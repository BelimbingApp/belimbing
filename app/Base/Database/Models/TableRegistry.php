<?php

namespace App\Base\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Table Registry Model
 *
 * Tracks database tables registered by migrations.
 *
 * @property int $id
 * @property string $table_name Physical database table name
 * @property string|null $module_name Module name (e.g., 'AI')
 * @property string|null $module_path Module path (e.g., 'app/Modules/Core/AI')
 * @property string|null $migration_file Migration file that created this table
 */
class TableRegistry extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'base_database_tables';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'table_name',
        'module_name',
        'module_path',
        'migration_file',
    ];

    /**
     * Infrastructure tables that are always preserved during selective drops.
     * These tables are never wiped, even if not explicitly marked stable.
     */
    const INFRASTRUCTURE_TABLES = [
        'base_database_tables',
        'base_database_seeders',
        'migrations',
    ];

    /**
     * Register a table in the registry.
     *
     * @param  string  $tableName  Physical database table name
     * @param  string|null  $moduleName  Module name (e.g., 'AI')
     * @param  string|null  $modulePath  Module path (e.g., 'app/Modules/Core/AI')
     * @param  string|null  $migrationFile  Migration file that created this table
     */
    public static function register(
        string $tableName,
        ?string $moduleName,
        ?string $modulePath,
        ?string $migrationFile = null
    ): void {
        if (self::query()->where('table_name', $tableName)->exists()) {
            self::query()->where('table_name', $tableName)->update([
                'module_name' => $moduleName,
                'module_path' => $modulePath,
                'migration_file' => $migrationFile,
            ]);

            return;
        }

        self::query()->create([
            'table_name' => $tableName,
            'module_name' => $moduleName,
            'module_path' => $modulePath,
            'migration_file' => $migrationFile,
        ]);
    }

    /**
     * Unregister a table from the registry.
     *
     * @param  string  $tableName  Physical database table name
     */
    public static function unregister(string $tableName): void
    {
        self::query()->where('table_name', $tableName)->delete();
    }

    /**
     * Auto-discover tables from migration files and register them.
     *
     * Scans migration files under app/Base and app/Modules for Schema::create()
     * calls, extracts table names, and registers any not already in the registry.
     */
    public static function ensureDiscoveredRegistered(): void
    {
        self::reconcile();
    }

    /**
     * Reconcile the registry against declared migrations and live relations.
     *
     * Declared tables from migration files are always (re)registered. Registry
     * rows are pruned only when they are neither declared by a migration nor
     * present as a live database relation (table or view).
     *
     * @return array{removed: list<string>}
     */
    public static function reconcile(): array
    {
        if (! Schema::hasTable('base_database_tables')) {
            return ['removed' => []];
        }

        $declaredTables = self::discoverDeclaredTables();

        foreach ($declaredTables as $tableName => $metadata) {
            self::register(
                $tableName,
                $metadata['module_name'],
                $metadata['module_path'],
                $metadata['migration_file'],
            );
        }

        self::ensureInfrastructureRegistered();

        return ['removed' => self::pruneOrphanedEntries(array_keys($declaredTables))];
    }

    /**
     * Determine whether a live database relation exists for the given name.
     */
    public static function relationExists(string $tableName): bool
    {
        return in_array($tableName, self::getExistingRelationNames(), true);
    }

    /**
     * Remove a registry row when it no longer maps to a declared or live relation.
     */
    public static function removeIfOrphaned(string $tableName): bool
    {
        if (in_array($tableName, self::INFRASTRUCTURE_TABLES, true)
            || self::relationExists($tableName)
            || array_key_exists($tableName, self::discoverDeclaredTables())
        ) {
            return false;
        }

        return self::query()->where('table_name', $tableName)->delete() > 0;
    }

    /**
     * Get registered relation names that currently exist in the database.
     *
     * @return list<string>
     */
    public static function getAvailableTableNames(): array
    {
        if (! Schema::hasTable('base_database_tables')) {
            return [];
        }

        return array_values(array_intersect(
            self::query()->pluck('table_name')->all(),
            self::getExistingRelationNames(),
        ));
    }

    /**
     * Ensure infrastructure tables are registered in the registry.
     *
     * These tables (e.g., `migrations`) are created by Laravel internals,
     * not by migration files, so auto-discovery from file scanning misses them.
     */
    private static function ensureInfrastructureRegistered(): void
    {
        foreach (self::INFRASTRUCTURE_TABLES as $tableName) {
            if (self::query()->where('table_name', $tableName)->exists()) {
                continue;
            }

            self::register($tableName, 'Database', 'app/Base/Database');
        }
    }

    /**
     * Discover declared tables from migration files.
     *
     * @return array<string, array{module_name: string|null, module_path: string|null, migration_file: string}>
     */
    private static function discoverDeclaredTables(): array
    {
        $patterns = [
            app_path('Base/*/Database/Migrations/*.php'),
            app_path('Modules/*/*/Database/Migrations/*.php'),
            database_path('migrations/*.php'),
            base_path('extensions/*/*/Database/Migrations/*.php'),
        ];

        $files = [];
        foreach ($patterns as $pattern) {
            $files = array_merge($files, glob($pattern) ?: []);
        }

        $declaredTables = [];
        foreach ($files as $file) {
            foreach (self::discoverTablesFromFile($file) as $tableName => $metadata) {
                $declaredTables[$tableName] = $metadata;
            }
        }

        return $declaredTables;
    }

    /**
     * Parse a migration file for Schema::create() calls and return found tables.
     *
     * @param  string  $file  Absolute path to a migration PHP file
     * @return array<string, array{module_name: string|null, module_path: string|null, migration_file: string}>
     */
    private static function discoverTablesFromFile(string $file): array
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            return [];
        }

        // Match Schema::create('table_name', ...) plus the migration-helper
        // convention ->create*Table('table_name', ...) — helpers must take
        // the table name as a string literal to stay discoverable.
        if (! preg_match_all('/(?:Schema::create|->create\w*Table)\(\s*[\'"]([\w]+)[\'"]/', $contents, $matches)) {
            return [];
        }

        $rel = str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $file);
        $migrationFile = basename($file);

        // Derive module provenance from file path
        $modulePath = null;
        $moduleName = null;

        if (preg_match('#app/Modules/[^/]+/[^/]+#', $rel, $pathMatch)
            || preg_match('#app/Base/[^/]+#', $rel, $pathMatch)
            || preg_match('#extensions/[^/]+/[^/]+#', $rel, $pathMatch)
        ) {
            $modulePath = $pathMatch[0];
            $moduleName = basename($modulePath);
        }

        $declaredTables = [];

        foreach ($matches[1] as $tableName) {
            $declaredTables[$tableName] = [
                'module_name' => $moduleName,
                'module_path' => $modulePath,
                'migration_file' => $migrationFile,
            ];
        }

        return $declaredTables;
    }

    /**
     * Remove registry rows that no longer map to any declared or live relation.
     *
     * @param  list<string>  $declaredTableNames
     * @return list<string>
     */
    private static function pruneOrphanedEntries(array $declaredTableNames): array
    {
        $protectedNames = array_values(array_unique(array_merge(
            self::INFRASTRUCTURE_TABLES,
            $declaredTableNames,
            self::getExistingRelationNames(),
        )));

        $query = self::query();

        if ($protectedNames !== []) {
            $query->whereNotIn('table_name', $protectedNames);
        }

        $removed = $query->pluck('table_name')->all();

        if ($removed !== []) {
            self::query()->whereIn('table_name', $removed)->delete();
        }

        return $removed;
    }

    /**
     * Get all live relation names (tables and views) for the current connection.
     *
     * @return list<string>
     */
    private static function getExistingRelationNames(): array
    {
        $tables = array_map(
            fn (array $table) => $table['name'],
            Schema::getTables(),
        );

        try {
            $views = array_map(
                fn (array $view) => $view['name'],
                Schema::getViews(),
            );
        } catch (\Throwable) {
            $views = [];
        }

        return array_values(array_unique(array_merge($tables, $views)));
    }
}
