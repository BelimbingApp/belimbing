<?php

namespace App\Base\Locale\Enums;

enum LocaleSource: string
{
    case MANUAL = 'manual';
    case PLATFORM_OPERATOR_ADDRESS = 'platform_operator_address';
    case DECLARED_DEFAULT = 'declared_default';
}
