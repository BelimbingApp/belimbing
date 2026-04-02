<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Contracts;

use App\Modules\Core\AI\DTO\PageSnapshot;

/**
 * Livewire page components implement this to expose rich state to Lara.
 *
 * Extends ProvidesLaraPageContext to add form values, table schema,
 * modal state, and other detailed UI context. The snapshot is requested
 * on-demand via the active_page_snapshot tool — not injected by default.
 */
interface ProvidesLaraPageSnapshot extends ProvidesLaraPageContext
{
    /**
     * Build the page snapshot DTO with forms, tables, modals.
     *
     * Field values must be pre-masked via FieldVisibilityResolver before
     * inclusion. The component builds its own DTO — it decides what to expose.
     */
    public function pageSnapshot(): PageSnapshot;
}
