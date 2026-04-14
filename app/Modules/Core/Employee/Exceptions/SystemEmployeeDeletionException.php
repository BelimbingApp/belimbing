<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Employee\Exceptions;

use App\Base\Foundation\Enums\BlbErrorCode;
use App\Base\Foundation\Exceptions\BlbInvariantViolationException;

/**
 * Thrown when an attempt is made to delete a system Agent.
 */
final class SystemEmployeeDeletionException extends BlbInvariantViolationException
{
    public function __construct(int $employeeId)
    {
        parent::__construct(
            'System Agents cannot be deleted.',
            BlbErrorCode::SYSTEM_EMPLOYEE_DELETION_FORBIDDEN,
            ['employee_id' => $employeeId],
        );
    }
}
