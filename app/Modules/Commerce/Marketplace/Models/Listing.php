<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Marketplace\Models;

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Marketplace listing materialized from an external channel.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $item_id
 * @property string $channel
 * @property string|null $external_listing_id
 * @property string|null $external_offer_id
 * @property string|null $external_sku
 * @property string|null $marketplace_id
 * @property string|null $title
 * @property string|null $status
 * @property int|null $price_amount
 * @property string|null $currency_code
 * @property string|null $listing_url
 * @property Carbon|null $listed_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $last_synced_at
 * @property array<string, mixed>|null $raw_payload
 * @property-read Company $company
 * @property-read Item|null $item
 */
class Listing extends Model
{
    protected $table = 'commerce_marketplace_listings';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'item_id',
        'channel',
        'external_listing_id',
        'external_offer_id',
        'external_sku',
        'marketplace_id',
        'title',
        'status',
        'price_amount',
        'currency_code',
        'listing_url',
        'listed_at',
        'ended_at',
        'last_synced_at',
        'raw_payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'listed_at' => 'datetime',
            'ended_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
