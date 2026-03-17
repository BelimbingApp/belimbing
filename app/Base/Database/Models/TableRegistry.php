<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
 * @property \Illuminate\Support\Carbon|null $stabilized_at When stability was toggled on
 * @property int|null $stabilized_by User who marked it stable
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
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
        $patterns = [
            app_path('Base/*/Database/Migrations/*.php'),
            app_path('Modules/*/*/Database/Migrations/*.php'),
        ];

        // Also include database/migrations for Laravel core tables
        $corePath = database_path('migrations/*.php');

        $files = [];
        foreach (array_merge($patterns, [$corePath]) as $pattern) {
            $files = array_merge($files, glob($pattern) ?: []);
        }

        foreach ($files as $file) {
            self::registerDiscoveredFile($file);
        }

        self::ensureInfrastructureRegistered();
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
     * Parse a migration file for Schema::create() calls and register found tables.
     *
     * @param  string  $file  Absolute path to a migration PHP file
     */
    private static function registerDiscoveredFile(string $file): void
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            return;
        }

        // Match Schema::create('table_name', ...) patterns
        if (! preg_match_all('/Schema::create\(\s*[\'"]([\w]+)[\'"]/', $contents, $matches)) {
            return;
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

        foreach ($matches[1] as $tableName) {
            if (self::query()->where('table_name', $tableName)->exists()) {
                continue;
            }

            self::register($tableName, $moduleName, $modulePath, $migrationFile);
        }
    }
}
