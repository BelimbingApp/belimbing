<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Classifies prompt sections for context budgeting and diagnostics.
 */
enum PromptSectionType: string
{
    /**
     * Identity, behavior, operating rules, extensions.
     * Loaded from workspace files or framework resources.
     */
    case Behavioral = 'behavioral';

    /**
     * User, company, delegation, dispatch metadata.
     * Assembled from runtime state each turn.
     */
    case Operational = 'operational';

    /**
     * Latest user message context, entity snapshots.
     * Valid for a single turn only.
     */
    case Transient = 'transient';
}
