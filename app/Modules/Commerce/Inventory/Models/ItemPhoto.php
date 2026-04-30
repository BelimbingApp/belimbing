<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Inventory\Models;

use App\Base\Media\Models\MediaAsset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pivot row that orders an item's photos and points at the underlying
 * {@see MediaAsset}. File metadata (filename, disk, storage_key, mime_type,
 * file_size) lives on the asset; this row only owns the inventory-side
 * relationship and ordering.
 *
 * @property int $id
 * @property int $item_id
 * @property int $media_asset_id
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Item $item
 * @property-read MediaAsset $mediaAsset
 */
class ItemPhoto extends Model
{
    protected $table = 'commerce_inventory_item_photos';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'item_id',
        'media_asset_id',
        'sort_order',
    ];

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
