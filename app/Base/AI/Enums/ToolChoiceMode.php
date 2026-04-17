<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Enums;

enum ToolChoiceMode: string
{
    case Auto = 'auto';
    case None = 'none';
    case Required = 'required';
}
