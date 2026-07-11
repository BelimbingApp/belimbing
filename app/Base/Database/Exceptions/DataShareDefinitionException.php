<?php

namespace App\Base\Database\Exceptions;

use RuntimeException;

class DataShareDefinitionException extends RuntimeException
{
    public static function invalid(string $reason): self
    {
        return new self(__('Invalid Data Share definition: :reason', ['reason' => $reason]));
    }

    public static function unclassifiedTable(string $table): self
    {
        return new self(__('Database table :table is not registered in an available Base export scope.', ['table' => $table]));
    }
}
