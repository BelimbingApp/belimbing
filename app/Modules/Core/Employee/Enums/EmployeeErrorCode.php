<?php

namespace App\Modules\Core\Employee\Enums;

use App\Base\Foundation\Enums\BlbErrorCode;

enum EmployeeErrorCode: string implements BlbErrorCode
{
    case SYSTEM_EMPLOYEE_DELETION_FORBIDDEN = 'system_employee_deletion_forbidden';
}
