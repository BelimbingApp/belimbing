<?php

namespace App\Base\Database\Services;

use App\Base\Database\Contracts\IncubatingSchemaInspector;
use App\Base\Database\Exceptions\IncubatingSchemaDependencyException;
use App\Base\Database\Models\SeederRegistry;
use App\Base\Database\Models\TableRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class IncubatingSchemaPreflight implements IncubatingSchemaInspector
{
    public function __construct(
        private readonly DeprecatedIncubatingTableList $deprecatedList,
        private readonly IncubatingMigrationFiles $migrationFiles,
        private readonly IncubatingSchemaTableClassifier $tableClassifier,
    ) {}

    /**
     * Determine whether the source migration for a registered table is incubating.
     */
    public function tableIsIncubating(string $tableName): bool
    {
        if ($this->deprecatedList->firstMatchingPattern($tableName) !== null) {
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

        return $this->migrationFiles->fileIsIncubating($migrationFile);
    }

    public function tableSchemaState(string $tableName): string
    {
        return $this->schemaStatesForTables([$tableName])[$tableName] ?? 'unknown';
    }

    /**
     * @param  list<string>  $tableNames
     * @return array<string, array{state: string, source_declared: bool, deprecated_pattern: string|null}>
     */
    public function schemaDetailsForTables(array $tableNames): array
    {
        return $this->tableClassifier->detailsForTables($tableNames);
    }

    /**
     * @param  list<string>  $tableNames
     * @return array<string, string>
     */
    public function schemaStatesForTables(array $tableNames): array
    {
        return $this->tableClassifier->statesForTables($tableNames);
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
            'deprecated_script_tables' => $this->tableClassifier->deprecatedTables(),
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

        foreach ($this->migrationFiles->paths($migrationPaths) as $path) {
            $contents = file_get_contents($path);

            if ($contents === false || ! $this->migrationFiles->contentsAreIncubating($contents)) {
                continue;
            }

            $file = basename($path);
            $migration = [
                'path' => $path,
                'relative_path' => $this->relativeBasePath($path),
                'file' => $file,
                'migration_name' => pathinfo($path, PATHINFO_FILENAME),
                'tables' => $this->declaredTables($file, $contents),
            ];

            $migrations[] = $migration;
            $seenFiles[$migration['file']] = true;
        }

        foreach ($this->deprecatedScriptMigrationFiles() as $migrationFile) {
            if (isset($seenFiles[$migrationFile])) {
                continue;
            }

            $path = $this->migrationFiles->pathByFileName($migrationFile);

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
                'tables' => $this->declaredTables(basename($path), $contents),
            ];
        }

        return $migrations;
    }

    /**
     * Tables owned by a migration, merged from the authoritative registry
     * (populated by RegistersTables::registerTable()) and the source-level
     * regex. The registry covers migrations that build tables via helper
     * traits or dynamic calls; the regex covers migrations that haven't
     * been run yet so their registry rows don't exist.
     *
     * @return list<string>
     */
    private function declaredTables(string $migrationFile, string $contents): array
    {
        return array_values(array_unique(array_merge(
            $this->registeredTablesForMigrationFile($migrationFile),
            $this->parsedCreatedTables($contents),
        )));
    }

    /**
     * @return list<string>
     */
    private function registeredTablesForMigrationFile(string $migrationFile): array
    {
        if (! Schema::hasTable('base_database_tables')) {
            return [];
        }

        return TableRegistry::query()
            ->where('migration_file', $migrationFile)
            ->pluck('table_name')
            ->all();
    }

    /**
     * @return list<string>
     */
    private function parsedCreatedTables(string $contents): array
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

    private function deprecatedScriptMigrationFiles(): array
    {
        return TableRegistry::query()
            ->whereIn('table_name', $this->tableClassifier->deprecatedTables())
            ->whereNotNull('migration_file')
            ->pluck('migration_file')
            ->unique()
            ->values()
            ->all();
    }
}
