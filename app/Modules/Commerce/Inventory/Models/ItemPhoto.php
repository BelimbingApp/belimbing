<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $item_id
 * @property string $filename
 * @property string $storage_key
 * @property string|null $mime_type
 * @property int|null $file_size
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Item $item
 */
class ItemPhoto extends Model
{
    protected $table = 'commerce_inventory_item_photos';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'item_id',
        'filename',
        'storage_key',
        'mime_type',
        'file_size',
        'sort_order',
    ];

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
