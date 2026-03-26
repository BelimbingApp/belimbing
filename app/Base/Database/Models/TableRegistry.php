<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Table Registry Model
 *
 * Tracks database tables registered by migrations. Tables marked as stable
 * are preserved during migrate:fresh — their data survives the wipe.
 *
 * @method static \Illuminate\Database\Eloquent\Builder|static stable() Query tables marked as stable
 * @method static \Illuminate\Database\Eloquent\Builder|static unstable() Query tables not marked as stable
 *
 * @property int $id
 * @property string $table_name Physical database table name
 * @property string|null $module_name Module name (e.g., 'AI')
 * @property string|null $module_path Module path (e.g., 'app/Modules/Core/AI')
 * @property string|null $migration_file Migration file that created this table
 * @property bool $is_stable Whether this table survives migrate:fresh
 * @property Carbon|null $stabilized_at When stability was toggled on
 * @property int|null $stabilized_by User who marked it stable
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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
        'is_stable',
        'stabilized_at',
        'stabilized_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_stable' => 'boolean',
        'stabilized_at' => 'datetime',
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
     * Scope to query tables marked as stable.
     */
    #[Scope]
    protected function stable(Builder $query): Builder
    {
        return $query->where('is_stable', true);
    }

    /**
     * Scope to query tables not marked as stable.
     */
    #[Scope]
    protected function unstable(Builder $query): Builder
    {
        return $query->where('is_stable', false);
    }

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
            'is_stable' => true,
            'stabilized_at' => now(),
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
     * Mark this table as stable (preserved during migrate:fresh).
     *
     * @param  int|null  $userId  User who marked it stable
     */
    public function markStable(?int $userId = null): bool
    {
        return $this->update([
            'is_stable' => true,
            'stabilized_at' => now(),
            'stabilized_by' => $userId,
        ]);
    }

    /**
     * Mark this table as unstable (will be wiped during migrate:fresh).
     */
    public function markUnstable(): bool
    {
        return $this->update([
            'is_stable' => false,
            'stabilized_at' => null,
            'stabilized_by' => null,
        ]);
    }

    /**
     * Get all table names that should be preserved (stable + infrastructure).
     *
     * @return array<string>
     */
    public static function getPreservedTableNames(): array
    {
        $stable = self::query()->stable()->pluck('table_name')->all();

        return array_unique(array_merge($stable, self::INFRASTRUCTURE_TABLES));
    }

    /**
     * Check if this table is stable.
     */
    public function isStable(): bool
    {
        return $this->is_stable;
    }

    /**
     * Auto-discover tables from migration files and register them.
     *
     * Scans migration files under app/Base and app/Modules for Schema::create()
     * calls, extracts table names, and registers any not already in the registry.
     * Does not overwrite existing rows (preserves stability flags).
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

        // Match Schema::create('table_name', ...) patterns
        if (! preg_match_all('/Schema::create\(\s*[\'"]([\w]+)[\'"]/', $contents, $matches)) {
            return [];
        }

        $rel = str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $file);
        $migrationFile = basename($file);

        // Derive module provenance from file path
        $modulePath = null;
        $moduleName = null;

        if (preg_match('#app/Modules/[^/]+/[^/]+#', $rel, $pathMatch)
            || preg_match('#app/Base/[^/]+#', $rel, $pathMatch)
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
