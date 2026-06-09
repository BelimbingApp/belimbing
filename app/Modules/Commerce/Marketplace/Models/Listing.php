<?php

namespace App\Modules\Commerce\Marketplace\Models;

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Database\Factories\ListingFactory;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
 * @property string $management_state
 * @property string $drift_status
 * @property string|null $drift_summary
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
    use HasFactory;

    public const MANAGEMENT_IMPORTED = 'imported';

    public const MANAGEMENT_BELIMBING_MANAGED = 'belimbing_managed';

    public const DRIFT_UNKNOWN = 'unknown';

    public const DRIFT_IN_SYNC = 'in_sync';

    public const DRIFT_DRIFTED = 'drifted';

    public const RECONCILIATION_UNLINKED = 'unlinked';

    public const RECONCILIATION_MANAGED = 'managed';

    public const RECONCILIATION_READY_TO_ADOPT = 'ready_to_adopt';

    public const RECONCILIATION_MISSING_FITMENT = 'missing_fitment';

    public const RECONCILIATION_MISSING_IDENTIFIERS = 'missing_identifiers';

    public const RECONCILIATION_CONFLICTING_IDENTIFIERS = 'conflicting_identifiers';

    public const RECONCILIATION_LEGACY_RELIST_REQUIRED = 'legacy_relist_required';

    public const RECONCILIATION_EXTERNALLY_CHANGED = 'externally_changed';

    public const RECONCILIATION_DRIFTED = 'drifted';

    public const ADOPTION_UNKNOWN = 'unknown';

    public const ADOPTION_INVENTORY_API_ADOPTABLE = 'inventory_api_adoptable';

    public const ADOPTION_LEGACY_RELIST_REQUIRED = 'legacy_relist_required';

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
        'management_state',
        'drift_status',
        'drift_summary',
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

    protected static function newFactory(): ListingFactory
    {
        return new ListingFactory;
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
     * @return HasOne<ListingDraft, $this>
     */
    public function draft(): HasOne
    {
        return $this->hasOne(ListingDraft::class);
    }

    public function isBelimbingManaged(): bool
    {
        return $this->management_state === self::MANAGEMENT_BELIMBING_MANAGED;
    }

    public function isImported(): bool
    {
        return $this->management_state === self::MANAGEMENT_IMPORTED;
    }

    public function isExternallyChanged(): bool
    {
        return $this->isBelimbingManaged() && $this->drift_status === self::DRIFT_DRIFTED;
    }

    public function hasInventoryApiOfferId(): bool
    {
        return $this->external_offer_id !== null && trim($this->external_offer_id) !== '';
    }

    public function hasInventoryItemSnapshot(): bool
    {
        return Str::of((string) data_get($this->raw_payload, 'inventory_item.sku'))->trim()->isNotEmpty();
    }

    public function hasInventoryApiWritePath(): bool
    {
        return $this->hasInventoryApiOfferId() && $this->hasInventoryItemSnapshot();
    }

    public function adoptionState(): string
    {
        if ($this->hasInventoryApiWritePath()) {
            return self::ADOPTION_INVENTORY_API_ADOPTABLE;
        }

        if ($this->external_listing_id !== null && trim($this->external_listing_id) !== '') {
            return self::ADOPTION_LEGACY_RELIST_REQUIRED;
        }

        return self::ADOPTION_UNKNOWN;
    }

    /**
     * The buyer-facing listing body as it currently stands on the marketplace.
     * Imported listings carry it under inventory_item; Belimbing-managed listings
     * keep a copy in the published contract. Returns null when neither is present.
     */
    public function marketplaceDescriptionBody(): ?string
    {
        $body = data_get($this->raw_payload, 'inventory_item.product.description')
            ?? data_get($this->raw_payload, 'publish_contract.inventory_item.product.description');

        return is_string($body) && trim($body) !== '' ? $body : null;
    }
}
