<?php

namespace App\Core\Company\Exceptions;

use App\Base\Foundation\Exceptions\BlbInvariantViolationException;
use App\Core\Company\Enums\CompanyErrorCode;

final class PrimaryCompanyDeletionException extends BlbInvariantViolationException
{
    public function __construct(int $tenantId, int $companyId)
    {
        parent::__construct(
            'A tenant primary company cannot be deleted before its role is safely transferred.',
            CompanyErrorCode::PRIMARY_COMPANY_DELETION_FORBIDDEN,
            ['tenant_id' => $tenantId, 'company_id' => $companyId],
        );
    }
}
