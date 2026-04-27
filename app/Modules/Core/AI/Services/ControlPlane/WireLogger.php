<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane;

use App\Base\Support\File as BlbFile;
use App\Base\Support\Json as BlbJson;
use Illuminate\Support\Str;

class WireLogger
{
    private const PREVIEW_ENTRY_LIMIT = 100;

    private const PREVIEW_ENTRY_LIMIT_MAX = 250;

    private const PREVIEW_LINE_BYTES = 64 * 1024;

    private const PREVIEW_PAYLOAD_BYTES = 24 * 1024;

    private const RAW_ENTRY_CHUNK_BYTES = 8 * 1024;

    public function enabled(): bool
    {
        return (bool) config('ai.wire_logging.enabled', false);
    }

    public function retentionDays(): int
    {
        return max(1, (int) config('ai.wire_logging.retention_days', 7));
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public function append(string $runId, array $entry): void
    {
        if (! $this->enabled()) {
            return;
        }

        $path = $this->path($runId);
        BlbFile::ensureDirectory(dirname($path));

        BlbFile::put(
            $path,
            json_encode(array_merge([
                'at' => now()->toIso8601String(),
            ], $entry), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function read(string $runId): array
    {
        $path = $this->path($runId);

        if (! file_exists($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        $entries = [];

        foreach ($lines as $line) {
            $decoded = BlbJson::decodeArray($line);

            if ($decoded !== null) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    /**
     * @return array{
     *     entries: list<array{
     *         entry_number: int,
     *         at: string|null,
     *         type: string|null,
     *         summary_preview: string,
     *         payload_pretty: string,
     *         payload_truncated: bool,
     *         preview_status: string,
     *         raw_line: string,
     *         decoded_payload: array<string, mixed>|null
     *     }>,
     *     footprint_bytes: int,
     *     total_entries: int,
     *     visible_entries: int,
     *     offset: int,
     *     limit: int,
     *     range_start: int,
     *     range_end: int,
     *     omitted_before: int,
     *     omitted_after: int,
     *     has_previous: bool,
     *     has_next: bool,
     *     last_offset: int
     * }
     */
    public function preview(string $runId, int $offset = 0, int $limit = self::PREVIEW_ENTRY_LIMIT): array
    {
        $path = $this->path($runId);
        $footprintBytes = 0;

        if (is_file($path)) {
            $size = @filesize($path);
            $footprintBytes = $size === false ? 0 : (int) $size;
        }

        $offset = max(0, $offset);
        $limit = max(1, min(self::PREVIEW_ENTRY_LIMIT_MAX, $limit));

        if (! is_file($path)) {
            return $this->emptyPreview($footprintBytes, $offset, $limit);
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return $this->emptyPreview($footprintBytes, $offset, $limit);
        }

        $entries = [];
        $lastEntries = [];
        $totalEntries = 0;

        try {
            while (($line = $this->readPreviewLine($handle)) !== null) {
                if ($line['line'] === '') {
                    continue;
                }

                $totalEntries++;
                $previewEntry = $this->previewEntry($totalEntries, $line['line'], $line['truncated']);

                $lastEntries[] = $previewEntry;

                if (count($lastEntries) > $limit) {
                    array_shift($lastEntries);
                }

                if ($totalEntries <= $offset || count($entries) >= $limit) {
                    continue;
                }

                $entries[] = $previewEntry;
            }
        } finally {
            fclose($handle);
        }

        $effectiveOffset = $offset;

        if ($totalEntries > 0 && $effectiveOffset >= $totalEntries) {
            $effectiveOffset = max(0, $totalEntries - $limit);
            $entries = $lastEntries;
        }

        $visibleEntries = count($entries);
        $rangeStart = $visibleEntries > 0 ? $effectiveOffset + 1 : 0;
        $rangeEnd = $visibleEntries > 0 ? $effectiveOffset + $visibleEntries : 0;

        return [
            'entries' => $entries,
            'footprint_bytes' => $footprintBytes,
            'total_entries' => $totalEntries,
            'visible_entries' => $visibleEntries,
            'offset' => $effectiveOffset,
            'limit' => $limit,
            'range_start' => $rangeStart,
            'range_end' => $rangeEnd,
            'omitted_before' => $rangeStart > 0 ? $rangeStart - 1 : 0,
            'omitted_after' => max(0, $totalEntries - $rangeEnd),
            'has_previous' => $effectiveOffset > 0,
            'has_next' => $rangeEnd < $totalEntries,
            'last_offset' => max(0, $totalEntries - $limit),
        ];
    }

    public function streamRawEntry(string $runId, int $entryNumber, ?callable $write = null): bool
    {
        $found = false;
        $path = $this->path($runId);

        if ($entryNumber < 1 || ! is_file($path)) {
            return $found;
        }

        $handle = @fopen($path, 'rb');
        $currentEntry = 0;

        try {
            if ($handle === false) {
                return $found;
            }

            while (($lineHasContent = $this->streamLineChunks(
                $handle,
                $currentEntry + 1 === $entryNumber ? $write : null,
            )) !== null) {
                if (! $lineHasContent) {
                    continue;
                }

                $currentEntry++;

                if ($currentEntry === $entryNumber) {
                    $found = true;
                    break;
                }
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return $found;
    }

    public function hasEntry(string $runId, int $entryNumber): bool
    {
        return $this->streamRawEntry($runId, $entryNumber);
    }

    public function path(string $runId): string
    {
        return storage_path('app/ai/wire-logs/'.$runId.'.jsonl');
    }

    public function totalBytes(): int
    {
        $wireLogPath = storage_path('app/ai/wire-logs');

        if (! is_dir($wireLogPath)) {
            return 0;
        }

        $total = 0;

        foreach (glob($wireLogPath.'/*.jsonl') ?: [] as $path) {
            $size = @filesize($path);

            if ($size !== false) {
                $total += $size;
            }
        }

        return $total;
    }

    public function pruneOlderThan(int $days): int
    {
        $cutoff = now()->subDays(max(1, $days))->getTimestamp();
        $wireLogPath = storage_path('app/ai/wire-logs');
        $deleted = 0;

        foreach (glob($wireLogPath.'/*.jsonl') ?: [] as $path) {
            $modifiedAt = @filemtime($path);

            if ($modifiedAt === false || $modifiedAt >= $cutoff) {
                continue;
            }

            if (@unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @param  resource  $handle
     * @return array{line: string, truncated: bool}|null
     */
    private function readPreviewLine($handle): ?array
    {
        $line = fgets($handle, self::PREVIEW_LINE_BYTES + 1);

        if ($line === false) {
            return null;
        }

        $truncated = ! str_contains($line, "\n") && ! feof($handle);

        if ($truncated) {
            $next = fgetc($handle);

            if ($next === "\n" || $next === false) {
                $truncated = false;
            } else {
                while ($next !== false && $next !== "\n") {
                    $next = fgetc($handle);
                }
            }
        }

        return [
            'line' => rtrim($line, "\r\n"),
            'truncated' => $truncated,
        ];
    }

    /**
     * @return array{
     *     entry_number: int,
     *     at: string|null,
     *     type: string|null,
     *     summary_preview: string,
     *     payload_pretty: string,
     *     payload_truncated: bool,
     *     preview_status: string,
     *     raw_line: string,
     *     decoded_payload: array<string, mixed>|null
     * }
     */
    private function previewEntry(int $entryNumber, string $line, bool $lineTruncated): array
    {
        $at = $this->extractScalar($line, 'at');
        $type = $this->extractScalar($line, 'type');
        $payload = $this->previewPayload($line, $lineTruncated, $at, $type);

        return [
            'entry_number' => $entryNumber,
            'at' => $payload['at'],
            'type' => $payload['type'],
            'summary_preview' => $payload['summary_preview'],
            'payload_pretty' => $payload['payload_pretty'],
            'payload_truncated' => $payload['payload_truncated'],
            'preview_status' => $payload['preview_status'],
            'raw_line' => $line,
            'decoded_payload' => $payload['decoded_payload'],
        ];
    }

    /**
     * @return array{
     *     at: string|null,
     *     type: string|null,
     *     summary_preview: string,
     *     payload_pretty: string,
     *     payload_truncated: bool,
     *     preview_status: string,
     *     decoded_payload: array<string, mixed>|null
     * }
     */
    private function previewPayload(string $line, bool $lineTruncated, ?string $fallbackAt, ?string $fallbackType): array
    {
        $payloadPretty = __('Payload preview omitted because this wire-log entry exceeds :size.', [
            'size' => number_format(self::PREVIEW_LINE_BYTES / 1024).' KB',
        ]);
        $payloadTruncated = $lineTruncated;
        $previewStatus = 'line_omitted';
        $at = $fallbackAt;
        $type = $fallbackType;
        $summaryPreview = $this->summaryPreviewFromRawLine($line);
        $decodedPayload = null;

        if (! $lineTruncated) {
            $decoded = BlbJson::decodeArray($line);
            $decodedPayload = $decoded;

            if ($decoded === null) {
                $payloadPretty = __('Payload preview unavailable because this wire-log entry could not be decoded.');
                $payloadTruncated = true;
                $previewStatus = 'decode_error';
            } else {
                $at = is_string($decoded['at'] ?? null) ? $decoded['at'] : $at;
                $type = is_string($decoded['type'] ?? null) ? $decoded['type'] : $type;

                unset($decoded['at'], $decoded['type']);
                $summaryPreview = $this->summaryPreviewFromPayload($type, $decoded);

                $encoded = json_encode(
                    $decoded,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                );

                if (! is_string($encoded)) {
                    $payloadPretty = __('Payload preview unavailable because this wire-log entry could not be encoded.');
                    $payloadTruncated = true;
                    $previewStatus = 'encode_error';
                } else {
                    $payloadPretty = $encoded;
                    $payloadTruncated = strlen($payloadPretty) > self::PREVIEW_PAYLOAD_BYTES;
                    $previewStatus = $payloadTruncated ? 'payload_truncated' : 'full';

                    if ($payloadTruncated) {
                        $payloadPretty = substr($payloadPretty, 0, self::PREVIEW_PAYLOAD_BYTES)."\n…";
                    }
                }
            }
        }

        return [
            'at' => $at,
            'type' => $type,
            'summary_preview' => $summaryPreview,
            'payload_pretty' => $payloadPretty,
            'payload_truncated' => $payloadTruncated,
            'preview_status' => $previewStatus,
            'decoded_payload' => $decodedPayload,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function summaryPreviewFromPayload(?string $type, array $payload): string
    {
        if ($type === 'llm.stream_line') {
            return $this->streamLineSummaryPreview($payload);
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded) || $encoded === '') {
            return '{}';
        }

        return Str::limit($encoded, 120, '...');
    }

    private function summaryPreviewFromRawLine(string $line): string
    {
        $summary = preg_replace('/^{"at":"[^"]*","type":"[^"]*",?/', '{', $line);
        $summary = is_string($summary) ? $summary : $line;

        return Str::limit($summary, 120, '...');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function streamLineSummaryPreview(array $payload): string
    {
        $rawLine = is_string($payload['raw_line'] ?? null) ? $payload['raw_line'] : '';

        if ($rawLine === '') {
            return '[]';
        }

        if ($rawLine === 'data: [DONE]') {
            return 'finish_reason: [DONE]';
        }

        if (str_starts_with($rawLine, 'data: ')) {
            $data = BlbJson::decodeArray(substr($rawLine, 6));

            if (is_array($data)) {
                $summary = $this->streamChunkSemanticSummary($data);

                if ($summary !== null) {
                    return $summary;
                }
            }
        }

        return Str::limit($rawLine, 120, '...');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function streamChunkSemanticSummary(array $data): ?string
    {
        $choice = $data['choices'][0] ?? null;

        if (! is_array($choice)) {
            return null;
        }

        $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
        $finishReason = $choice['finish_reason'] ?? null;

        if (is_string($delta['reasoning_content'] ?? null) && $delta['reasoning_content'] !== '') {
            return 'reasoning_content: '.$this->quotedPreview($delta['reasoning_content']);
        }

        if (is_string($delta['content'] ?? null) && $delta['content'] !== '') {
            return 'content: '.$this->quotedPreview($delta['content']);
        }

        $toolCallDeltas = $delta['tool_calls'] ?? null;

        if (is_array($toolCallDeltas) && isset($toolCallDeltas[0]) && is_array($toolCallDeltas[0])) {
            $toolCall = $toolCallDeltas[0];
            $toolName = $toolCall['function']['name'] ?? null;
            $argumentsDelta = $toolCall['function']['arguments'] ?? null;

            if (is_string($toolName) && $toolName !== '') {
                return 'tool_call: '.$toolName;
            }

            if (is_string($argumentsDelta) && $argumentsDelta !== '') {
                return 'tool_args: '.$this->quotedPreview($argumentsDelta);
            }
        }

        if (is_string($finishReason) && $finishReason !== '') {
            return 'finish_reason: '.$finishReason;
        }

        return null;
    }

    private function quotedPreview(string $value): string
    {
        return '"'.Str::limit($value, 72, '...').'"';
    }

    /**
     * @param  resource  $handle
     * @param  (callable(string): void)|null  $write
     */
    private function streamLineChunks($handle, ?callable $write = null): ?bool
    {
        $sawContent = false;

        while (($chunk = fgets($handle, self::RAW_ENTRY_CHUNK_BYTES)) !== false) {
            $endsWithNewline = str_ends_with($chunk, "\n");
            $segment = $endsWithNewline ? rtrim($chunk, "\r\n") : $chunk;

            if ($segment !== '') {
                $sawContent = true;

                if ($write !== null) {
                    $write($segment);
                }
            }

            if ($endsWithNewline) {
                return $sawContent;
            }
        }

        return $sawContent ? true : null;
    }

    private function extractScalar(string $line, string $key): ?string
    {
        if (! preg_match('/"'.preg_quote($key, '/').'":"((?:[^"\\\\]|\\\\.)*)"/', $line, $matches)) {
            return null;
        }

        $decoded = json_decode('"'.$matches[1].'"');

        return is_string($decoded) ? $decoded : null;
    }

    /**
     * @return array{
     *     entries: list<array{
     *         at: string|null,
     *         type: string|null,
     *         payload_pretty: string,
     *         payload_truncated: bool
     *     }>,
     *     footprint_bytes: int,
     *     total_entries: int,
     *     visible_entries: int,
     *     offset: int,
     *     limit: int,
     *     range_start: int,
     *     range_end: int,
     *     omitted_before: int,
     *     omitted_after: int,
     *     has_previous: bool,
     *     has_next: bool,
     *     last_offset: int
     * }
     */
    private function emptyPreview(int $footprintBytes = 0, int $offset = 0, int $limit = self::PREVIEW_ENTRY_LIMIT): array
    {
        return [
            'entries' => [],
            'footprint_bytes' => $footprintBytes,
            'total_entries' => 0,
            'visible_entries' => 0,
            'offset' => max(0, $offset),
            'limit' => max(1, min(self::PREVIEW_ENTRY_LIMIT_MAX, $limit)),
            'range_start' => 0,
            'range_end' => 0,
            'omitted_before' => 0,
            'omitted_after' => 0,
            'has_previous' => false,
            'has_next' => false,
            'last_offset' => 0,
        ];
    }
}
