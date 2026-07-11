<?php

namespace App\Base\Database\Concerns;

use App\Base\Database\Models\SeederRegistry;

trait RegistersSeeders
{
    use ExtractsModuleProvenance;

    /**
     * Register a seeder to be run after migration.
     * Simple interface: just pass the seeder class. Module path is auto-derived.
     */
    protected function registerSeeder(string $seederClass): void
    {
        // Get current migration file name and path
        $migrationPath = (new \ReflectionClass($this))->getFileName();
        $migrationFile = basename($migrationPath);
        $modulePath = $this->extractModulePath($migrationPath);
        $moduleName = $this->extractModuleName($modulePath);

        // Delegate to model for database operations
        SeederRegistry::register($seederClass, $moduleName, $modulePath, $migrationFile);
    }

    /**
     * Unregister a seeder (typically called in down() for clean rollbacks).
     */
    protected function unregisterSeeder(string $seederClass): void
    {
        // Delegate to model for database operations
        SeederRegistry::unregister($seederClass);
    }
}
