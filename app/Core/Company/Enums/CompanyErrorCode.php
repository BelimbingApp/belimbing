<?php

namespace App\Core\Company\Enums;

use App\Base\Foundation\Enums\BlbErrorCode;

enum CompanyErrorCode: string implements BlbErrorCode
{
    case COMPANY_TENANT_ASSIGNMENT_INVALID = 'company_tenant_assignment_invalid';
    case PRIMARY_COMPANY_ASSIGNMENT_INVALID = 'primary_company_assignment_invalid';
    case PRIMARY_COMPANY_DELETION_FORBIDDEN = 'primary_company_deletion_forbidden';
    case PRIMARY_COMPANY_INVALID = 'primary_company_invalid';
    case PRIMARY_COMPANY_NOT_PROVISIONED = 'primary_company_not_provisioned';
}
