<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane;

use App\Base\Support\File as BlbFile;
use App\Base\Support\Json as BlbJson;

class WireLogger
{
    private const PREVIEW_ENTRY_LIMIT = 100;

    /**
     * Hard cap for how many decoded preview rows are retained in memory while scanning the JSONL.
     * Larger windows load faster operator workflows but increase peak RAM (roughly proportional to this cap).
     */
    private const PREVIEW_ENTRY_LIMIT_MAX = 2000;

    private const PREVIEW_LINE_BYTES = 64 * 1024;

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
        $footprintBytes = $this->fileFootprintBytes($path);

        $offset = max(0, $offset);
        $limit = max(1, min(self::PREVIEW_ENTRY_LIMIT_MAX, $limit));

        if (! is_file($path)) {
            return $this->emptyPreview($footprintBytes, $offset, $limit);
        }

        $window = $this->readPreviewWindow($path, $offset, $limit);
        if ($window === null) {
            return $this->emptyPreview($footprintBytes, $offset, $limit);
        }

        return $this->previewResult(
            $window['entries'],
            footprintBytes: $footprintBytes,
            totalEntries: $window['total_entries'],
            effectiveOffset: $window['effective_offset'],
            limit: $limit,
        );
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

    public function footprintBytes(string $runId): int
    {
        return $this->fileFootprintBytes($this->path($runId));
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

    private function fileFootprintBytes(string $path): int
    {
        if (! is_file($path)) {
            return 0;
        }

        $size = @filesize($path);

        return $size === false ? 0 : (int) $size;
    }

    /**
     * @return array{
     *     entries: list<array<string, mixed>>,
     *     total_entries: int,
     *     effective_offset: int
     * }|null
     */
    private function readPreviewWindow(string $path, int $offset, int $limit): ?array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $entries = [];
        $lastEntries = [];
        $totalEntries = 0;
        $extendStreamBlock = false;
        $previewer = new WireLogEntryPreviewer;

        try {
            while (($line = $this->readPreviewLine($handle)) !== null) {
                if ($line['line'] === '') {
                    continue;
                }

                $totalEntries++;
                $previewEntry = $previewer->previewEntry($totalEntries, $line['line'], $line['truncated']);
                $this->acceptPreviewEntry(
                    $previewEntry,
                    $totalEntries,
                    $offset,
                    $limit,
                    $entries,
                    $lastEntries,
                    $extendStreamBlock,
                );
            }
        } finally {
            fclose($handle);
        }

        if ($totalEntries > 0 && $offset >= $totalEntries) {
            return [
                'entries' => $lastEntries,
                'total_entries' => $totalEntries,
                'effective_offset' => max(0, $totalEntries - $limit),
            ];
        }

        return [
            'entries' => $entries,
            'total_entries' => $totalEntries,
            'effective_offset' => $offset,
        ];
    }

    /**
     * @param  array<string, mixed>  $previewEntry
     * @param  list<array<string, mixed>>  $entries
     * @param  list<array<string, mixed>>  $lastEntries
     */
    private function acceptPreviewEntry(
        array $previewEntry,
        int $totalEntries,
        int $offset,
        int $limit,
        array &$entries,
        array &$lastEntries,
        bool &$extendStreamBlock,
    ): void {
        $lastEntries[] = $previewEntry;

        if (count($lastEntries) > $limit) {
            array_shift($lastEntries);
        }

        if ($totalEntries <= $offset) {
            return;
        }

        if (count($entries) < $limit) {
            $entries[] = $previewEntry;
            $extendStreamBlock = count($entries) >= $limit && $this->isStreamLinePreviewEntry($previewEntry);

            return;
        }

        if (! $extendStreamBlock) {
            return;
        }

        if (! $this->isStreamLinePreviewEntry($previewEntry)) {
            $extendStreamBlock = false;

            return;
        }

        $entries[] = $previewEntry;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isStreamLinePreviewEntry(array $entry): bool
    {
        return ($entry['type'] ?? null) === 'llm.stream_line';
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function previewResult(array $entries, int $footprintBytes, int $totalEntries, int $effectiveOffset, int $limit): array
    {
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
