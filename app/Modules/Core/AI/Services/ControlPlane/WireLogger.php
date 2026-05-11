<?php

namespace App\Modules\Core\AI\Services\ControlPlane;

use App\Base\Support\File as BlbFile;

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

    /**
     * Hard ceiling for how many additional consecutive `llm.stream_line` rows
     * the preview window will absorb past `$limit` to keep a stream block
     * intact. Once exceeded, trailing deltas collapse into a single placeholder.
     */
    private const STREAM_BLOCK_EXTENSION_CAP = 200;

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

        $state = [
            'entries' => [],
            'last_entries' => [],
            'total_entries' => 0,
            'offset' => $offset,
            'limit' => $limit,
            'extend_stream_block' => false,
            'extension_count' => 0,
            'collapsed_count' => 0,
            'collapsed_from_entry' => 0,
            'collapsed_to_entry' => 0,
        ];
        $previewer = new WireLogEntryPreviewer;

        try {
            while (($line = $this->readPreviewLine($handle)) !== null) {
                if ($line['line'] === '') {
                    continue;
                }

                $state['total_entries']++;
                $previewEntry = $previewer->previewEntry($state['total_entries'], $line['line'], $line['truncated']);
                $this->acceptPreviewEntry($previewEntry, $state);
            }
        } finally {
            fclose($handle);
        }

        if ($state['collapsed_count'] > 0) {
            $state['entries'][] = $this->collapsedStreamPlaceholder(
                $state['collapsed_from_entry'],
                $state['collapsed_to_entry'],
                $state['collapsed_count'],
            );
        }

        if ($state['total_entries'] > 0 && $state['offset'] >= $state['total_entries']) {
            return [
                'entries' => $state['last_entries'],
                'total_entries' => $state['total_entries'],
                'effective_offset' => max(0, $state['total_entries'] - $state['limit']),
            ];
        }

        return [
            'entries' => $state['entries'],
            'total_entries' => $state['total_entries'],
            'effective_offset' => $state['offset'],
        ];
    }

    /**
     * @param  array<string, mixed>  $previewEntry
     * @param  array{
     *     entries: list<array<string, mixed>>,
     *     last_entries: list<array<string, mixed>>,
     *     total_entries: int,
     *     offset: int,
     *     limit: int,
     *     extend_stream_block: bool,
     *     extension_count: int,
     *     collapsed_count: int,
     *     collapsed_from_entry: int,
     *     collapsed_to_entry: int
     * }  $state
     */
    private function acceptPreviewEntry(
        array $previewEntry,
        array &$state,
    ): void {
        $state['last_entries'][] = $previewEntry;

        if (count($state['last_entries']) > $state['limit']) {
            array_shift($state['last_entries']);
        }

        if ($state['total_entries'] <= $state['offset']) {
            return;
        }

        if (count($state['entries']) < $state['limit']) {
            $state['entries'][] = $previewEntry;
            $state['extend_stream_block'] = count($state['entries']) >= $state['limit'] && $this->isStreamLinePreviewEntry($previewEntry);
            $state['extension_count'] = 0;

            return;
        }

        if (! $state['extend_stream_block']) {
            return;
        }

        if (! $this->isStreamLinePreviewEntry($previewEntry)) {
            $state['extend_stream_block'] = false;

            if ($state['collapsed_count'] > 0) {
                $state['entries'][] = $this->collapsedStreamPlaceholder(
                    $state['collapsed_from_entry'],
                    $state['collapsed_to_entry'],
                    $state['collapsed_count'],
                );
                $state['collapsed_count'] = 0;
                $state['collapsed_from_entry'] = 0;
                $state['collapsed_to_entry'] = 0;
            }

            return;
        }

        if ($state['extension_count'] < self::STREAM_BLOCK_EXTENSION_CAP) {
            $state['entries'][] = $previewEntry;
            $state['extension_count']++;

            return;
        }

        if ($state['collapsed_count'] === 0) {
            $state['collapsed_from_entry'] = $state['total_entries'];
        }

        $state['collapsed_to_entry'] = $state['total_entries'];
        $state['collapsed_count']++;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isStreamLinePreviewEntry(array $entry): bool
    {
        return ($entry['type'] ?? null) === 'llm.stream_line';
    }

    /**
     * @return array<string, mixed>
     */
    private function collapsedStreamPlaceholder(int $fromEntry, int $toEntry, int $count): array
    {
        $summary = __(
            'Collapsed :count consecutive stream deltas (#:from – #:to). The full sequence is preserved on disk; raise the page size or jump to the entry to inspect them individually.',
            ['count' => $count, 'from' => $fromEntry, 'to' => $toEntry],
        );

        return [
            'entry_number' => $fromEntry,
            'from_entry_number' => $fromEntry,
            'to_entry_number' => $toEntry,
            'count' => $count,
            'at' => null,
            'type' => 'stream_lines_collapsed',
            'summary_preview' => $summary,
            'payload_pretty' => $summary,
            'payload_truncated' => false,
            'preview_status' => 'collapsed',
            'raw_line' => '',
            'decoded_payload' => null,
        ];
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
