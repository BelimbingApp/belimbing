<?php

namespace App\Modules\Commerce\Marketplace\DTO;

/**
 * Outcome of adopting existing marketplace listings (created outside the
 * Inventory API) into Belimbing via a migration call.
 */
final readonly class MarketplaceMigrationResult
{
    /**
     * @param  list<array{listing_id: string, message: string}>  $failures
     */
    public function __construct(
        public string $channel,
        public int $requested,
        public int $migrated,
        public int $failed,
        public int $listingsCreated,
        public array $failures = [],
    ) {}
}
