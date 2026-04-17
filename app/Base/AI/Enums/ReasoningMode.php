<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Enums;

enum ReasoningMode: string
{
    case Auto = 'auto';
    case Enabled = 'enabled';
    case Disabled = 'disabled';
}
