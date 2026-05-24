<?php

namespace App\Modules\Core\Company\Exceptions;

use App\Base\Foundation\Exceptions\BlbInvariantViolationException;
use App\Modules\Core\Company\Enums\CompanyErrorCode;

/**
 * Thrown when an attempt is made to delete the licensee company (id=1).
 */
final class LicenseeCompanyDeletionException extends BlbInvariantViolationException
{
    public function __construct()
    {
        parent::__construct(
            'The licensee company (id=1) cannot be deleted.',
            CompanyErrorCode::LICENSEE_COMPANY_DELETION_FORBIDDEN,
            ['company_id' => 1],
        );
    }
}
