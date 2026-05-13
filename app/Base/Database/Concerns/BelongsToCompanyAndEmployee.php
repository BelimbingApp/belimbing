<?php

namespace App\Base\Database\Concerns;

trait BelongsToCompanyAndEmployee
{
    use BelongsToCompany;
    use BelongsToEmployee;

    protected const COMPANY_EMPLOYEE_FILLABLE = [
        ...self::COMPANY_FILLABLE,
        ...self::EMPLOYEE_FILLABLE,
    ];
}
