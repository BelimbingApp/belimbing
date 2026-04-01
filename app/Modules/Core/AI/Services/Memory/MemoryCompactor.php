<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Memory;

/**
 * Compacts daily memory notes into durable knowledge.
 *
 * Compaction reads raw daily memory files (memory/*.md), extracts
 * durable facts, and appends them to MEMORY.md. Processed daily
 * files are archived (renamed with prefix) rather than deleted.
 *
 * Triggers a reindex after successful compaction.
 *
 * Key invariant: compaction writes to canonical markdown, not only
 * to an index. Humans can always inspect and override the result.
 */
class MemoryCompactor
{
    private const DURABLE_FILE = 'MEMORY.md';

    private const DAILY_DIR = 'memory';

    public function __construct(
        private readonly MemorySourceCatalog $catalog,
        private readonly MemoryIndexer $indexer,
    ) {}

    /**
     * Compact daily notes for an agent.
     *
     * Extracts content from unarchived daily files, appends to MEMORY.md,
     * archives the daily files, and triggers reindex.
     *
     * @return array{compacted_files: int, archived_files: int, appended_bytes: int}
     */
    public function compact(int $employeeId): array
    {
        $workspacePath = $this->workspacePath($employeeId);
        $dailyDir = $workspacePath.'/'.self::DAILY_DIR;

        if (! is_dir($dailyDir)) {
            return ['compacted_files' => 0, 'archived_files' => 0, 'appended_bytes' => 0];
        }

        $dailyFiles = $this->findUnarchivedDailyFiles($dailyDir);

        if ($dailyFiles === []) {
            return ['compacted_files' => 0, 'archived_files' => 0, 'appended_bytes' => 0];
        }

        $extracted = $this->extractDurableContent($dailyFiles);

        if ($extracted === '') {
            // Archive files even if no durable content — they were reviewed
            $archived = $this->archiveFiles($dailyFiles);

            return ['compacted_files' => count($dailyFiles), 'archived_files' => $archived, 'appended_bytes' => 0];
        }

        $appended = $this->appendToDurable($workspacePath, $extracted);
        $archived = $this->archiveFiles($dailyFiles);

        // Reindex first (creates schema if needed), then record compaction timestamp
        $this->indexer->reindex($employeeId);

        $store = MemoryIndexStore::forAgent($employeeId);
        $store->ensureSchema();
        $store->setMeta('last_compacted_at', (string) time());

        return [
            'compacted_files' => count($dailyFiles),
            'archived_files' => $archived,
            'appended_bytes' => $appended,
        ];
    }

    /**
     * Find daily files that haven't been archived yet.
     *
     * @return list<string> Absolute paths to unarchived daily files
     */
    private function findUnarchivedDailyFiles(string $dailyDir): array
    {
        $archivePrefix = (string) config('ai.memory.compaction_archive_prefix', 'archived-');
        $files = @scandir($dailyDir) ?: [];
        $result = [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (! str_ends_with($file, '.md')) {
                continue;
            }

            // Skip already archived files
            if (str_starts_with($file, $archivePrefix)) {
                continue;
            }

            $absolute = $dailyDir.'/'.$file;

            if (is_file($absolute)) {
                $result[] = $absolute;
            }
        }

        sort($result);

        return $result;
    }

    /**
     * Extract content from daily files suitable for durable memory.
     *
     * Concatenates all daily file contents with date headers.
     * Future: LLM-assisted summarization to extract key facts.
     *
     * @param  list<string>  $files  Absolute paths
     */
    private function extractDurableContent(array $files): string
    {
        $parts = [];

        foreach ($files as $file) {
            $content = @file_get_contents($file);

            if ($content === false || trim($content) === '') {
                continue;
            }

            $basename = pathinfo($file, PATHINFO_FILENAME);
            $parts[] = '### Compacted from '.$basename."\n\n".trim($content);
        }

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * Append extracted content to MEMORY.md.
     *
     * Creates the file if it doesn't exist.
     *
     * @return int Bytes appended
     */
    private function appendToDurable(string $workspacePath, string $content): int
    {
        $durablePath = $workspacePath.'/'.self::DURABLE_FILE;

        $separator = "\n\n---\n\n";
        $entry = $separator.'## Compacted on '.date('Y-m-d H:i:s')."\n\n".$content."\n";

        if (! is_file($durablePath)) {
            $entry = "# Agent Memory\n\nDurable knowledge compacted from daily notes.\n".$entry;
        }

        $bytes = strlen($entry);
        file_put_contents($durablePath, $entry, FILE_APPEND | LOCK_EX);

        return $bytes;
    }

    /**
     * Archive daily files by renaming with archive prefix.
     *
     * @param  list<string>  $files  Absolute paths
     * @return int Number of files archived
     */
    private function archiveFiles(array $files): int
    {
        $archivePrefix = (string) config('ai.memory.compaction_archive_prefix', 'archived-');
        $archived = 0;

        foreach ($files as $file) {
            $dir = dirname($file);
            $basename = basename($file);
            $archivedPath = $dir.'/'.$archivePrefix.$basename;

            if (@rename($file, $archivedPath)) {
                $archived++;
            }
        }

        return $archived;
    }

    private function workspacePath(int $employeeId): string
    {
        return rtrim((string) config('ai.workspace_path'), '/').'/'.$employeeId;
    }
}
