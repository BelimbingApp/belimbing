<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Catalog\Models;

use App\Modules\Commerce\Catalog\Database\Factories\DescriptionFactory;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $item_id
 * @property int|null $created_by_user_id
 * @property int $version
 * @property string $title
 * @property string $body
 * @property string $source
 * @property bool $is_accepted
 * @property array<string, mixed>|null $metadata
 * @property-read Item $item
 * @property-read User|null $createdByUser
 */
class Description extends Model
{
    use HasFactory;

    public const string SOURCE_MANUAL = 'manual';

    public const string SOURCE_LARA = 'lara';

    protected $table = 'commerce_catalog_descriptions';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'item_id',
        'created_by_user_id',
        'version',
        'title',
        'body',
        'source',
        'is_accepted',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_accepted' => 'boolean',
            'metadata' => 'array',
        ];
    }

    protected static function newFactory(): DescriptionFactory
    {
        return new DescriptionFactory;
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
