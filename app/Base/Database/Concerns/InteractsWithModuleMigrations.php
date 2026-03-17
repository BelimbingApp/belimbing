<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Concerns;

/**
 * Trait for interacting with module-aware migrations.
 *
 * This trait is intended to be used by Laravel migration commands that extend
 * \Illuminate\Database\Console\Migrations\BaseCommand or its subclasses.
 *
 * @mixin \Illuminate\Database\Console\Migrations\BaseCommand
 *
 * @property \Illuminate\Database\Migrations\Migrator $migrator
 */
trait InteractsWithModuleMigrations
{
    /**
     * Load migrations from all module discovery paths.
     *
     * Scans Base and Modules layers for Database/Migrations directories
     * and registers each with the migrator.
     */
    protected function loadAllModuleMigrations(): void
    {
        $paths = array_merge(
            glob(app_path('Base/*/Database/Migrations'), GLOB_ONLYDIR) ?: [],
            glob(app_path('Modules/*/*/Database/Migrations'), GLOB_ONLYDIR) ?: [],
        );

        foreach ($paths as $path) {
            $this->migrator->path($path);
        }
    }

    /**
     * Get all of the migration paths.
     *
     * Always includes `database/migrations/` for Laravel core tables (cache, jobs, sessions),
     * plus module migration paths when modules are specified.
     *
     * @return string[]
     */
    protected function getMigrationPaths(): array
    {
        $paths = $this->migrator->paths();

        // Always include database/migrations for Laravel core tables (cache, jobs, sessions)
        $corePath = $this->laravel->databasePath('migrations');
        if (! in_array($corePath, $paths)) {
            $paths[] = $corePath;
        }

        return $paths;
    }
}
