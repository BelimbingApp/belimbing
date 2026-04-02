<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Direction of a conversation message.
 */
enum MessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
