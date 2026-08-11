<?php

namespace App\Core\Company\Exceptions;

use App\Base\Foundation\Exceptions\BlbInvariantViolationException;
use App\Core\Company\Enums\CompanyErrorCode;

final class PrimaryCompanyNotProvisionedException extends BlbInvariantViolationException
{
    public function __construct(int $tenantId)
    {
        parent::__construct(
            "Tenant {$tenantId} has not finished provisioning a primary company.",
            CompanyErrorCode::PRIMARY_COMPANY_NOT_PROVISIONED,
            ['tenant_id' => $tenantId],
        );
    }
}
