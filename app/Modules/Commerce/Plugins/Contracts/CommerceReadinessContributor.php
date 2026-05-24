<?php

namespace App\Modules\Commerce\Plugins\Contracts;

use App\Modules\Commerce\Inventory\Models\Item;

interface CommerceReadinessContributor
{
    /**
     * Stable contribution identifier, e.g. `ham.auto-parts.identifiers`.
     */
    public function id(): string;

    /**
     * @return list<array{code: string, severity: 'success'|'blocker'|'warning'|'suggestion', label: string, description?: string, action?: string}>
     */
    public function readinessForItem(Item $item): array;
}
