<?php

namespace App\Base\Tenancy\Exceptions;

use App\Base\Foundation\Exceptions\BlbException;
use App\Base\Tenancy\Enums\TenancyErrorCode;

final class TenantOptionRequiredException extends BlbException
{
    public function __construct()
    {
        parent::__construct(
            'A --tenant=<id> option is required before this command can run.',
            TenancyErrorCode::TENANT_OPTION_REQUIRED,
        );
    }
}
