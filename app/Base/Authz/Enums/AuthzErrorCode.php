<?php

namespace App\Base\Authz\Enums;

use App\Base\Foundation\Enums\BlbErrorCode;

enum AuthzErrorCode: string implements BlbErrorCode
{
    case AUTHZ_DENIED = 'authz_denied';
    case AUTHZ_UNKNOWN_CAPABILITY = 'authz_unknown_capability';
}
