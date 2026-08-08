<?php

namespace App\Base\Tenancy\Exceptions;

use App\Base\Foundation\Exceptions\BlbInvariantViolationException;
use App\Base\Tenancy\Enums\TenancyErrorCode;

/**
 * Thrown when an attempt is made to delete the licensee tenant (id=1).
 */
final class LicenseeTenantDeletionException extends BlbInvariantViolationException
{
    public function __construct()
    {
        parent::__construct(
            'The licensee tenant (id=1) cannot be deleted.',
            TenancyErrorCode::LICENSEE_TENANT_DELETION_FORBIDDEN,
            ['tenant_id' => 1],
        );
    }
}
