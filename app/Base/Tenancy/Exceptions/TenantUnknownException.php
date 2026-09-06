<?php

namespace App\Base\Tenancy\Exceptions;

use App\Base\Foundation\Exceptions\BlbException;
use App\Base\Tenancy\Enums\TenancyErrorCode;

final class TenantUnknownException extends BlbException
{
    public function __construct(int $tenantId)
    {
        parent::__construct(
            sprintf('Tenant [%d] is unknown or not available.', $tenantId),
            TenancyErrorCode::TENANT_UNKNOWN,
            ['tenant_id' => $tenantId],
        );
    }
}
