<?php
namespace App\Base\Locale\Enums;

enum LocaleSource: string
{
    case MANUAL = 'manual';
    case LICENSEE_ADDRESS = 'licensee_address';
    case CONFIG_DEFAULT = 'config_default';
}
