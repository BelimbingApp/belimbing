<?php

namespace App\Modules\Commerce\Marketplace\Models;

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Imported marketplace catalog/product reference used as suggestion evidence.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $item_id
 * @property int|null $listing_id
 * @property int|null $listing_draft_id
 * @property string $channel
 * @property string|null $marketplace_id
 * @property string $reference_type
 * @property string $external_product_id
 * @property string $target_key
 * @property string|null $title
 * @property array<string, mixed>|null $facts
 * @property string $source
 * @property string $review_status
 * @property Carbon|null $imported_at
 * @property-read Company $company
 * @property-read Item|null $item
 * @property-read Listing|null $listing
 * @property-read ListingDraft|null $listingDraft
 */
class ProductReference extends Model
{
    public const TYPE_EBAY_EPID = 'ebay_epid';

    public const SOURCE_IMPORTED = 'imported';

    public const SOURCE_CATALOG = 'catalog';

    public const REVIEW_SUGGESTED = 'suggested';

    public const REVIEW_ACCEPTED = 'accepted';

    public const REVIEW_REJECTED = 'rejected';

    protected $table = 'commerce_marketplace_product_references';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'item_id',
        'listing_id',
        'listing_draft_id',
        'channel',
        'marketplace_id',
        'reference_type',
        'external_product_id',
        'target_key',
        'title',
        'facts',
        'source',
        'review_status',
        'imported_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (ProductReference $reference): void {
            $reference->target_key = $reference->targetKey();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'facts' => 'array',
            'imported_at' => 'datetime',
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

    /**
     * @return BelongsTo<ListingDraft, $this>
     */
    public function listingDraft(): BelongsTo
    {
        return $this->belongsTo(ListingDraft::class);
    }

    public function targetKey(): string
    {
        if ($this->listing_draft_id !== null) {
            return 'draft:'.$this->listing_draft_id;
        }

        if ($this->listing_id !== null) {
            return 'listing:'.$this->listing_id;
        }

        if ($this->item_id !== null) {
            return 'item:'.$this->item_id;
        }

        return 'company:'.$this->company_id;
    }
}
