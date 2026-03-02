<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Seeder Registry Model
 *
 * Tracks database seeders registered by migrations for registry-based execution.
 * Provides status tracking, error handling, and module filtering capabilities.
 *
 * @method static \Illuminate\Database\Eloquent\Builder|static pending() Query seeders that are pending execution
 * @method static \Illuminate\Database\Eloquent\Builder|static failed() Query seeders that failed execution
 * @method static \Illuminate\Database\Eloquent\Builder|static completed() Query seeders that completed successfully
 * @method static \Illuminate\Database\Eloquent\Builder|static runnable() Query seeders that are pending or failed (ready to run/retry)
 * @method static \Illuminate\Database\Eloquent\Builder|static forModules(array|string $modules) Filter seeders by module name(s)
 * @method static \Illuminate\Database\Eloquent\Builder|static inMigrationOrder() Order seeders by migration file for execution order
 *
 * @property int $id
 * @property string $seeder_class Fully qualified seeder class name
 * @property string|null $module_name Module name (e.g., 'Geonames')
 * @property string|null $module_path Module path (e.g., 'app/Modules/Core/Geonames')
 * @property string|null $migration_file Migration file that registered this seeder
 * @property string $status Current execution status
 * @property \Illuminate\Support\Carbon|null $ran_at Timestamp when seeder completed
 * @property string|null $error_message Error message if seeder failed
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class SeederRegistry extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'base_database_seeders';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'seeder_class',
        'module_name',
        'module_path',
        'migration_file',
        'status',
        'ran_at',
        'error_message',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'ran_at' => 'datetime',
    ];

    /**
     * Status: Seeder is pending execution.
     */
    const STATUS_PENDING = 'pending';

    /**
     * Status: Seeder is currently running.
     */
    const STATUS_RUNNING = 'running';

    /**
     * Status: Seeder completed successfully.
     */
    const STATUS_COMPLETED = 'completed';

    /**
     * Status: Seeder execution failed.
     */
    const STATUS_FAILED = 'failed';

    /**
     * Status: Seeder was skipped.
     */
    const STATUS_SKIPPED = 'skipped';

    /**
     * Scope to query seeders that are pending execution.
     */
    #[Scope]
    protected function pending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to query seeders that failed execution.
     */
    #[Scope]
    protected function failed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope to query seeders that completed successfully.
     */
    #[Scope]
    protected function completed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope to query seeders that are pending or failed (ready to run/retry).
     */
    #[Scope]
    protected function runnable(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_FAILED,
        ]);
    }

    /**
     * Scope to filter seeders by module name(s).
     */
    #[Scope]
    protected function forModules(Builder $query, array|string $modules): Builder
    {
        $modules = (array) $modules;

        // If empty array or wildcard '*' is present, don't filter
        if ($modules === [] || in_array('*', $modules, true)) {
            return $query;
        }

        return $query->whereIn('module_name', $modules);
    }

    /**
     * Scope to order seeders by migration file for execution order.
     * Seeders with null migration_file (discovered) sort after and by seeder_class.
     */
    #[Scope]
    protected function inMigrationOrder(Builder $query): Builder
    {
        return $query->orderBy('migration_file')->orderBy('seeder_class');
    }

    /**
     * Register a seeder in the registry.
     *
     * @param  string  $seederClass  Fully qualified seeder class name
     * @param  string|null  $moduleName  Module name (e.g., 'Geonames')
     * @param  string|null  $modulePath  Module path (e.g., 'app/Modules/Core/Geonames')
     * @param  string|null  $migrationFile  Migration file that registered this seeder (null for discovered seeders)
     */
    public static function register(
        string $seederClass,
        ?string $moduleName,
        ?string $modulePath,
        ?string $migrationFile = null
    ): void {
        // Use updateOrCreate to handle rollback/re-run: reset status to pending
        self::query()->updateOrCreate(
            ['seeder_class' => $seederClass],
            [
                'module_name' => $moduleName,
                'module_path' => $modulePath,
                'migration_file' => $migrationFile,
                'status' => self::STATUS_PENDING,
                'ran_at' => null,
                'error_message' => null,
            ]
        );
    }

    /**
     * Ensure BLB seeders under app/Base/<module>/Database/Seeders and
     * app/Modules/<layer>/<module>/Database/Seeders are in the registry.
     * Mirrors the layer pattern used by InteractsWithModuleMigrations.
     * Only inserts when seeder_class is missing; does not overwrite migration-registered rows.
     */
    public static function ensureDiscoveredRegistered(): void
    {
        $layers = [
            app_path('Base') => '/*/Database/Seeders/*.php',
            app_path('Modules') => '/*/*/Database/Seeders/*.php',
        ];

        $files = [];
        foreach ($layers as $appPath => $pattern) {
            $files = array_merge($files, glob($appPath.$pattern) ?: []);
        }
        foreach ($files as $file) {
            self::registerDiscoveredFile($file);
        }
    }

    /**
     * Register a single discovered seeder file if not already in the registry.
     *
     * @param  string  $file  Absolute path to the seeder PHP file
     */
    private static function registerDiscoveredFile(string $file): void
    {
        $rel = str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $file);
        if (! str_ends_with($rel, '.php')) {
            return;
        }
        if (! str_starts_with($rel, 'app/Base/') && ! str_starts_with($rel, 'app/Modules/')) {
            return;
        }
        $fqcn = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($rel, 4));
        if (self::query()->where('seeder_class', $fqcn)->exists()) {
            return;
        }
        $beforeSeeders = '/Database/Seeders/';
        $pos = strpos($rel, $beforeSeeders);
        $modulePath = $pos !== false ? substr($rel, 0, $pos) : null;
        $moduleName = $modulePath ? basename($modulePath) : null;
        self::register($fqcn, $moduleName, $modulePath, null);
    }

    /**
     * Unregister a seeder from the registry.
     *
     * @param  string  $seederClass  Fully qualified seeder class name
     */
    public static function unregister(string $seederClass): void
    {
        self::query()->where('seeder_class', $seederClass)->delete();
    }

    /**
     * Mark this seeder as running.
     *
     * Clears any previous error message.
     */
    public function markAsRunning(): bool
    {
        return $this->update([
            'status' => self::STATUS_RUNNING,
            'error_message' => null,
        ]);
    }

    /**
     * Mark this seeder as completed.
     */
    public function markAsCompleted(): bool
    {
        return $this->update([
            'status' => self::STATUS_COMPLETED,
            'ran_at' => now(),
        ]);
    }

    /**
     * Mark this seeder as failed with an error message.
     */
    public function markAsFailed(string $errorMessage): bool
    {
        return $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Check if this seeder is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if this seeder has failed.
     */
    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if this seeder has completed.
     */
    public function hasCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
