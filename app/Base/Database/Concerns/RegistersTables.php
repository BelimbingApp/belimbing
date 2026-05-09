<?php
namespace App\Base\Database\Concerns;

use App\Base\Database\Models\TableRegistry;

/**
 * Trait for migrations to register tables in the Table Registry.
 *
 * Call registerTable() in up() and unregisterTable() in down() to track
 * which module owns each table. Tables can then be marked stable in the
 * admin UI to survive migrate:fresh.
 */
trait RegistersTables
{
    use ExtractsModuleProvenance;

    /**
     * Register a table in the registry.
     * Module path is auto-derived from the migration file location.
     *
     * @param  string  $tableName  Physical database table name
     */
    protected function registerTable(string $tableName): void
    {
        $migrationPath = (new \ReflectionClass($this))->getFileName();
        $migrationFile = basename($migrationPath);
        $modulePath = $this->extractModulePath($migrationPath);
        $moduleName = $this->extractModuleName($modulePath);

        TableRegistry::register($tableName, $moduleName, $modulePath, $migrationFile);
    }

    /**
     * Unregister a table from the registry (typically called in down()).
     *
     * @param  string  $tableName  Physical database table name
     */
    protected function unregisterTable(string $tableName): void
    {
        TableRegistry::unregister($tableName);
    }
}
