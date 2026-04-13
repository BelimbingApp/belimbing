<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

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
