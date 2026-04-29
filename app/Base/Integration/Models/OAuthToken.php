<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Durable OAuth token bundle for integrations.
 *
 * @property int $id
 * @property string $provider
 * @property string $account_key
 * @property string|null $scope_type
 * @property int|null $scope_id
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $expires_at
 * @property Carbon|null $refresh_token_expires_at
 * @property array<int, string>|null $scopes
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $last_refreshed_at
 */
class OAuthToken extends Model
{
    protected $table = 'base_integration_oauth_tokens';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'account_key',
        'scope_type',
        'scope_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'refresh_token_expires_at',
        'scopes',
        'metadata',
        'last_refreshed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'scopes' => 'array',
            'metadata' => 'array',
            'last_refreshed_at' => 'datetime',
        ];
    }

    public function isExpired(int $skewSeconds = 120): bool
    {
        return $this->expires_at === null || $this->expires_at->copy()->subSeconds($skewSeconds)->isPast();
    }
}
