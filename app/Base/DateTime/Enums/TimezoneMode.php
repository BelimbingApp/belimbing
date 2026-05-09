<?php
namespace App\Base\DateTime\Enums;

enum TimezoneMode: string
{
    case COMPANY = 'company';
    case LOCAL = 'local';
    case UTC = 'utc';

    public function label(): string
    {
        return match ($this) {
            self::COMPANY => __('Company'),
            self::LOCAL => __('Local'),
            self::UTC => __('Stored'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::COMPANY => __('Company'),
            self::LOCAL => __('Local (browser)'),
            self::UTC => __('Stored (raw)'),
        };
    }
}
