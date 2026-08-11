<?php

namespace App\Base\Tenancy\Exceptions;

use App\Base\Foundation\Exceptions\BlbInvariantViolationException;
use App\Base\Tenancy\Enums\TenancyErrorCode;

final class PlatformOperatorTenantDeletionException extends BlbInvariantViolationException
{
    public function __construct(int $tenantId)
    {
        parent::__construct(
            'The platform-operator tenant cannot be deleted.',
            TenancyErrorCode::PLATFORM_OPERATOR_TENANT_DELETION_FORBIDDEN,
            ['tenant_id' => $tenantId],
        );
    }
}
