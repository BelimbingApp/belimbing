<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Sales\DTO;

use App\Modules\Commerce\Sales\Models\Sale;
use Illuminate\Support\Carbon;

/**
 * Aggregate result for "sold in this period" queries against
 * {@see Sale}.
 *
 * Money fields are minor units (e.g. cents) tied to a single currency. Mixed-
 * currency totals are not represented — callers query one currency at a time.
 */
final readonly class SalesPeriodSummary
{
    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public int $saleCount,
        public int $unitCount,
        public int $totalRevenueMinor,
        public int $totalCostMinor,
        public int $totalFeesMinor,
        public string $currencyCode,
    ) {}

    public function grossProfitMinor(): int
    {
        return $this->totalRevenueMinor - $this->totalCostMinor - $this->totalFeesMinor;
    }
}
