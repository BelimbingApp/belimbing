<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Integration\Services;

use App\Base\Foundation\Exceptions\BlbIntegrationException;
use Illuminate\Support\Str;

class OAuth2Client
{
    public function __construct(
        private readonly IntegrationGateway $integration,
    ) {}

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
        string $system = 'oauth2',
        ?string $provider = null,
        ?string $ownerType = null,
        ?int $ownerId = null,
        array $metadata = [],
    ): array {
        return $this->tokenRequest(
            tokenEndpoint: $tokenEndpoint,
            clientId: $clientId,
            clientSecret: $clientSecret,
            payload: [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ],
            operation: 'oauth2.authorization_code.exchange',
            system: $system,
            provider: $provider,
            ownerType: $ownerType,
            ownerId: $ownerId,
            metadata: $metadata,
        );
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
        string $system = 'oauth2',
        ?string $provider = null,
        ?string $ownerType = null,
        ?int $ownerId = null,
        array $metadata = [],
    ): array {
        $payload = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ];

        if ($scopes !== []) {
            $payload['scope'] = implode(' ', $scopes);
        }

        return $this->tokenRequest(
            tokenEndpoint: $tokenEndpoint,
            clientId: $clientId,
            clientSecret: $clientSecret,
            payload: $payload,
            operation: 'oauth2.refresh_token.exchange',
            system: $system,
            provider: $provider,
            ownerType: $ownerType,
            ownerId: $ownerId,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    private function tokenRequest(
        string $tokenEndpoint,
        string $clientId,
        string $clientSecret,
        array $payload,
        string $operation,
        string $system,
        ?string $provider,
        ?string $ownerType,
        ?int $ownerId,
        array $metadata,
    ): array {
        $response = $this->integration->send(new IntegrationRequest(
            system: $system,
            operation: $operation,
            method: 'POST',
            endpoint: $tokenEndpoint,
            protocol: 'oauth2',
            protocolOperation: 'POST token',
            provider: $provider ?? $this->providerNameFromEndpoint($tokenEndpoint),
            body: $payload,
            ownerType: $ownerType,
            ownerId: $ownerId,
            timeoutSeconds: 30,
            asJson: false,
            asForm: true,
            basicAuth: [$clientId, $clientSecret],
            metadata: $metadata,
        ));

        if (! $response->successful()) {
            throw new BlbIntegrationException(
                'OAuth token request failed.',
                context: [
                    'endpoint' => $tokenEndpoint,
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                    'exchange_id' => $response->exchange?->id,
                ],
            );
        }

        return $response->json();
    }

    private function providerNameFromEndpoint(string $tokenEndpoint): ?string
    {
        $host = parse_url($tokenEndpoint, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
