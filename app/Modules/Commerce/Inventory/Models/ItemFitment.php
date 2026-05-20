<?php

namespace App\Modules\Commerce\Inventory\Models;

use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vehicle/application compatibility entry for a sellable inventory item.
 *
 * @property int $id
 * @property int $company_id
 * @property int $item_id
 * @property string|null $channel
 * @property string|null $marketplace_id
 * @property string|null $category_tree_id
 * @property string|null $category_id
 * @property bool $is_universal
 * @property array<string, string>|null $compatibility_properties
 * @property string|null $display_year
 * @property string|null $display_make
 * @property string|null $display_model
 * @property string|null $display_trim
 * @property string|null $display_engine
 * @property string $source
 * @property string $confidence
 * @property string|null $notes
 * @property-read Company $company
 * @property-read Item $item
 */
class ItemFitment extends Model
{
    public const SOURCE_OPERATOR = 'operator';

    public const SOURCE_IMPORTED = 'imported';

    public const SOURCE_CATALOG = 'catalog';

    public const CONFIDENCE_SELLER_CONFIRMED = 'seller_confirmed';

    public const CONFIDENCE_SUGGESTED = 'suggested';

    public const CONFIDENCE_UNKNOWN = 'unknown';

    protected $table = 'commerce_inventory_item_fitments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'item_id',
        'channel',
        'marketplace_id',
        'category_tree_id',
        'category_id',
        'is_universal',
        'compatibility_properties',
        'display_year',
        'display_make',
        'display_model',
        'display_trim',
        'display_engine',
        'source',
        'confidence',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_universal' => 'boolean',
            'compatibility_properties' => 'array',
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

    public function displayLabel(): string
    {
        if ($this->is_universal) {
            return 'Universal fit';
        }

        return collect([
            $this->display_year,
            $this->display_make,
            $this->display_model,
            $this->display_trim,
            $this->display_engine,
        ])->filter()->implode(' ');
    }
}
