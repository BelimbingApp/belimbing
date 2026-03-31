<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Locale\Enums;

enum LocaleSource: string
{
    case MANUAL = 'manual';
    case LICENSEE_ADDRESS = 'licensee_address';
    case CONFIG_DEFAULT = 'config_default';
}
