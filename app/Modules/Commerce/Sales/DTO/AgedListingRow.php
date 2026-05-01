<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Sales\DTO;

use Illuminate\Support\Carbon;

/**
 * One row of a "listings aging without a sale" insights query.
 *
 * `daysListed` is computed against the caller's "as of" date so the same listing
 * row gives consistent aging across the page even if rendering takes a moment.
 * `priceAmountMinor` is in the listing's currency (the surrounding query is
 * scoped to one currency).
 */
final readonly class AgedListingRow
{
    public function __construct(
        public int $listingId,
        public ?int $itemId,
        public string $channel,
        public ?string $marketplaceId,
        public ?string $title,
        public ?int $priceAmountMinor,
        public Carbon $listedAt,
        public int $daysListed,
    ) {}
}
