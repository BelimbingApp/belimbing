<?php
namespace App\Modules\Commerce\Sales\DTO;

/**
 * One row of a "margin per item" insights query.
 *
 * Money fields are minor units tied to the surrounding query's currency.
 * `itemId` is null when the underlying sale rows were not linked back to
 * an inventory item (e.g. an unmatched listing or pre-link sale).
 */
final readonly class ItemMarginRow
{
    public function __construct(
        public ?int $itemId,
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
