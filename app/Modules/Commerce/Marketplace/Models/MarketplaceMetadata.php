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
}
