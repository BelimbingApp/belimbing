<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Catalog\Models;

use App\Modules\Commerce\Catalog\Database\Factories\AttributeFactory;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int|null $category_id
 * @property int|null $product_template_id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property bool $is_required
 * @property array<int|string, mixed>|null $options
 * @property int $sort_order
 * @property-read Company $company
 * @property-read Category|null $category
 * @property-read ProductTemplate|null $productTemplate
 * @property-read Collection<int, AttributeValue> $values
 */
class Attribute extends Model
{
    use HasFactory;

    public const string TYPE_TEXT = 'text';

    public const string TYPE_NUMBER = 'number';

    public const string TYPE_BOOLEAN = 'boolean';

    public const string TYPE_SELECT = 'select';

    protected $table = 'commerce_catalog_attributes';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'category_id',
        'product_template_id',
        'code',
        'name',
        'type',
        'is_required',
        'options',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'options' => 'array',
        ];
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_TEXT,
            self::TYPE_NUMBER,
            self::TYPE_BOOLEAN,
            self::TYPE_SELECT,
        ];
    }

    protected static function newFactory(): AttributeFactory
    {
        return new AttributeFactory;
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
     * @return HasMany<AttributeValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class, 'attribute_id');
    }
}
