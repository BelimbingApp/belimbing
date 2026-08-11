<?php

namespace App\Base\Tenancy\Exceptions;

use App\Base\Foundation\Exceptions\BlbInvariantViolationException;
use App\Base\Tenancy\Enums\TenancyErrorCode;

final class PlatformOperatorTenantInvariantViolationException extends BlbInvariantViolationException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, array $context = [])
    {
        parent::__construct(
            $message,
            TenancyErrorCode::PLATFORM_OPERATOR_TENANT_INVALID,
            $context,
        );
    }
}
