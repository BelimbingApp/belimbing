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
        $incubatingTables = $tables;
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
            $foreignKeysByTable = $this->foreignKeysByTable();
            $scope = $this->expandedRebuildScope($tables, $migrationFiles, $foreignKeysByTable);
            $tables = $scope['tables'];
            $cascaded = array_values(array_diff($tables, $incubatingTables));
            $migrationFiles = $scope['migration_files'];
            $migrationNames = array_values(array_unique(array_map(
                fn (string $file): string => (string) preg_replace('/\.php$/', '', $file),
                $migrationFiles,
            )));

            $this->dropTables($tables, $foreignKeysByTable);
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
     * @param  list<string>  $migrationFiles
     * @param  array<string, list<array<string, mixed>>>  $foreignKeysByTable
     * @return array{tables: list<string>, migration_files: list<string>}
     */
    private function expandedRebuildScope(array $tablesToDrop, array $migrationFiles, array $foreignKeysByTable): array
    {
        $tables = array_values(array_unique($tablesToDrop));
        $migrationFiles = array_values(array_unique($migrationFiles));
        $tableSet = array_fill_keys($tables, true);
        $fileSet = array_fill_keys($migrationFiles, true);

        do {
            $changed = false;

            foreach ($this->liveTablesForMigrationFiles(array_keys($fileSet)) as $table) {
                if (isset($tableSet[$table])) {
                    continue;
                }

                $tables[] = $table;
                $tableSet[$table] = true;
                $changed = true;
            }

            $dependents = $this->resolveDependentRebuilds(array_keys($tableSet), $foreignKeysByTable);

            foreach ($dependents as $table => $file) {
                if (! isset($tableSet[$table])) {
                    $tables[] = $table;
                    $tableSet[$table] = true;
                    $changed = true;
                }

                if (! isset($fileSet[$file])) {
                    $migrationFiles[] = $file;
                    $fileSet[$file] = true;
                    $changed = true;
                }
            }
        } while ($changed);

        return [
            'tables' => $tables,
            'migration_files' => $migrationFiles,
        ];
    }

    /**
     * Foreign keys for every live table, keyed by table name. Computed once per
     * rebuild-scope expansion so the fixpoint loop reuses cached metadata
     * instead of re-querying each table's foreign keys on every iteration.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function foreignKeysByTable(): array
    {
        $map = [];

        foreach ($this->liveTableNames() as $table) {
            $map[$table] = Schema::getForeignKeys($table);
        }

        return $map;
    }

    /**
     * @param  list<string>  $tablesToDrop
     * @param  array<string, list<array<string, mixed>>>  $foreignKeysByTable
     * @return array<string, string>
     */
    private function resolveDependentRebuilds(array $tablesToDrop, array $foreignKeysByTable): array
    {
        $dependents = $this->dependentClosure($tablesToDrop, $foreignKeysByTable);

        if ($dependents === []) {
            return [];
        }

        $rebuildable = $this->migrationFilesForTables($dependents);
        $unrebuildable = array_values(array_diff($dependents, array_keys($rebuildable)));

        if ($unrebuildable !== []) {
            throw IncubatingSchemaDependencyException::forStableDependents(
                $this->dependenciesInto($unrebuildable, array_merge($tablesToDrop, $dependents), $foreignKeysByTable),
            );
        }

        return $rebuildable;
    }

    /**
     * Transitive closure of live tables holding a foreign key into the seed
     * set, excluding the seed set itself.
     *
     * @param  list<string>  $seed
     * @param  array<string, list<array<string, mixed>>>  $foreignKeysByTable
     * @return list<string>
     */
    private function dependentClosure(array $seed, array $foreignKeysByTable): array
    {
        $inSet = array_fill_keys($seed, true);
        $added = [];

        do {
            $changed = false;

            foreach ($foreignKeysByTable as $table => $foreignKeys) {
                if (isset($inSet[$table])) {
                    continue;
                }

                foreach ($foreignKeys as $foreignKey) {
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
     * @param  array<string, list<array<string, mixed>>>  $foreignKeysByTable
     * @return list<array{table: string, column: string, foreign_table: string}>
     */
    private function dependenciesInto(array $dependents, array $foreignTables, array $foreignKeysByTable): array
    {
        $dependencies = [];

        foreach ($dependents as $table) {
            foreach ($foreignKeysByTable[$table] ?? [] as $foreignKey) {
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
     * A migration record is the coherent rerun unit. If one table from a
     * multi-table migration must be rebuilt, every live table owned by that
     * migration must be dropped too, otherwise the rerun would collide with
     * sibling tables left behind.
     *
     * @param  list<string>  $migrationFiles
     * @return list<string>
     */
    private function liveTablesForMigrationFiles(array $migrationFiles): array
    {
        if ($migrationFiles === [] || ! Schema::hasTable('base_database_tables')) {
            return [];
        }

        $live = array_fill_keys($this->liveTableNames(), true);

        return TableRegistry::query()
            ->whereIn('migration_file', array_values(array_unique($migrationFiles)))
            ->pluck('table_name')
            ->filter(fn (string $table): bool => isset($live[$table]))
            ->values()
            ->all();
    }

    /**
     * Drop tables in a driver-agnostic order.
     *
     * Tables are dropped dependents-first (a table only after every table
     * referencing it has already gone), which satisfies PostgreSQL/MySQL
     * foreign-key enforcement and SQLite's pragma without disabling checks.
     * Tables left in a foreign-key cycle (mutual references that admit no
     * ordering) are dropped together: a single comma-list DROP TABLE on
     * drivers that accept it (Postgres, MySQL), or per-table with SQLite's
     * foreign-key checks disabled/deferred.
     *
     * @param  list<string>  $tables
     * @param  array<string, list<array<string, mixed>>>  $foreignKeysByTable
     */
    private function dropTables(array $tables, array $foreignKeysByTable): void
    {
        [$ordered, $cyclic] = $this->topologicalDropOrder($tables, $foreignKeysByTable);

        foreach ($ordered as $table) {
            Schema::dropIfExists($table);
        }

        if ($cyclic === []) {
            return;
        }

        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            $deferForeignKeys = $connection->transactionLevel() > 0;

            if ($deferForeignKeys) {
                $connection->statement('PRAGMA defer_foreign_keys = ON');
            }

            Schema::disableForeignKeyConstraints();

            try {
                foreach ($cyclic as $table) {
                    Schema::dropIfExists($table);
                }
            } catch (\Throwable $exception) {
                Schema::enableForeignKeyConstraints();

                throw $exception;
            }

            Schema::enableForeignKeyConstraints();

            if ($deferForeignKeys) {
                $connection->statement('PRAGMA defer_foreign_keys = OFF');
            }

            return;
        }

        $grammar = $connection->getQueryGrammar();
        $wrapped = array_map(fn (string $table): string => $grammar->wrapTable($table), $cyclic);
        $connection->statement('DROP TABLE IF EXISTS '.implode(', ', $wrapped));
    }

    /**
     * Order $tables so each table appears only after every other table that
     * references it (dependents-first), which makes a plain per-table
     * DROP TABLE safe under foreign-key enforcement on every driver. Self
     * references are ignored. Returns the ordered prefix plus any tables that
     * remain because they sit in a foreign-key cycle.
     *
     * @param  list<string>  $tables
     * @param  array<string, list<array<string, mixed>>>  $foreignKeysByTable
     * @return array{0: list<string>, 1: list<string>}
     */
    private function topologicalDropOrder(array $tables, array $foreignKeysByTable): array
    {
        $tableSet = array_fill_keys($tables, true);
        $outbound = [];
        $inDegree = [];

        foreach ($tables as $table) {
            $outbound[$table] = [];
            $inDegree[$table] = 0;
        }

        foreach ($tables as $table) {
            foreach ($foreignKeysByTable[$table] ?? [] as $foreignKey) {
                $parent = $foreignKey['foreign_table'] ?? null;

                if (! is_string($parent) || $parent === $table || ! isset($tableSet[$parent])) {
                    continue;
                }

                // $table references $parent, so $table must be dropped first.
                $outbound[$table][] = $parent;
                $inDegree[$parent]++;
            }
        }

        $queue = array_values(array_filter($tables, fn (string $table): bool => $inDegree[$table] === 0));
        sort($queue);

        $ordered = [];

        while ($queue !== []) {
            $table = array_shift($queue);
            $ordered[] = $table;

            foreach ($outbound[$table] as $parent) {
                if (--$inDegree[$parent] === 0) {
                    $queue[] = $parent;
                    sort($queue);
                }
            }
        }

        $cyclic = array_values(array_filter(
            $tables,
            fn (string $table): bool => $inDegree[$table] > 0,
        ));

        return [$ordered, $cyclic];
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
