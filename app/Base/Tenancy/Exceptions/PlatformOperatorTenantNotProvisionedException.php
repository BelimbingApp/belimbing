<?php

namespace App\Base\Tenancy\Exceptions;

use App\Base\Foundation\Exceptions\BlbInvariantViolationException;
use App\Base\Tenancy\Enums\TenancyErrorCode;

final class PlatformOperatorTenantNotProvisionedException extends BlbInvariantViolationException
{
    public function __construct()
    {
        parent::__construct(
            'The platform-operator tenant has not been provisioned.',
            TenancyErrorCode::PLATFORM_OPERATOR_TENANT_NOT_PROVISIONED,
        );
    }
}
