<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Whether a provider record is being created or edited.
 *
 * Definitions use this to adjust required fields and secret-replacement
 * semantics (e.g. blank API key = "keep existing" on edit).
 */
enum ProviderOperation: string
{
    case Create = 'create';
    case Edit = 'edit';
}
