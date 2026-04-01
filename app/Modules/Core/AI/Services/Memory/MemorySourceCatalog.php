<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Memory;

use App\Modules\Core\AI\DTO\MemorySourceEntry;
use App\Modules\Core\AI\Enums\MemoryFileType;

/**
 * Discovers memory source files for a given agent.
 *
 * Memory layout within an agent workspace:
 * - `MEMORY.md` — durable curated knowledge (MemoryFileType::Durable)
 * - `memory/*.md` — daily/raw notes (MemoryFileType::Daily)
 *
 * Does not scan recursively beyond `memory/` — subdirectories are excluded
 * to prevent runaway discovery. The catalog is agent-generic; it resolves
 * through the configured workspace path.
 */
class MemorySourceCatalog
{
    private const DURABLE_FILE = 'MEMORY.md';

    private const DAILY_DIR = 'memory';

    /**
     * Discover all memory sources for an agent.
     *
     * @param  int  $employeeId  Agent employee ID
     * @return list<MemorySourceEntry>
     */
    public function scan(int $employeeId): array
    {
        $workspacePath = $this->workspacePath($employeeId);

        if (! is_dir($workspacePath)) {
            return [];
        }

        $sources = [];

        $this->scanDurableFile($workspacePath, $sources);
        $this->scanDailyDirectory($workspacePath, $sources);

        return $sources;
    }

    /**
     * Check if a relative path is within this agent's memory scope.
     *
     * Validates that the path refers to MEMORY.md or memory/*.md —
     * prevents arbitrary workspace reads through the memory API.
     */
    public function isMemoryPath(string $relativePath): bool
    {
        if ($relativePath === self::DURABLE_FILE) {
            return true;
        }

        $prefix = self::DAILY_DIR.'/';

        if (! str_starts_with($relativePath, $prefix)) {
            return false;
        }

        $filename = substr($relativePath, strlen($prefix));

        // Only direct children — no subdirectory traversal
        if (str_contains($filename, '/') || str_contains($filename, '..')) {
            return false;
        }

        return str_ends_with($filename, '.md');
    }

    /**
     * Resolve an absolute path for a memory-scoped relative path.
     *
     * Returns null if the path is not a valid memory path or the file
     * does not exist.
     */
    public function resolveReadPath(int $employeeId, string $relativePath): ?string
    {
        if (! $this->isMemoryPath($relativePath)) {
            return null;
        }

        $absolute = $this->workspacePath($employeeId).'/'.$relativePath;

        if (! is_file($absolute)) {
            return null;
        }

        return $absolute;
    }

    /**
     * Classify a relative path by its file type.
     */
    public function classifyPath(string $relativePath): MemoryFileType
    {
        if ($relativePath === self::DURABLE_FILE) {
            return MemoryFileType::Durable;
        }

        return MemoryFileType::Daily;
    }

    /**
     * Get the workspace path for an agent.
     */
    private function workspacePath(int $employeeId): string
    {
        return rtrim((string) config('ai.workspace_path'), '/').'/'.$employeeId;
    }

    /**
     * Scan for the durable MEMORY.md file.
     *
     * @param  list<MemorySourceEntry>  &$sources
     */
    private function scanDurableFile(string $workspacePath, array &$sources): void
    {
        $path = $workspacePath.'/'.self::DURABLE_FILE;

        if (is_file($path)) {
            $sources[] = MemorySourceEntry::fromFile($path, self::DURABLE_FILE, MemoryFileType::Durable);
        }
    }

    /**
     * Scan the memory/ directory for daily markdown files.
     *
     * Only direct children — no recursive scanning.
     *
     * @param  list<MemorySourceEntry>  &$sources
     */
    private function scanDailyDirectory(string $workspacePath, array &$sources): void
    {
        $dir = $workspacePath.'/'.self::DAILY_DIR;

        if (! is_dir($dir)) {
            return;
        }

        $files = @scandir($dir) ?: [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (! str_ends_with($file, '.md')) {
                continue;
            }

            $absolute = $dir.'/'.$file;

            if (! is_file($absolute)) {
                continue;
            }

            $relative = self::DAILY_DIR.'/'.$file;
            $sources[] = MemorySourceEntry::fromFile($absolute, $relative, MemoryFileType::Daily);
        }
    }
}
