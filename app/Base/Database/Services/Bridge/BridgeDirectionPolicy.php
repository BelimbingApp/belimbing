<?php

namespace App\Base\Database\Services\Bridge;

use App\Base\Database\DTO\Bridge\BridgeInstanceIdentity;
use App\Base\Database\Exceptions\BridgePolicyException;

class BridgeDirectionPolicy
{
    public function assertAllowed(BridgeInstanceIdentity $source, BridgeInstanceIdentity $target): void
    {
        if ($source->role->rank() >= $target->role->rank()) {
            throw BridgePolicyException::directionDenied($source->role->value, $target->role->value);
        }
    }
}
