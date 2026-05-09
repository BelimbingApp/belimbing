<?php
namespace App\Base\Database\Concerns;

/**
 * Shared logic for deriving module name and path from a migration file path.
 *
 * Used by RegistersSeeders and RegistersTables to auto-detect which module
 * a migration belongs to based on its filesystem location.
 */
trait ExtractsModuleProvenance
{
    /**
     * Extract module path from migration file path.
     *
     * @param  string  $migrationPath  Full path to migration file
     * @return string|null Module path (e.g., 'app/Modules/Core/Geonames')
     */
    protected function extractModulePath(string $migrationPath): ?string
    {
        // Pattern: .../app/Modules/{Layer}/{Module}/Database/Migrations/{file}
        if (preg_match('#app/Modules/[^/]+/[^/]+#', $migrationPath, $matches)) {
            return $matches[0];
        }

        // Pattern: .../app/Base/{Module}/Database/Migrations/{file}
        if (preg_match('#app/Base/[^/]+#', $migrationPath, $matches)) {
            return $matches[0];
        }

        return null;
    }

    /**
     * Extract module name from module path.
     *
     * e.g., 'app/Modules/Core/Geonames' → 'Geonames'
     *
     * @param  string|null  $modulePath  Module path
     * @return string|null Module name
     */
    protected function extractModuleName(?string $modulePath): ?string
    {
        if (! $modulePath) {
            return null;
        }

        return basename($modulePath);
    }
}
