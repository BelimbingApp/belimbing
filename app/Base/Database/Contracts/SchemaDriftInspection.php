<?php

namespace App\Base\Database\Contracts;

use App\Base\Database\Services\SchemaDrift\SchemaDriftReport;

interface SchemaDriftInspection
{
    public function inspect(): SchemaDriftReport;
}
