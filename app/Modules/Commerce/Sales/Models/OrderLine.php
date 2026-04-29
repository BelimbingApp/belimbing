<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Sales\Models;

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Sales\Database\Factories\OrderLineFactory;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sellable line from a marketplace order.
 *
 * @property int $id
 * @property int $company_id
 * @property int $order_id
 * @property int|null $item_id
 * @property int|null $listing_id
 * @property string|null $external_line_item_id
 * @property string|null $external_listing_id
 * @property string|null $external_sku
 * @property string|null $title
 * @property int $quantity
 * @property int|null $unit_price_amount
 * @property int|null $line_total_amount
 * @property string|null $currency_code
 * @property array<string, mixed>|null $raw_payload
 * @property-read Company $company
 * @property-read Order $order
 * @property-read Item|null $item
 * @property-read Listing|null $listing
 */
class OrderLine extends Model
{
    use HasFactory;

    protected $table = 'commerce_sales_order_lines';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'order_id',
        'item_id',
        'listing_id',
        'external_line_item_id',
        'external_listing_id',
        'external_sku',
        'title',
        'quantity',
        'unit_price_amount',
        'line_total_amount',
        'currency_code',
        'raw_payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
        ];
    }

    protected static function newFactory(): OrderLineFactory
    {
        return new OrderLineFactory;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
