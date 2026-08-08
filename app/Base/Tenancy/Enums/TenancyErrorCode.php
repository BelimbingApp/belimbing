<?php

namespace App\Base\Tenancy\Enums;

use App\Base\Foundation\Enums\BlbErrorCode;

enum TenancyErrorCode: string implements BlbErrorCode
{
    case LICENSEE_TENANT_DELETION_FORBIDDEN = 'licensee_tenant_deletion_forbidden';
    case TENANT_CONTEXT_MISSING = 'tenant_context_missing';
}
