<?php

namespace App\Base\Foundation\Services;

use App\Base\Database\Models\TableRegistry;

/**
 * Maps migration-declared tables to their owning Modules.
 *
 * Callers supply the module-root inventory so runtime composition and static
 * analysis can share this scanner without either rebuilding topology rules.
 */
final class ModuleTableOwnershipScanner
{
    /**
     * @param  array<string, string>  $moduleRoots
     * @return array<string, list<string>> Table name => owning Module IDs
     */
    public function scan(array $moduleRoots): array
    {
        $owners = [];

        foreach ($moduleRoots as $module => $path) {
            $pattern = $path.DIRECTORY_SEPARATOR.'Database'.DIRECTORY_SEPARATOR.'Migrations'.DIRECTORY_SEPARATOR.'*.php';

            foreach (glob($pattern) ?: [] as $file) {
                foreach (TableRegistry::declaredTableNames($file) as $table) {
                    $owners[$table][] = $module;
                }
            }
        }

        foreach ($owners as &$modules) {
            $modules = array_values(array_unique($modules));
            sort($modules);
        }
        unset($modules);

        ksort($owners);

        return $owners;
    }
}
