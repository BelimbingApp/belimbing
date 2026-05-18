<?php

namespace App\Base\Authz\Exceptions;

use App\Base\Authz\Enums\AuthzErrorCode;
use App\Base\Foundation\Exceptions\BlbDataContractException;

final class UnknownCapabilityException extends BlbDataContractException
{
    public static function fromKey(string $capability): self
    {
        return new self(
            'Unknown capability ['.$capability.'].',
            AuthzErrorCode::AUTHZ_UNKNOWN_CAPABILITY,
            ['capability' => $capability],
        );
    }
}
