<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Marketplace\Contracts;

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\DTO\MarketplacePullResult;
use App\Modules\Commerce\Marketplace\Models\Listing;

interface MarketplaceChannel
{
    public function key(): string;

    public function pullListings(int $companyId): MarketplacePullResult;

    public function pullOrders(int $companyId): MarketplacePullResult;

    /**
     * @return array<string, mixed>
     */
    public function createListing(Item $item): array;

    /**
     * @return array<string, mixed>
     */
    public function reviseListing(Listing $listing): array;

    /**
     * @return array<string, mixed>
     */
    public function endListing(Listing $listing): array;
}
