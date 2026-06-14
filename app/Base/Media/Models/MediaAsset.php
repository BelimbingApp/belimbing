<?php

namespace App\Base\Media\Models;

use App\Base\Media\Exceptions\MediaAssetUrlException;
use DateInterval;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Durable record for a media asset. Usually a stored file on a Laravel
 * filesystem disk; an asset on the {@see self::DISK_EXTERNAL} sentinel disk is
 * instead an external link (the bytes live elsewhere, e.g. an eBay-hosted image
 * URL captured during listing adoption) with its URL in metadata.public_url and
 * no streamable stored file.
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

    /**
     * Sentinel disk for assets that are external links (the bytes live elsewhere,
     * e.g. an eBay-hosted image URL captured during listing adoption). Such assets
     * carry the URL in metadata.public_url and have no streamable stored file.
     */
    public const DISK_EXTERNAL = 'external';

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
     * URL to render this asset in an <img>. External (link-only) assets return
     * their stored public URL directly; stored-file assets get a short-lived
     * signed stream URL. Centralizes the choice so views never special-case it.
     */
    public function displayUrl(int|DateInterval $expiresIn = 5): string
    {
        if ($this->disk === self::DISK_EXTERNAL) {
            $publicUrl = data_get($this->metadata, 'public_url');

            // External assets have no stored bytes to stream — the stored URL is
            // the only thing that can render. Require a safe http(s) URL and fail
            // fast otherwise (missing, or an unsafe data:/file: scheme) rather
            // than silently returning a stream URL that can never succeed.
            if (is_string($publicUrl) && preg_match('#^https?://#i', $publicUrl) === 1) {
                return $publicUrl;
            }

            throw MediaAssetUrlException::invalidExternalPublicUrl();
        }

        return $this->streamUrl($expiresIn);
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
