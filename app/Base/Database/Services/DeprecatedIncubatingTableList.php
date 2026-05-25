<?php

namespace App\Base\Database\Services;

use Illuminate\Support\Str;

final class DeprecatedIncubatingTableList
{
    public function path(): string
    {
        $path = env('BLB_DEPRECATED_UNSTABLE_TABLE_LIST', base_path('scripts/unstable-table-list.sh'));

        return is_string($path) && trim($path) !== ''
            ? $path
            : base_path('scripts/unstable-table-list.sh');
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * @return list<string>
     */
    public function patterns(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        if (preg_match('/BLB_DEPRECATED_UNSTABLE_TABLE_PATTERNS=\((.*?)^\)/ms', $contents, $matches) !== 1) {
            return [];
        }

        $patterns = [];

        foreach (preg_split('/\R/', $matches[1]) ?: [] as $line) {
            $pattern = trim($line);

            if ($pattern === '' || str_starts_with($pattern, '#')) {
                continue;
            }

            $pattern = trim($pattern, " \t\n\r\0\x0B'\"");

            if ($pattern !== '') {
                $patterns[] = $pattern;
            }
        }

        return array_values(array_unique($patterns));
    }

    public function firstMatchingPattern(string $tableName): ?string
    {
        foreach ($this->patterns() as $pattern) {
            if (Str::is($pattern, $tableName)) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $tableNames
     * @return array<string, string|null>
     */
    public function matchingPatternsForTables(array $tableNames): array
    {
        $matches = [];

        foreach ($tableNames as $tableName) {
            $matches[$tableName] = $this->firstMatchingPattern($tableName);
        }

        return $matches;
    }

    /**
     * @param  list<string>  $patterns
     * @return list<string>
     */
    public function addPatterns(array $patterns): array
    {
        $normalized = $this->normalizePatterns($patterns);

        if ($normalized === []) {
            return [];
        }

        $existing = $this->patterns();
        $updated = array_values(array_unique(array_merge($existing, $normalized)));
        sort($updated);

        if ($updated === $existing) {
            return [];
        }

        $this->writePatterns($updated);

        return array_values(array_diff($updated, $existing));
    }

    /**
     * @param  list<string>  $patterns
     * @return list<string>
     */
    public function removePatterns(array $patterns): array
    {
        $normalized = $this->normalizePatterns($patterns);

        if ($normalized === []) {
            return [];
        }

        $existing = $this->patterns();
        $updated = array_values(array_diff($existing, $normalized));

        if ($updated === $existing) {
            return [];
        }

        $this->writePatterns($updated);

        return array_values(array_intersect($existing, $normalized));
    }

    /**
     * @param  list<string>  $patterns
     * @return list<string>
     */
    private function normalizePatterns(array $patterns): array
    {
        $normalized = [];

        foreach ($patterns as $pattern) {
            if (! is_string($pattern)) {
                continue;
            }

            $pattern = trim($pattern);

            if ($pattern === '' || preg_match('/^[A-Za-z0-9_*?]+$/', $pattern) !== 1) {
                continue;
            }

            $normalized[] = $pattern;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  list<string>  $patterns
     */
    private function writePatterns(array $patterns): void
    {
        $path = $this->path();

        if (! is_file($path)) {
            throw new \RuntimeException('Deprecated incubating table list script not found: '.$path);
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException('Unable to read deprecated incubating table list script: '.$path);
        }

        $renderedPatterns = implode(PHP_EOL, array_map(
            fn (string $pattern): string => '  '.$pattern,
            $patterns,
        ));

        $replacement = "readonly BLB_DEPRECATED_UNSTABLE_TABLE_PATTERNS=(".PHP_EOL
            .$renderedPatterns.PHP_EOL
            .')';

        $updated = preg_replace(
            '/readonly BLB_DEPRECATED_UNSTABLE_TABLE_PATTERNS=\((.*?)^\)/ms',
            $replacement,
            $contents,
            1,
        );

        if (! is_string($updated) || $updated === $contents) {
            throw new \RuntimeException('Unable to update deprecated incubating table list script: '.$path);
        }

        file_put_contents($path, $updated);
    }
}
