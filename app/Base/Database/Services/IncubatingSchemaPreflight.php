<?php

namespace App\Base\Database\Services;

use App\Base\Database\Contracts\IncubatingSchemaInspector;
use App\Base\Database\Exceptions\IncubatingSchemaDependencyException;
use App\Base\Database\Models\SeederRegistry;
use App\Base\Database\Models\TableRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class IncubatingSchemaPreflight implements IncubatingSchemaInspector
{
    /**
     * Determine whether the source migration for a registered table is incubating.
     */
    public function tableIsIncubating(string $tableName): bool
    {
        if ($this->tableMatchesDeprecatedScriptPattern($tableName)) {
            return true;
        }

        if (! Schema::hasTable('base_database_tables')) {
            return false;
        }

        $migrationFile = TableRegistry::query()
            ->where('table_name', $tableName)
            ->value('migration_file');

        if (! is_string($migrationFile) || $migrationFile === '') {
            return false;
        }

        $path = $this->migrationPathByFileName($migrationFile);

        if ($path === null) {
            return false;
        }

        $contents = file_get_contents($path);

        return $contents !== false && $this->isIncubating($contents);
    }

    public function tableSchemaState(string $tableName): string
    {
        return $this->schemaStatesForTables([$tableName])[$tableName] ?? 'unknown';
    }

    /**
     * @param  list<string>  $tableNames
     * @return array<string, string>
     */
    public function schemaStatesForTables(array $tableNames): array
    {
        if ($tableNames === []) {
            return [];
        }

        $rows = TableRegistry::query()
            ->whereIn('table_name', $tableNames)
            ->get(['table_name', 'migration_file']);

        $incubatingFiles = $rows
            ->pluck('migration_file')
            ->filter(fn (mixed $file): bool => is_string($file) && $file !== '')
            ->unique()
            ->reduce(function (array $files, string $migrationFile): array {
                $path = $this->migrationPathByFileName($migrationFile);

                if ($path === null) {
                    return $files;
                }

                $contents = file_get_contents($path);

                if ($contents !== false && $this->isIncubating($contents)) {
                    $files[$migrationFile] = true;
                }

                return $files;
            }, []);

        $deprecatedTables = $this->deprecatedScriptTables();
        $rowsByTable = $rows->keyBy('table_name');

        return collect($tableNames)
            ->mapWithKeys(function (string $tableName) use ($rowsByTable, $incubatingFiles, $deprecatedTables): array {
                if (in_array($tableName, TableRegistry::INFRASTRUCTURE_TABLES, true)) {
                    return [$tableName => 'infrastructure'];
                }

                $migrationFile = $rowsByTable->get($tableName)?->migration_file;

                if (! is_string($migrationFile) || $migrationFile === '') {
                    return [$tableName => 'unknown'];
                }

                $state = isset($incubatingFiles[$migrationFile]) || in_array($tableName, $deprecatedTables, true)
                    ? 'incubating'
                    : 'stable';

                return [$tableName => $state];
            })
            ->all();
    }

    /**
     * @param  list<string>  $migrationPaths
     * @return array{files: list<string>, tables: list<string>, migrations: list<string>, seeders_reset: int, deprecated_script_tables: list<string>}
     */
    public function run(array $migrationPaths): array
    {
        if (! Schema::hasTable('migrations')) {
            return ['files' => [], 'tables' => [], 'migrations' => [], 'seeders_reset' => 0, 'deprecated_script_tables' => []];
        }

        $incubating = $this->incubatingMigrations($migrationPaths);

        if ($incubating === []) {
            return ['files' => [], 'tables' => [], 'migrations' => [], 'seeders_reset' => 0, 'deprecated_script_tables' => []];
        }

        $tables = $this->liveTablesDeclaredBy($incubating);
        $migrationNames = array_values(array_unique(array_map(
            fn (array $migration): string => $migration['migration_name'],
            $incubating,
        )));

        if ($tables !== []) {
            $this->guardNoStableDependents($tables);
            $this->dropTables($tables);
        }

        $deletedMigrations = DB::table('migrations')
            ->whereIn('migration', $migrationNames)
            ->pluck('migration')
            ->all();

        if ($deletedMigrations !== []) {
            DB::table('migrations')->whereIn('migration', $deletedMigrations)->delete();
        }

        $seedersReset = $this->resetSeedersFor($incubating);

        return [
            'files' => array_map(fn (array $migration): string => $migration['relative_path'], $incubating),
            'tables' => $tables,
            'migrations' => array_values($deletedMigrations),
            'seeders_reset' => $seedersReset,
            'deprecated_script_tables' => $this->deprecatedScriptTables(),
        ];
    }

    /**
     * @param  list<string>  $migrationPaths
     * @return list<array{path: string, relative_path: string, file: string, migration_name: string, tables: list<string>}>
     */
    public function incubatingMigrations(array $migrationPaths): array
    {
        $migrations = [];
        $seenFiles = [];

        foreach ($this->migrationFiles($migrationPaths) as $path) {
            $contents = file_get_contents($path);

            if ($contents === false || ! $this->isIncubating($contents)) {
                continue;
            }

            $migration = [
                'path' => $path,
                'relative_path' => $this->relativeBasePath($path),
                'file' => basename($path),
                'migration_name' => pathinfo($path, PATHINFO_FILENAME),
                'tables' => $this->createdTables($contents),
            ];

            $migrations[] = $migration;
            $seenFiles[$migration['file']] = true;
        }

        foreach ($this->deprecatedScriptMigrationFiles() as $migrationFile) {
            if (isset($seenFiles[$migrationFile])) {
                continue;
            }

            $path = $this->migrationPathByFileName($migrationFile);

            if ($path === null) {
                continue;
            }

            $contents = file_get_contents($path);

            if ($contents === false) {
                continue;
            }

            $migrations[] = [
                'path' => $path,
                'relative_path' => $this->relativeBasePath($path),
                'file' => basename($path),
                'migration_name' => pathinfo($path, PATHINFO_FILENAME),
                'tables' => $this->createdTables($contents),
            ];
        }

        return $migrations;
    }

    /**
     * @param  list<string>  $migrationPaths
     * @return list<string>
     */
    private function migrationFiles(array $migrationPaths): array
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

    private function migrationPathByFileName(string $migrationFile): ?string
    {
        $paths = [];

        foreach ($this->defaultDiscoveryPathPatterns() as $pattern) {
            $paths = array_merge($paths, glob($pattern) ?: []);
        }

        foreach ($this->migrationFiles($paths) as $path) {
            if (basename($path) === $migrationFile) {
                return $path;
            }
        }

        return null;
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

    private function isIncubating(string $contents): bool
    {
        if (preg_match('/\bBLB_SCHEMA_STABLE\s*=\s*false\s*;/i', $contents) === 1) {
            return true;
        }

        return preg_match('/\buse\s+IncubatingSchema\s*;/i', $contents) === 1;
    }

    /**
     * @return list<string>
     */
    private function createdTables(string $contents): array
    {
        if (preg_match_all('/Schema::create\(\s*[\'"]([\w]+)[\'"]/', $contents, $matches) === false) {
            return [];
        }

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * @param  list<array{tables: list<string>}>  $migrations
     * @return list<string>
     */
    private function liveTablesDeclaredBy(array $migrations): array
    {
        $declared = [];

        foreach ($migrations as $migration) {
            $declared = array_merge($declared, $migration['tables']);
        }

        $declared = array_values(array_unique($declared));

        return array_values(array_intersect($declared, $this->liveTableNames()));
    }

    /**
     * @return list<string>
     */
    private function liveTableNames(): array
    {
        return array_map(
            fn (array $table): string => $table['name'],
            Schema::getTables(),
        );
    }

    /**
     * @param  list<string>  $tablesToDrop
     */
    private function guardNoStableDependents(array $tablesToDrop): void
    {
        $dependencies = [];

        foreach ($this->liveTableNames() as $table) {
            if (in_array($table, $tablesToDrop, true)) {
                continue;
            }

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if (! in_array($foreignKey['foreign_table'], $tablesToDrop, true)) {
                    continue;
                }

                foreach ($foreignKey['columns'] as $column) {
                    $dependencies[] = [
                        'table' => $table,
                        'column' => $column,
                        'foreign_table' => $foreignKey['foreign_table'],
                    ];
                }
            }
        }

        if ($dependencies !== []) {
            throw IncubatingSchemaDependencyException::forStableDependents($dependencies);
        }
    }

    /**
     * @param  list<string>  $tables
     */
    private function dropTables(array $tables): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $grammar = $connection->getQueryGrammar();
            $wrapped = array_map(fn (string $table): string => $grammar->wrapTable($table), $tables);
            $connection->statement('DROP TABLE IF EXISTS '.implode(', ', $wrapped));

            return;
        }

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($tables as $table) {
                Schema::dropIfExists($table);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * @param  list<array{file: string}>  $migrations
     */
    private function resetSeedersFor(array $migrations): int
    {
        if (! Schema::hasTable('base_database_seeders')) {
            return 0;
        }

        $migrationFiles = array_values(array_unique(array_map(
            fn (array $migration): string => $migration['file'],
            $migrations,
        )));

        if ($migrationFiles === []) {
            return 0;
        }

        return SeederRegistry::query()
            ->whereIn('migration_file', $migrationFiles)
            ->update([
                'status' => SeederRegistry::STATUS_PENDING,
                'ran_at' => null,
                'error_message' => null,
            ]);
    }

    private function relativeBasePath(string $absolutePath): string
    {
        return str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $absolutePath);
    }

    /**
     * @return list<string>
     */
    private function deprecatedScriptTables(): array
    {
        $patterns = $this->deprecatedScriptPatterns();

        if ($patterns === []) {
            return [];
        }

        return TableRegistry::query()
            ->pluck('table_name')
            ->filter(function (string $tableName) use ($patterns): bool {
                foreach ($patterns as $pattern) {
                    if (Str::is($pattern, $tableName)) {
                        return true;
                    }
                }

                return false;
            })
            ->values()
            ->all();
    }

    private function tableMatchesDeprecatedScriptPattern(string $tableName): bool
    {
        foreach ($this->deprecatedScriptPatterns() as $pattern) {
            if (Str::is($pattern, $tableName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function deprecatedScriptMigrationFiles(): array
    {
        return TableRegistry::query()
            ->whereIn('table_name', $this->deprecatedScriptTables())
            ->whereNotNull('migration_file')
            ->pluck('migration_file')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function deprecatedScriptPatterns(): array
    {
        $path = env('BLB_DEPRECATED_UNSTABLE_TABLE_LIST', base_path('scripts/unstable-table-list.sh'));

        if (! is_string($path) || trim($path) === '' || ! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        if (preg_match('/BLB_DEPRECATED_UNSTABLE_TABLE_PATTERNS=\((.*?)\)/s', $contents, $matches) !== 1) {
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
}
