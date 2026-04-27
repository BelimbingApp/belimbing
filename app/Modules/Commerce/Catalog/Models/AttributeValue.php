<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Catalog\Models;

use App\Modules\Commerce\Catalog\Database\Factories\AttributeValueFactory;
use App\Modules\Commerce\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $item_id
 * @property int $attribute_id
 * @property mixed $value
 * @property string|null $display_value
 * @property-read Item $item
 * @property-read Attribute $attribute
 */
class AttributeValue extends Model
{
    use HasFactory;

    protected $table = 'commerce_catalog_attribute_values';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'item_id',
        'attribute_id',
        'value',
        'display_value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    protected static function newFactory(): AttributeValueFactory
    {
        return new AttributeValueFactory;
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Attribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}
