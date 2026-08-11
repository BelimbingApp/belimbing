<?php

namespace App\Core\Employee\Exceptions;

use App\Base\Foundation\Exceptions\BlbInvariantViolationException;
use App\Core\Employee\Enums\EmployeeErrorCode;

final class SystemAgentInvariantViolationException extends BlbInvariantViolationException
{
    public function __construct(int $employeeId, int $companyId, int $expectedCompanyId)
    {
        parent::__construct(
            'The system Agent employee belongs to a different company. Repair the assignment before retrying provisioning.',
            EmployeeErrorCode::SYSTEM_EMPLOYEE_INVALID,
            [
                'employee_id' => $employeeId,
                'company_id' => $companyId,
                'expected_company_id' => $expectedCompanyId,
            ],
        );
    }
}
