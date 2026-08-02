<?php

namespace App\Base\Database\Services;

final class IncubatingMigrationFiles
{
    /**
     * @param  list<string>  $migrationPaths
     * @return list<string>
     */
    public function paths(array $migrationPaths): array
    {
        $files = [];

        foreach ($migrationPaths as $path) {
            if (is_file($path)) {
                $files[] = $path;

                continue;
            }

            if (is_dir($path)) {
                $files = array_merge($files, glob(rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.php') ?: []);
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    public function pathByFileName(string $migrationFile): ?string
    {
        foreach ($this->discoveredPaths() as $path) {
            if (basename($path) === $migrationFile) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function discoveredPaths(): array
    {
        $paths = [];

        foreach ($this->defaultDiscoveryPathPatterns() as $pattern) {
            $paths = array_merge($paths, glob($pattern) ?: []);
        }

        return $this->paths($paths);
    }

    public function fileIsIncubating(string $migrationFile): bool
    {
        $path = $this->pathByFileName($migrationFile);

        if ($path === null) {
            return false;
        }

        $contents = file_get_contents($path);

        return $contents !== false && $this->contentsAreIncubating($contents);
    }

    public function contentsAreIncubating(string $contents): bool
    {
        $tokens = token_get_all($contents);
        $braceDepth = 0;
        $classDepths = [];
        $awaitingClassBody = false;

        foreach ($tokens as $index => $token) {
            if (is_array($token)) {
                if ($token[0] === T_CLASS) {
                    $awaitingClassBody = true;

                    continue;
                }

                if ($this->tokenUsesIncubatingSchema($token, $classDepths, $tokens, $index)) {
                    return true;
                }

                continue;
            }

            if ($token !== '}') {
                $this->trackOpeningBrace($token, $braceDepth, $classDepths, $awaitingClassBody);

                continue;
            }

            $this->trackClosingBrace($braceDepth, $classDepths);
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function createdTables(string $contents): array
    {
        $tokens = token_get_all($contents);
        $tables = [];

        foreach ($tokens as $index => $token) {
            if (! $this->tokenNames($token, 'Schema')) {
                continue;
            }

            $doubleColon = $this->nextSignificantTokenIndex($tokens, $index + 1);
            $create = $doubleColon === null
                ? null
                : $this->nextSignificantTokenIndex($tokens, $doubleColon + 1);
            $openingParenthesis = $create === null
                ? null
                : $this->nextSignificantTokenIndex($tokens, $create + 1);
            $tableName = $openingParenthesis === null
                ? null
                : $this->nextSignificantTokenIndex($tokens, $openingParenthesis + 1);

            if ($doubleColon === null
                || ! is_array($tokens[$doubleColon])
                || $tokens[$doubleColon][0] !== T_DOUBLE_COLON
                || $create === null
                || ! $this->tokenNames($tokens[$create], 'create')
                || $openingParenthesis === null
                || $tokens[$openingParenthesis] !== '('
                || $tableName === null
                || ! is_array($tokens[$tableName])
                || $tokens[$tableName][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $literal = $this->literalString($tokens[$tableName][1]);

            if ($literal !== null && preg_match('/^[A-Za-z_]\w*$/D', $literal) === 1) {
                $tables[] = $literal;
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Conservatively find live table names mentioned by a migration. This
     * includes schema calls, constants, data migrations, and raw SQL strings;
     * a destructive replay must treat any later applied mention as a possible
     * forward dependency unless that migration is incubating too.
     *
     * @param  list<string>  $tableNames
     * @return list<string>
     */
    public function referencedTables(string $contents, array $tableNames): array
    {
        if ($tableNames === []) {
            return [];
        }

        $references = [];

        foreach (token_get_all($contents) as $token) {
            if (! is_array($token)
                || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }

            $literal = $token[0] === T_CONSTANT_ENCAPSED_STRING
                ? $this->literalString($token[1])
                : $token[1];

            if ($literal === null) {
                continue;
            }

            foreach ($tableNames as $tableName) {
                if (preg_match('/(?<![A-Za-z0-9_])'.preg_quote($tableName, '/').'(?![A-Za-z0-9_])/i', $literal) === 1) {
                    $references[$tableName] = true;
                }
            }
        }

        return array_keys($references);
    }

    /**
     * @param  array{0: int, 1: string, 2?: int}  $token
     * @param  list<int>  $classDepths
     * @param  list<array{0: int, 1: string, 2?: int}|string>  $tokens
     */
    private function tokenUsesIncubatingSchema(array $token, array $classDepths, array $tokens, int $index): bool
    {
        return $token[0] === T_USE
            && $classDepths !== []
            && $this->traitUseIncludesIncubatingSchema($tokens, $index);
    }

    /**
     * @param  list<int>  $classDepths
     */
    private function trackOpeningBrace(string $token, int &$braceDepth, array &$classDepths, bool &$awaitingClassBody): void
    {
        if ($token !== '{') {
            return;
        }

        $braceDepth++;

        if ($awaitingClassBody) {
            $classDepths[] = $braceDepth;
            $awaitingClassBody = false;
        }
    }

    /**
     * @param  list<int>  $classDepths
     */
    private function trackClosingBrace(int &$braceDepth, array &$classDepths): void
    {
        if ($classDepths !== [] && end($classDepths) === $braceDepth) {
            array_pop($classDepths);
        }

        $braceDepth--;
    }

    /**
     * @param  list<array{0: int, 1: string, 2?: int}|string>  $tokens
     */
    private function traitUseIncludesIncubatingSchema(array $tokens, int $useIndex): bool
    {
        for ($index = $useIndex + 1, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if ($this->tokenIsIgnorable($token)) {
                continue;
            }

            if ($token === '(') {
                return false;
            }

            if ($token === ';' || $token === '{') {
                return false;
            }

            if ($this->tokenNames($token, 'IncubatingSchema')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{0: int, 1: string, 2?: int}|string>  $tokens
     */
    private function nextSignificantTokenIndex(array $tokens, int $start): ?int
    {
        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            if (! $this->tokenIsIgnorable($tokens[$index])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array{0: int, 1: string, 2?: int}|string  $token
     */
    private function tokenIsIgnorable(array|string $token): bool
    {
        return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * @param  array{0: int, 1: string, 2?: int}|string  $token
     */
    private function tokenNames(array|string $token, string $name): bool
    {
        if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }

        $segments = explode('\\', ltrim($token[1], '\\'));

        return strcasecmp((string) end($segments), $name) === 0;
    }

    private function literalString(string $token): ?string
    {
        if (strlen($token) < 2 || ! in_array($token[0], ['\'', '"'], true) || substr($token, -1) !== $token[0]) {
            return null;
        }

        return stripcslashes(substr($token, 1, -1));
    }

    /**
     * @return list<string>
     */
    private function defaultDiscoveryPathPatterns(): array
    {
        return [
            app_path('Base/*/Database/Migrations'),
            app_path('Modules/*/*/Database/Migrations'),
            database_path('migrations'),
            base_path('extensions/*/*/Database/Migrations'),
        ];
    }
}
