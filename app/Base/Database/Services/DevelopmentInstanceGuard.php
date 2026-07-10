<?php

namespace App\Base\Database\Services;

use App\Base\Database\Exceptions\DevelopmentInstanceRequiredException;

class DevelopmentInstanceGuard
{
    public function assertDevelopment(string $operation): void
    {
        $environment = (string) config('app.env');

        if (! in_array($environment, ['local', 'testing'], true)) {
            throw DevelopmentInstanceRequiredException::forOperation($operation, $environment);
        }
    }
}
