<?php

namespace App\Base\Tenancy\Contracts;

/** A queued job whose serialized command names its tenant explicitly. */
interface CarriesTenant
{
    public function tenantId(): int;
}
