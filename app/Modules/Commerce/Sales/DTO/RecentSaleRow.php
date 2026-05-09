<?php
namespace App\Modules\Commerce\Sales\DTO;

use Illuminate\Support\Carbon;

/**
 * One row of a "recent sales" listing query.
 *
 * `title` is resolved at query time: prefer the linked `Item->title` (source of
 * truth), fall back to the `OrderLine->title` captured from the channel, then
 * the channel SKU, then an empty string. `categoryName` is null when the sale
 * isn't linked to an item, or the item isn't categorized.
 */
final readonly class RecentSaleRow
{
    public function __construct(
        public int $saleId,
        public Carbon $soldAt,
        public ?int $itemId,
        public string $title,
        public ?string $categoryName,
        public string $channel,
        public int $quantity,
        public ?int $saleAmountMinor,
    ) {}
}
