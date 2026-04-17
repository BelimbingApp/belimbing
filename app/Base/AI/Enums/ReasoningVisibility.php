<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Enums;

enum ReasoningVisibility: string
{
    case None = 'none';
    case Summary = 'summary';
    case Full = 'full';
}
