<?php

namespace App\Modules\Commerce\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Cached metadata from marketplace APIs.
 *
 * @property int $id
 * @property string $channel
 * @property string $environment
 * @property string|null $marketplace_id
 * @property string $kind
 * @property string $key
 * @property array<string, mixed> $payload
 * @property string|null $etag
 * @property Carbon $fetched_at
 * @property Carbon|null $expires_at
 */
class MarketplaceMetadata extends Model
{
    public const REFRESH_STATE_FRESH = 'fresh';

    public const REFRESH_STATE_STALE = 'stale';

    protected $table = 'commerce_marketplace_metadata';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'channel',
        'environment',
        'marketplace_id',
        'kind',
        'key',
        'payload',
        'etag',
        'fetched_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'fetched_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function isFresh(?Carbon $now = null): bool
    {
        return $this->expires_at === null || $this->expires_at->greaterThan($now ?? Carbon::now());
    }

    public function isStale(?Carbon $now = null): bool
    {
        return ! $this->isFresh($now);
    }

    public function refreshState(?Carbon $now = null): string
    {
        return $this->isFresh($now) ? self::REFRESH_STATE_FRESH : self::REFRESH_STATE_STALE;
    }
}
