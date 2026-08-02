<?php

namespace App\Base\Database\Services;

use App\Base\Database\Contracts\IncubatingSchemaInspector;
use App\Base\Database\Exceptions\IncubatingSchemaConflictException;
use App\Base\Database\Exceptions\IncubatingSchemaDependencyException;
use App\Base\Database\Models\TableRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class IncubatingSchemaPreflight implements IncubatingSchemaInspector
{
    public function __construct(
        private readonly IncubatingMigrationFiles $migrationFiles,
        private readonly IncubatingSchemaRegistry $registry,
        private readonly IncubatingSchemaTableClassifier $tableClassifier,
        private readonly IncubatingSchemaTableDropper $tableDropper,
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

        $this->registry->assertLiveTableOwnership($incubating);

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
            $foreignKeysByTable = $this->tableDropper->foreignKeysByTable($this->registry->liveTableNames());
            $scope = $this->expandedRebuildScope(
                $tables,
                $migrationFiles,
                $foreignKeysByTable,
                $this->appliedMigrationSources($migrationPaths),
            );
            $tables = $scope['tables'];
            $cascaded = array_values(array_diff($tables, $incubatingTables));
            $migrationFiles = $scope['migration_files'];
            $migrationNames = array_values(array_unique(array_map(
                fn (string $file): string => (string) preg_replace('/\.php$/', '', $file),
                $migrationFiles,
            )));

            $this->tableDropper->drop($tables, $foreignKeysByTable);
        }

        $deletedMigrations = DB::table('migrations')
            ->whereIn('migration', $migrationNames)
            ->pluck('migration')
            ->all();

        if ($deletedMigrations !== []) {
            DB::table('migrations')->whereIn('migration', $deletedMigrations)->delete();
        }

        $seedersReset = $this->registry->resetSeedersForFiles($migrationFiles);

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
            $this->registry->tablesForMigrationFile($migrationFile),
            $this->parsedCreatedTables($contents),
        )));
    }

    /**
     * @return list<string>
     */
    private function parsedCreatedTables(string $contents): array
    {
        return $this->migrationFiles->createdTables($contents);
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

        return array_values(array_intersect($declared, $this->registry->liveTableNames()));
    }

    /**
     * Tables that must be dropped and rebuilt alongside the incubating set
     * because they hold foreign keys (directly or transitively) into it.
     *
     * The rebuild may cascade only through migrations that are also explicitly
     * incubating in source. Stable or undeclared dependents stop the preflight
     * before any table is dropped. Treating a registry entry as permission to
     * rebuild would erase stable data and would rerun only the owning create
     * migration, not later forward migrations that matured the table.
     *
     * @param  list<string>  $tablesToDrop
     * @param  list<string>  $migrationFiles
     * @param  array<string, list<array<string, mixed>>>  $foreignKeysByTable
     * @param  list<array{file: string, migration_name: string, contents: string}>  $appliedMigrationSources
     * @return array{tables: list<string>, migration_files: list<string>}
     */
    private function expandedRebuildScope(
        array $tablesToDrop,
        array $migrationFiles,
        array $foreignKeysByTable,
        array $appliedMigrationSources,
    ): array {
        $tables = array_values(array_unique($tablesToDrop));
        $migrationFiles = array_values(array_unique($migrationFiles));
        $tableSet = array_fill_keys($tables, true);
        $fileSet = array_fill_keys($migrationFiles, true);

        do {
            $changed = $this->addSiblingTables($tables, $tableSet, $fileSet);

            $dependents = $this->resolveDependentRebuilds(array_keys($tableSet), $foreignKeysByTable);
            $changed = $this->addDependentTables($dependents, $tables, $tableSet, $migrationFiles, $fileSet) || $changed;

            $forwardFiles = $this->resolveAppliedForwardMigrations(
                array_keys($tableSet),
                array_keys($fileSet),
                $appliedMigrationSources,
            );
            $changed = $this->addMigrationFiles($forwardFiles, $migrationFiles, $fileSet) || $changed;
        } while ($changed);

        return [
            'tables' => $tables,
            'migration_files' => $migrationFiles,
        ];
    }

    /**
     * @param  list<string>  $tables
     * @param  array<string, true>  $tableSet
     * @param  array<string, true>  $fileSet
     */
    private function addSiblingTables(array &$tables, array &$tableSet, array $fileSet): bool
    {
        $changed = false;

        foreach ($this->registry->liveTablesForMigrationFiles(array_keys($fileSet)) as $table) {
            if (! isset($tableSet[$table])) {
                $tables[] = $table;
                $tableSet[$table] = true;
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * @param  array<string, string>  $dependents
     * @param  list<string>  $tables
     * @param  array<string, true>  $tableSet
     * @param  list<string>  $migrationFiles
     * @param  array<string, true>  $fileSet
     */
    private function addDependentTables(array $dependents, array &$tables, array &$tableSet, array &$migrationFiles, array &$fileSet): bool
    {
        $changed = false;

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

        return $changed;
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $migrationFiles
     * @param  array<string, true>  $fileSet
     */
    private function addMigrationFiles(array $files, array &$migrationFiles, array &$fileSet): bool
    {
        $changed = false;

        foreach ($files as $file) {
            if (! isset($fileSet[$file])) {
                $migrationFiles[] = $file;
                $fileSet[$file] = true;
                $changed = true;
            }
        }

        return $changed;
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

        $rebuildable = $this->registry->migrationFilesForTables($dependents);
        $unrebuildable = array_values(array_diff($dependents, array_keys($rebuildable)));
        $nonIncubating = array_keys(array_filter(
            $rebuildable,
            fn (string $file): bool => ! $this->migrationFiles->fileIsIncubating($file),
        ));
        $blocked = array_values(array_unique(array_merge($unrebuildable, $nonIncubating)));

        if ($blocked !== []) {
            throw IncubatingSchemaDependencyException::forNonIncubatingDependents(
                $this->dependenciesInto($blocked, array_merge($tablesToDrop, $dependents), $foreignKeysByTable),
            );
        }

        return $rebuildable;
    }

    /**
     * Include later applied migration files only when they are explicitly
     * incubating. A stable forward migration may have added columns, indexes,
     * constraints, or transformed data; leaving its ledger row applied while
     * replaying the original create migration produces an obsolete schema.
     *
     * @param  list<string>  $tablesToDrop
     * @param  list<string>  $migrationFiles
     * @param  list<array{file: string, migration_name: string, contents: string}>  $appliedMigrationSources
     * @return list<string>
     */
    private function resolveAppliedForwardMigrations(
        array $tablesToDrop,
        array $migrationFiles,
        array $appliedMigrationSources,
    ): array {
        $migrationNames = array_map(
            fn (string $file): string => (string) preg_replace('/\.php$/', '', $file),
            $migrationFiles,
        );
        $earliestRebuild = min($migrationNames);
        $fileSet = array_fill_keys($migrationFiles, true);
        $incubating = [];
        $conflicts = [];

        foreach ($appliedMigrationSources as $source) {
            if (isset($fileSet[$source['file']]) || $source['migration_name'] <= $earliestRebuild) {
                continue;
            }

            $referencedTables = $this->migrationFiles->referencedTables($source['contents'], $tablesToDrop);

            if ($referencedTables === []) {
                continue;
            }

            if ($this->migrationFiles->contentsAreIncubating($source['contents'])) {
                $incubating[$source['file']] = true;

                continue;
            }

            foreach ($referencedTables as $table) {
                $conflicts[] = [
                    'table' => $table,
                    'migration' => $source['file'],
                ];
            }
        }

        if ($conflicts !== []) {
            throw IncubatingSchemaConflictException::forAppliedForwardMigrations($conflicts);
        }

        return array_keys($incubating);
    }

    /**
     * @param  list<string>  $migrationPaths
     * @return list<array{file: string, migration_name: string, contents: string}>
     */
    private function appliedMigrationSources(array $migrationPaths): array
    {
        $applied = DB::table('migrations')->pluck('migration')->flip()->all();
        $sources = [];
        $paths = array_values(array_unique(array_merge(
            $this->migrationFiles->discoveredPaths(),
            $this->migrationFiles->paths($migrationPaths),
        )));
        sort($paths);

        foreach ($paths as $path) {
            $migrationName = pathinfo($path, PATHINFO_FILENAME);

            if (! isset($applied[$migrationName])) {
                continue;
            }

            $contents = file_get_contents($path);

            if ($contents === false) {
                throw IncubatingSchemaConflictException::forUnreadableAppliedMigration($path);
            }

            $sources[] = [
                'file' => basename($path),
                'migration_name' => $migrationName,
                'contents' => $contents,
            ];
        }

        return $sources;
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

    private function relativeBasePath(string $absolutePath): string
    {
        return str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $absolutePath);
    }
}
