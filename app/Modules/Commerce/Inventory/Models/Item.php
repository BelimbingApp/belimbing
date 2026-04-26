<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Inventory\Models;

use App\Modules\Commerce\Inventory\Database\Factories\ItemFactory;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Sellable inventory item.
 *
 * @property int $id
 * @property int $company_id
 * @property string $sku
 * @property string $status
 * @property string $title
 * @property string|null $description
 * @property int|null $unit_cost_amount
 * @property int|null $target_price_amount
 * @property string $currency_code
 * @property array<string, mixed>|null $attributes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
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
        'sku',
        'status',
        'title',
        'description',
        'unit_cost_amount',
        'target_price_amount',
        'currency_code',
        'attributes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attributes' => 'array',
        ];
    }

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
}
