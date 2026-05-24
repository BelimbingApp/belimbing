<?php

namespace App\Modules\Core\Employee\Exceptions;

use App\Base\Foundation\Exceptions\BlbInvariantViolationException;
use App\Modules\Core\Employee\Enums\EmployeeErrorCode;

/**
 * Thrown when an attempt is made to delete a system Agent.
 */
final class SystemEmployeeDeletionException extends BlbInvariantViolationException
{
    public function __construct(int $employeeId)
    {
        parent::__construct(
            'System Agents cannot be deleted.',
            EmployeeErrorCode::SYSTEM_EMPLOYEE_DELETION_FORBIDDEN,
            ['employee_id' => $employeeId],
        );
    }
}
