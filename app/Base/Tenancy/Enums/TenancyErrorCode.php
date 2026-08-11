<?php

namespace App\Base\Tenancy\Enums;

use App\Base\Foundation\Enums\BlbErrorCode;

enum TenancyErrorCode: string implements BlbErrorCode
{
    case PLATFORM_OPERATOR_TENANT_DELETION_FORBIDDEN = 'platform_operator_tenant_deletion_forbidden';
    case PLATFORM_OPERATOR_TENANT_INVALID = 'platform_operator_tenant_invalid';
    case PLATFORM_OPERATOR_TENANT_NOT_PROVISIONED = 'platform_operator_tenant_not_provisioned';
    case TENANT_CONTEXT_MISSING = 'tenant_context_missing';
}
