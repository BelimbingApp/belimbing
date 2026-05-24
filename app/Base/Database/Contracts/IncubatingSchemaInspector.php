<?php

namespace App\Base\Database\Contracts;

interface IncubatingSchemaInspector
{
    public function tableIsIncubating(string $tableName): bool;
}
