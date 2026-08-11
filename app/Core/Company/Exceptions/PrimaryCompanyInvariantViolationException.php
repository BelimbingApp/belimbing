<?php

namespace App\Core\Company\Exceptions;

use App\Base\Foundation\Exceptions\BlbInvariantViolationException;
use App\Core\Company\Enums\CompanyErrorCode;

final class PrimaryCompanyInvariantViolationException extends BlbInvariantViolationException
{
    /** @param array<string, mixed> $context */
    public function __construct(string $message, array $context = [])
    {
        parent::__construct($message, CompanyErrorCode::PRIMARY_COMPANY_INVALID, $context);
    }
}
