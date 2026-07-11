<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\DTO\DataShare\DataShareInstanceIdentity;
use App\Base\Database\Exceptions\DataSharePolicyException;

class DataShareDirectionPolicy
{
    public function assertAllowed(DataShareInstanceIdentity $source, DataShareInstanceIdentity $target): void
    {
        if ($source->role->rank() >= $target->role->rank()) {
            throw DataSharePolicyException::directionDenied($source->role->value, $target->role->value);
        }
    }
}
