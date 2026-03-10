<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Settings\DTO;

enum ScopeType: string
{
    case COMPANY = 'company';
    case EMPLOYEE = 'employee';
}
