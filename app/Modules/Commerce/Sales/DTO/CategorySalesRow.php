<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Sales\DTO;

/**
 * One row of a "sales by category" frequency query.
 *
 * `categoryId`/`categoryName` are null when the underlying sales weren't
 * linked to an inventory item, or the linked item didn't carry a category —
 * those are bucketed together so revenue gaps stay visible to the operator.
 *
 * Money fields are minor units in the surrounding query's currency.
 */
final readonly class CategorySalesRow
{
    public function __construct(
        public ?int $categoryId,
        public ?string $categoryName,
        public int $saleCount,
        public int $unitCount,
        public int $totalRevenueMinor,
        public int $totalCostMinor,
        public int $totalFeesMinor,
    ) {}

    public function grossProfitMinor(): int
    {
        return $this->totalRevenueMinor - $this->totalCostMinor - $this->totalFeesMinor;
    }
}
