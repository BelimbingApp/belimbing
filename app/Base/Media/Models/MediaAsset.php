<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Media\Models;

use DateInterval;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Durable record for a stored file on a Laravel filesystem disk.
 *
 * An asset is either an original (parent_id null) or a derivative of another
 * asset (parent_id set). The kind string is open vocabulary chosen by the
 * consumer — for example 'original' or 'background_removed'.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $disk
 * @property string $storage_key
 * @property string|null $original_filename
 * @property string|null $mime_type
 * @property int|null $file_size
 * @property string $kind
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MediaAsset|null $parent
 * @property-read Collection<int, MediaAsset> $derivatives
 */
class MediaAsset extends Model
{
    public const KIND_ORIGINAL = 'original';

    protected $table = 'base_media_assets';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'disk',
        'storage_key',
        'original_filename',
        'mime_type',
        'file_size',
        'kind',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function isOriginal(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Build a short-lived signed URL the browser can fetch directly from
     * the generic media stream route. Default lifetime is 5 minutes; pass
     * an integer (minutes) or a {@see DateInterval} to override.
     */
    public function streamUrl(int|DateInterval $expiresIn = 5): string
    {
        $expires = is_int($expiresIn) ? now()->addMinutes($expiresIn) : now()->add($expiresIn);

        return URL::temporarySignedRoute('media.assets.stream', $expires, ['asset' => $this->id]);
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<MediaAsset, $this>
     */
    public function derivatives(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
