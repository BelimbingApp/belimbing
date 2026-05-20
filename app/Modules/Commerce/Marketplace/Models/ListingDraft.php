<?php

namespace App\Modules\Commerce\Marketplace\Models;

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Local marketplace listing draft and readiness record.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $item_id
 * @property int|null $listing_id
 * @property string $channel
 * @property string $marketplace_id
 * @property string|null $metadata_marketplace_id
 * @property string|null $external_sku
 * @property string|null $title
 * @property string|null $category_id
 * @property string $status
 * @property string $management_state
 * @property array<string, mixed>|null $aspect_values
 * @property array<string, mixed>|null $mapped_aspects
 * @property array<string, mixed>|null $policy_ids
 * @property string|null $merchant_location_key
 * @property array<int, int>|null $photo_asset_ids
 * @property string $readiness_status
 * @property array<string, mixed>|null $readiness_snapshot
 * @property Carbon|null $metadata_checked_at
 * @property string|null $metadata_version_key
 * @property string|null $publish_intent
 * @property string|null $last_failure_summary
 * @property-read Company $company
 * @property-read Item|null $item
 * @property-read Listing|null $listing
 */
class ListingDraft extends Model
{
    protected $table = 'commerce_marketplace_listing_drafts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'item_id',
        'listing_id',
        'channel',
        'marketplace_id',
        'metadata_marketplace_id',
        'external_sku',
        'title',
        'category_id',
        'status',
        'management_state',
        'aspect_values',
        'mapped_aspects',
        'policy_ids',
        'merchant_location_key',
        'photo_asset_ids',
        'readiness_status',
        'readiness_snapshot',
        'metadata_checked_at',
        'metadata_version_key',
        'publish_intent',
        'last_failure_summary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aspect_values' => 'array',
            'mapped_aspects' => 'array',
            'policy_ids' => 'array',
            'photo_asset_ids' => 'array',
            'readiness_snapshot' => 'array',
            'metadata_checked_at' => 'datetime',
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

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
