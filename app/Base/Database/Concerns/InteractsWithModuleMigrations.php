<?php

namespace App\Base\Database\Concerns;

use Illuminate\Database\Console\Migrations\BaseCommand;
use Illuminate\Database\Migrations\Migrator;

/**
 * Trait for interacting with module-aware migrations.
 *
 * This trait is intended to be used by Laravel migration commands that extend
 * \Illuminate\Database\Console\Migrations\BaseCommand or its subclasses.
 *
 * @mixin BaseCommand
 *
 * @property Migrator $migrator
 */
trait InteractsWithModuleMigrations
{
    /**
     * Load migrations from all module discovery paths.
     *
     * Scans Base, application modules, and extension modules for
     * Database/Migrations directories and registers each with the migrator.
     */
    protected function loadAllModuleMigrations(): void
    {
        $paths = array_merge(
            glob(app_path('Base/*/Database/Migrations'), GLOB_ONLYDIR) ?: [],
            glob(app_path('Modules/*/*/Database/Migrations'), GLOB_ONLYDIR) ?: [],
            glob(base_path('extensions/*/*/Database/Migrations'), GLOB_ONLYDIR) ?: [],
        );

        foreach ($paths as $path) {
            $this->migrator->path($path);
        }
    }

    /**
     * Get all of the migration paths.
     *
     * Honors explicit --path scopes, otherwise includes `database/migrations/`
     * for Laravel core tables (cache, jobs, sessions) plus discovered module
     * migration paths.
     *
     * @return string[]
     */
    protected function getMigrationPaths(): array
    {
        if ($this->input->hasOption('path') && $this->option('path')) {
            return array_map(
                fn (string $path): string => $this->usingRealPath()
                    ? $path
                    : $this->laravel->basePath().'/'.$path,
                (array) $this->option('path'),
            );
        }

        $paths = $this->migrator->paths();

        // Always include database/migrations for Laravel core tables (cache, jobs, sessions)
        $corePath = $this->laravel->databasePath('migrations');
        if (! in_array($corePath, $paths, true)) {
            $paths[] = $corePath;
        }

        return $paths;
    }
}
