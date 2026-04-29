<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Sales\Models;

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Sales\Database\Factories\SaleFactory;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Durable "this sold" ledger row.
 *
 * @property int $id
 * @property int $company_id
 * @property int $order_id
 * @property int $order_line_id
 * @property int|null $item_id
 * @property int|null $listing_id
 * @property string $channel
 * @property string|null $external_sale_id
 * @property Carbon|null $sold_at
 * @property int $quantity
 * @property int|null $sale_amount
 * @property string|null $currency_code
 * @property int|null $cost_basis_amount
 * @property int|null $fee_amount
 * @property array<string, mixed>|null $raw_payload
 * @property-read Company $company
 * @property-read Order $order
 * @property-read OrderLine $orderLine
 * @property-read Item|null $item
 * @property-read Listing|null $listing
 */
class Sale extends Model
{
    use HasFactory;

    protected $table = 'commerce_sales_sales';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'order_id',
        'order_line_id',
        'item_id',
        'listing_id',
        'channel',
        'external_sale_id',
        'sold_at',
        'quantity',
        'sale_amount',
        'currency_code',
        'cost_basis_amount',
        'fee_amount',
        'raw_payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    protected static function newFactory(): SaleFactory
    {
        return new SaleFactory;
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
     * @return BelongsTo<OrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
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
