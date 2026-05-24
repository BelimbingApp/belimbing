<?php

namespace App\Base\Database\Contracts;

interface IncubatingSchemaInspector
{
    public function tableIsIncubating(string $tableName): bool;

    public function tableSchemaState(string $tableName): string;

    /**
     * @param  list<string>  $tableNames
     * @return array<string, string>
     */
    public function schemaStatesForTables(array $tableNames): array;
}
