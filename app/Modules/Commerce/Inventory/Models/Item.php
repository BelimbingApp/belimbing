<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Inventory\Models;

use App\Modules\Commerce\Catalog\Models\AttributeValue;
use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Commerce\Catalog\Models\Description;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use App\Modules\Commerce\Inventory\Database\Factories\ItemFactory;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Sellable inventory item.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $category_id
 * @property int|null $product_template_id
 * @property string $sku
 * @property string $status
 * @property string $title
 * @property string|null $notes
 * @property int|null $unit_cost_amount
 * @property int|null $target_price_amount
 * @property string $currency_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read Category|null $category
 * @property-read ProductTemplate|null $productTemplate
 * @property-read Collection<int, ItemPhoto> $photos
 * @property-read Collection<int, AttributeValue> $catalogAttributeValues
 * @property-read Collection<int, Description> $descriptions
 */
class Item extends Model
{
    use HasFactory;

    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_READY = 'ready';

    public const string STATUS_LISTED = 'listed';

    public const string STATUS_SOLD = 'sold';

    public const string STATUS_ARCHIVED = 'archived';

    protected $table = 'commerce_inventory_items';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'category_id',
        'product_template_id',
        'sku',
        'status',
        'title',
        'notes',
        'unit_cost_amount',
        'target_price_amount',
        'currency_code',
    ];

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_READY,
            self::STATUS_LISTED,
            self::STATUS_SOLD,
            self::STATUS_ARCHIVED,
        ];
    }

    protected static function newFactory(): ItemFactory
    {
        return new ItemFactory;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<ProductTemplate, $this>
     */
    public function productTemplate(): BelongsTo
    {
        return $this->belongsTo(ProductTemplate::class);
    }

    /**
     * @return HasMany<ItemPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(ItemPhoto::class, 'item_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<AttributeValue, $this>
     */
    public function catalogAttributeValues(): HasMany
    {
        return $this->hasMany(AttributeValue::class, 'item_id');
    }

    /**
     * @return HasMany<Description, $this>
     */
    public function descriptions(): HasMany
    {
        return $this->hasMany(Description::class, 'item_id')->orderByDesc('version');
    }
}
