<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Catalog\Models;

use App\Modules\Commerce\Catalog\Database\Factories\ProductTemplateFactory;
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
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property array<string, mixed>|null $metadata
 * @property-read Company $company
 * @property-read Category|null $category
 * @property-read Collection<int, Attribute> $attributes
 */
class ProductTemplate extends Model
{
    use HasFactory;

    protected $table = 'commerce_catalog_product_templates';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'category_id',
        'code',
        'name',
        'description',
        'is_active',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    protected static function newFactory(): ProductTemplateFactory
    {
        return new ProductTemplateFactory;
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
     * @return HasMany<Attribute, $this>
     */
    public function attributes(): HasMany
    {
        return $this->hasMany(Attribute::class, 'product_template_id')->orderBy('sort_order')->orderBy('name');
    }
}
