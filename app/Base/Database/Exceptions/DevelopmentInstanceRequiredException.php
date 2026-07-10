<?php

namespace App\Base\Database\Exceptions;

use RuntimeException;

class DevelopmentInstanceRequiredException extends RuntimeException
{
    public static function forOperation(string $operation, string $environment): self
    {
        return new self(__(':operation is available only on a development instance; this instance is :environment.', [
            'operation' => $operation,
            'environment' => $environment,
        ]));
    }
}
