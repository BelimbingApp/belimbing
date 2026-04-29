<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Integration\Services;

use App\Base\Foundation\Exceptions\BlbIntegrationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OAuth2Client
{
    /**
     * @param  list<string>  $scopes
     * @param  array<string, string>  $extra
     */
    public function authorizationUrl(
        string $authorizationEndpoint,
        string $clientId,
        string $redirectUri,
        array $scopes,
        ?string $state = null,
        array $extra = [],
    ): string {
        $query = array_filter([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => $state ?? Str::random(40),
        ] + $extra, static fn ($value): bool => $value !== null && $value !== '');

        return $authorizationEndpoint.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeAuthorizationCode(
        string $tokenEndpoint,
        string $clientId,
        string $clientSecret,
        string $code,
        string $redirectUri,
    ): array {
        return $this->tokenRequest($tokenEndpoint, $clientId, $clientSecret, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * @param  list<string>  $scopes
     * @return array<string, mixed>
     */
    public function refreshAccessToken(
        string $tokenEndpoint,
        string $clientId,
        string $clientSecret,
        string $refreshToken,
        array $scopes = [],
    ): array {
        $payload = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ];

        if ($scopes !== []) {
            $payload['scope'] = implode(' ', $scopes);
        }

        return $this->tokenRequest($tokenEndpoint, $clientId, $clientSecret, $payload);
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    private function tokenRequest(string $tokenEndpoint, string $clientId, string $clientSecret, array $payload): array
    {
        $response = Http::asForm()
            ->acceptJson()
            ->withBasicAuth($clientId, $clientSecret)
            ->timeout(30)
            ->post($tokenEndpoint, $payload);

        if (! $response->successful()) {
            throw new BlbIntegrationException(
                'OAuth token request failed.',
                context: [
                    'endpoint' => $tokenEndpoint,
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ],
            );
        }

        return $response->json();
    }
}
