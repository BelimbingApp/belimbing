<?php

namespace App\Base\Tenancy\Exceptions;

use App\Base\Foundation\Exceptions\BlbException;
use App\Base\Tenancy\Enums\TenancyErrorCode;

final class TenantInactiveException extends BlbException
{
    public function __construct(int $tenantId, string $status)
    {
        parent::__construct(
            sprintf('Tenant [%d] is not active (status: %s).', $tenantId, $status),
            TenancyErrorCode::TENANT_INACTIVE,
            ['tenant_id' => $tenantId, 'status' => $status],
        );
    }
}
