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
        private readonly IncubatingMigrationFiles $migrationFiles,
        private readonly IncubatingSchemaTableClassifier $tableClassifier,
    ) {}

    /**
     * Determine whether the source migration for a registered table is incubating.
     */
    public function tableIsIncubating(string $tableName): bool
    {
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
     * @return array<string, array{state: string, source_declared: bool}>
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
     * @return array{files: list<string>, tables: list<string>, cascaded: list<string>, migrations: list<string>, seeders_reset: int}
     */
    public function run(array $migrationPaths): array
    {
        if (! Schema::hasTable('migrations')) {
            return ['files' => [], 'tables' => [], 'cascaded' => [], 'migrations' => [], 'seeders_reset' => 0];
        }

        $incubating = $this->incubatingMigrations($migrationPaths);

        if ($incubating === []) {
            return ['files' => [], 'tables' => [], 'cascaded' => [], 'migrations' => [], 'seeders_reset' => 0];
        }

        $tables = $this->liveTablesDeclaredBy($incubating);
        $migrationFiles = array_values(array_unique(array_map(
            fn (array $migration): string => $migration['file'],
            $incubating,
        )));
        $migrationNames = array_values(array_unique(array_map(
            fn (array $migration): string => $migration['migration_name'],
            $incubating,
        )));

        $cascaded = [];

        if ($tables !== []) {
            $cascaded = $this->resolveDependentRebuilds($tables);

            if ($cascaded !== []) {
                $tables = array_values(array_unique(array_merge($tables, $cascaded)));

                foreach ($this->migrationFilesForTables($cascaded) as $file) {
                    $migrationFiles[] = $file;
                    $migrationNames[] = preg_replace('/\.php$/', '', $file);
                }

                $migrationFiles = array_values(array_unique($migrationFiles));
                $migrationNames = array_values(array_unique($migrationNames));
            }

            $this->dropTables($tables);
        }

        $deletedMigrations = DB::table('migrations')
            ->whereIn('migration', $migrationNames)
            ->pluck('migration')
            ->all();

        if ($deletedMigrations !== []) {
            DB::table('migrations')->whereIn('migration', $deletedMigrations)->delete();
        }

        $seedersReset = $this->resetSeedersForFiles($migrationFiles);

        return [
            'files' => array_map(fn (array $migration): string => $migration['relative_path'], $incubating),
            'tables' => $tables,
            'cascaded' => $cascaded,
            'migrations' => array_values($deletedMigrations),
            'seeders_reset' => $seedersReset,
        ];
    }

    /**
     * @param  list<string>  $migrationPaths
     * @return list<array{path: string, relative_path: string, file: string, migration_name: string, tables: list<string>}>
     */
    public function incubatingMigrations(array $migrationPaths): array
    {
        $migrations = [];

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
     * Tables that must be dropped and rebuilt alongside the incubating set
     * because they hold foreign keys (directly or transitively) into it.
     *
     * Rather than refusing to rebuild when a stable sibling depends on an
     * incubating table, we cascade the rebuild: the dependent table is dropped
     * and its migration record cleared so the subsequent `migrate` re-creates
     * it. A dependent can only be rebuilt this way if its owning migration is
     * known via the registry; if any dependent is not re-creatable, we fall
     * back to the original hard error so data is never silently lost.
     *
     * @param  list<string>  $tablesToDrop
     * @return list<string>
     */
    private function resolveDependentRebuilds(array $tablesToDrop): array
    {
        $dependents = $this->dependentClosure($tablesToDrop);

        if ($dependents === []) {
            return [];
        }

        $rebuildable = $this->migrationFilesForTables($dependents);
        $unrebuildable = array_values(array_diff($dependents, array_keys($rebuildable)));

        if ($unrebuildable !== []) {
            throw IncubatingSchemaDependencyException::forStableDependents(
                $this->dependenciesInto($unrebuildable, array_merge($tablesToDrop, $dependents)),
            );
        }

        return $dependents;
    }

    /**
     * Transitive closure of live tables holding a foreign key into the seed
     * set, excluding the seed set itself.
     *
     * @param  list<string>  $seed
     * @return list<string>
     */
    private function dependentClosure(array $seed): array
    {
        $live = $this->liveTableNames();
        $inSet = array_fill_keys($seed, true);
        $added = [];

        do {
            $changed = false;

            foreach ($live as $table) {
                if (isset($inSet[$table])) {
                    continue;
                }

                foreach (Schema::getForeignKeys($table) as $foreignKey) {
                    if (! isset($inSet[$foreignKey['foreign_table']])) {
                        continue;
                    }

                    $inSet[$table] = true;
                    $added[$table] = true;
                    $changed = true;

                    break;
                }
            }
        } while ($changed);

        return array_keys($added);
    }

    /**
     * Foreign-key dependencies from $dependents into $foreignTables, for error
     * reporting when a dependent cannot be rebuilt automatically.
     *
     * @param  list<string>  $dependents
     * @param  list<string>  $foreignTables
     * @return list<array{table: string, column: string, foreign_table: string}>
     */
    private function dependenciesInto(array $dependents, array $foreignTables): array
    {
        $dependencies = [];

        foreach ($dependents as $table) {
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if (! in_array($foreignKey['foreign_table'], $foreignTables, true)) {
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

        return $dependencies;
    }

    /**
     * Map live tables to their owning migration file via the registry, keeping
     * only those with a known, non-empty migration file.
     *
     * @param  list<string>  $tables
     * @return array<string, string>
     */
    private function migrationFilesForTables(array $tables): array
    {
        if ($tables === [] || ! Schema::hasTable('base_database_tables')) {
            return [];
        }

        return TableRegistry::query()
            ->whereIn('table_name', $tables)
            ->whereNotNull('migration_file')
            ->where('migration_file', '!=', '')
            ->pluck('migration_file', 'table_name')
            ->all();
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
     * @param  list<string>  $migrationFiles
     */
    private function resetSeedersForFiles(array $migrationFiles): int
    {
        if (! Schema::hasTable('base_database_seeders')) {
            return 0;
        }

        $migrationFiles = array_values(array_unique($migrationFiles));

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
}
