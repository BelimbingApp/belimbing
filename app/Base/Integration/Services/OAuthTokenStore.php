<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Integration\Services;

use App\Base\Integration\Models\OAuthToken;
use App\Base\Settings\DTO\Scope;
use Illuminate\Support\Carbon;

class OAuthTokenStore
{
    public function find(string $provider, Scope $scope, string $accountKey = 'default'): ?OAuthToken
    {
        return OAuthToken::query()
            ->where('provider', $provider)
            ->where('account_key', $accountKey)
            ->where('scope_type', $scope->type->value)
            ->where('scope_id', $scope->id)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $tokenPayload
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $metadata
     */
    public function persist(
        string $provider,
        Scope $scope,
        array $tokenPayload,
        array $scopes,
        string $accountKey = 'default',
        array $metadata = [],
    ): OAuthToken {
        $expiresIn = isset($tokenPayload['expires_in']) ? (int) $tokenPayload['expires_in'] : null;

        return OAuthToken::query()->updateOrCreate(
            [
                'provider' => $provider,
                'account_key' => $accountKey,
                'scope_type' => $scope->type->value,
                'scope_id' => $scope->id,
            ],
            [
                'access_token' => $tokenPayload['access_token'] ?? null,
                'refresh_token' => $tokenPayload['refresh_token'] ?? $this->find($provider, $scope, $accountKey)?->refresh_token,
                'expires_at' => $expiresIn !== null ? Carbon::now()->addSeconds($expiresIn) : null,
                'scopes' => $scopes,
                'metadata' => $metadata,
                'last_refreshed_at' => Carbon::now(),
            ],
        );
    }
}
