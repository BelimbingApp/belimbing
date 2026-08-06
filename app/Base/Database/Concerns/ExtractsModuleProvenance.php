<?php

namespace App\Base\Database\Concerns;

use App\Base\Foundation\ApplicationTopology;
use App\Base\Support\Str;

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
     * @return string|null Module path (e.g., 'app/Core/Geonames')
     */
    protected function extractModulePath(string $migrationPath): ?string
    {
        $normalized = str_replace('\\', '/', $migrationPath);

        // Pattern: .../app/Core/{Module}/Database/Migrations/{file}
        if (preg_match('#app/Core/[^/]+#', $normalized, $matches)) {
            return $matches[0];
        }

        // Pattern: .../app/Domains/{Domain}/{Module}/Database/Migrations/{file}
        if (preg_match('#app/Domains/[^/]+/[^/]+#', $normalized, $matches)) {
            return $matches[0];
        }

        // Pattern: .../app/Base/{Module}/Database/Migrations/{file}
        if (preg_match('#app/Base/[^/]+#', $normalized, $matches)) {
            return $matches[0];
        }

        // Pattern: .../app/Extensions/{Extension}/{Module}/Database/Migrations/{file}
        if (preg_match('#app/Extensions/[^/]+/[^/]+#', $normalized, $matches)) {
            return $matches[0];
        }

        return null;
    }

    /**
     * Extract module name from module path.
     *
     * e.g., 'app/Core/Geonames' → 'Geonames'
     *
     * @param  string|null  $modulePath  Module path
     * @return string|null Module name
     */
    protected function extractModuleName(?string $modulePath): ?string
    {
        if (! $modulePath) {
            return null;
        }

        $moduleName = basename($modulePath);

        // Extension directories became PascalCase in the four-root topology,
        // but their persisted logical module names remain kebab-case.
        return ApplicationTopology::belongsToRoot($modulePath, ApplicationTopology::EXTENSIONS)
            ? Str::pascalToKebab($moduleName)
            : $moduleName;
    }
}
