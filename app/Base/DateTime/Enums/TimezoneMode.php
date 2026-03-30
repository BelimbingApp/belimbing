<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\DateTime\Enums;

enum TimezoneMode: string
{
    case COMPANY = 'company';
    case LOCAL = 'local';
    case UTC = 'utc';
}
