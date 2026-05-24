<?php

namespace App\Modules\Core\Company\Enums;

use App\Base\Foundation\Enums\BlbErrorCode;

enum CompanyErrorCode: string implements BlbErrorCode
{
    case LICENSEE_COMPANY_DELETION_FORBIDDEN = 'licensee_company_deletion_forbidden';
}
