<?php

namespace App\Modules\Commerce\Marketplace\DTO;

/**
 * Outcome of reconciling the local listing cache against the marketplace's live
 * active set: how many were seen, newly added, and ended because they are no
 * longer active. `complete` is false when the active set was only partially read
 * (so no ending was performed, to avoid retiring listings off a partial view).
 */
final readonly class MarketplaceReconcileResult
{
    public function __construct(
        public string $channel,
        public int $active,
        public int $created,
        public int $refreshed,
        public int $ended,
        public bool $complete,
    ) {}
}
