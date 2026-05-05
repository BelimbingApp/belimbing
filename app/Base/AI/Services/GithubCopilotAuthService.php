<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services;

use App\Base\AI\Exceptions\GithubCopilotAuthException;
use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use Illuminate\Support\Facades\Cache;

/**
 * GitHub Copilot OAuth device flow and token exchange.
 *
 * Stateless authentication helper — no knowledge of companies, employees,
 * or provider records. Handles the GitHub OAuth device flow to obtain a
 * GitHub token, then exchanges it for a short-lived Copilot API token.
 *
 * Flow:
 *   1. requestDeviceCode() — get device_code + user_code
 *   2. User visits verification_uri, enters user_code
 *   3. pollForAccessToken() — poll until GitHub returns access_token
 *   4. exchangeForCopilotToken() — exchange GitHub token for Copilot API token
 *
 * @see https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps#device-flow
 */
class GithubCopilotAuthService
{
    /**
     * GitHub OAuth App client ID used by Copilot CLI tools.
     *
     * This is the well-known client ID shared across Copilot-compatible tools
     * (OpenClaw, Copilot.vim, etc.). BLB may register its own OAuth App in the
     * future, at which point this should be moved to config.
     */
    private const CLIENT_ID = 'Iv1.b507a08c87ecfe98';

    private const DEVICE_CODE_URL = 'https://github.com/login/device/code';

    private const ACCESS_TOKEN_URL = 'https://github.com/login/oauth/access_token';

    private const COPILOT_TOKEN_URL = 'https://api.github.com/copilot_internal/v2/token';

    public const DEFAULT_BASE_URL = 'https://api.individual.githubcopilot.com';

    public function __construct(
        private readonly ?IntegrationGateway $gateway = null,
    ) {}

    /**
     * Request a device code from GitHub for the OAuth device flow.
     *
     * @return array{device_code: string, user_code: string, verification_uri: string, expires_in: int, interval: int}
     *
     * @throws GithubCopilotAuthException
     */
    public function requestDeviceCode(): array
    {
        $response = $this->integrationGateway()->send(new IntegrationRequest(
            system: 'ai_provider',
            operation: 'ai.github_copilot.device_code.request',
            method: 'POST',
            endpoint: self::DEVICE_CODE_URL,
            protocol: 'oauth2',
            protocolOperation: 'POST device/code',
            provider: 'github-copilot',
            body: [
                'client_id' => self::CLIENT_ID,
                'scope' => 'read:user',
            ],
            asJson: false,
            asForm: true,
        ));

        if (! $response->successful()) {
            throw GithubCopilotAuthException::deviceCodeRequestFailed((int) ($response->status ?? 0), $response->exchange?->id);
        }

        $data = $response->json();

        if (empty($data['device_code']) || empty($data['user_code']) || empty($data['verification_uri'])) {
            throw GithubCopilotAuthException::missingDeviceCodeFields();
        }

        return [
            'device_code' => $data['device_code'],
            'user_code' => $data['user_code'],
            'verification_uri' => $data['verification_uri'],
            'expires_in' => (int) ($data['expires_in'] ?? 900),
            'interval' => (int) ($data['interval'] ?? 5),
        ];
    }

    /**
     * Poll GitHub for the access token (single attempt).
     *
     * Call this repeatedly at the interval returned by requestDeviceCode().
     * Returns 'pending' while the user hasn't authorized yet.
     *
     * @param  string  $deviceCode  The device_code from requestDeviceCode()
     * @return array{status: string, token?: string, error?: string}
     *                                                               status: 'pending' | 'success' | 'expired' | 'denied' | 'error'
     */
    public function pollForAccessToken(string $deviceCode): array
    {
        $response = $this->integrationGateway()->send(new IntegrationRequest(
            system: 'ai_provider',
            operation: 'ai.github_copilot.device_token.poll',
            method: 'POST',
            endpoint: self::ACCESS_TOKEN_URL,
            protocol: 'oauth2',
            protocolOperation: 'POST oauth/access_token',
            provider: 'github-copilot',
            body: [
                'client_id' => self::CLIENT_ID,
                'device_code' => $deviceCode,
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            ],
            asJson: false,
            asForm: true,
        ));

        if (! $response->successful()) {
            return ['status' => 'error', 'error' => 'HTTP '.$response->status, 'exchange_id' => $response->exchange?->id];
        }

        $data = $response->json();

        if (isset($data['access_token']) && is_string($data['access_token'])) {
            return ['status' => 'success', 'token' => $data['access_token']];
        }

        $error = $data['error'] ?? 'unknown';

        return match ($error) {
            'authorization_pending', 'slow_down' => ['status' => 'pending'],
            'expired_token' => ['status' => 'expired', 'error' => 'Device code expired — start login again'],
            'access_denied' => ['status' => 'denied', 'error' => 'Login was cancelled'],
            default => ['status' => 'error', 'error' => "GitHub device flow error: {$error}"],
        };
    }

    /**
     * Exchange a GitHub access token for a Copilot API token.
     *
     * The Copilot API token is short-lived and must be refreshed periodically.
     * Results are cached until 5 minutes before expiry.
     *
     * @param  string  $githubToken  GitHub access token from device flow
     * @return array{token: string, expires_at: int, base_url: string}
     *
     * @throws GithubCopilotAuthException
     */
    public function exchangeForCopilotToken(string $githubToken): array
    {
        $cacheKey = 'copilot_api_token:'.hash('sha256', $githubToken);
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && isset($cached['expires_at']) && ($cached['expires_at'] - time()) > 300) {
            return $cached;
        }

        $response = $this->integrationGateway()->send(new IntegrationRequest(
            system: 'ai_provider',
            operation: 'ai.github_copilot.copilot_token.exchange',
            method: 'GET',
            endpoint: self::COPILOT_TOKEN_URL,
            provider: 'github-copilot',
            headers: ['Authorization' => 'Bearer '.$githubToken],
            timeoutSeconds: 30,
        ));

        if (! $response->successful()) {
            throw GithubCopilotAuthException::tokenExchangeFailed((int) ($response->status ?? 0), $response->exchange?->id);
        }

        $data = $response->json();
        $token = $data['token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw GithubCopilotAuthException::missingCopilotToken();
        }

        $expiresAt = $this->parseExpiresAt($data['expires_at'] ?? null);
        $baseUrl = $this->deriveCopilotBaseUrl($token);

        $result = [
            'token' => $token,
            'expires_at' => $expiresAt,
            'base_url' => $baseUrl,
        ];

        $ttl = max(1, $expiresAt - time() - 300);
        Cache::put($cacheKey, $result, $ttl);

        return $result;
    }

    /**
     * Derive the Copilot API base URL from a Copilot API token.
     *
     * The token contains semicolon-delimited key=value pairs. The `proxy-ep`
     * key holds the proxy endpoint URL. We convert proxy.* → api.* to get the
     * API base URL.
     *
     * @param  string  $token  Copilot API token
     */
    public function deriveCopilotBaseUrl(string $token): string
    {
        $trimmed = trim($token);

        if ($trimmed === '') {
            return self::DEFAULT_BASE_URL;
        }

        if (preg_match('/(?:^|;)\s*proxy-ep=([^;\s]+)/i', $trimmed, $matches)) {
            $proxyEp = trim($matches[1]);
            $host = (string) preg_replace('#^https?://#', '', $proxyEp);
            $host = (string) preg_replace('/^proxy\./i', 'api.', $host);

            if ($host !== '') {
                return 'https://'.$host;
            }
        }

        return self::DEFAULT_BASE_URL;
    }

    /**
     * Parse the expires_at value from the Copilot token response.
     *
     * GitHub returns a unix timestamp in seconds, but we handle milliseconds
     * defensively.
     *
     * @param  mixed  $expiresAt  Raw expires_at from response
     *
     * @throws GithubCopilotAuthException
     */
    private function parseExpiresAt(mixed $expiresAt): int
    {
        if (is_numeric($expiresAt)) {
            $value = (int) $expiresAt;

            return $value > 10_000_000_000 ? intdiv($value, 1000) : $value;
        }

        throw GithubCopilotAuthException::invalidExpiresAt();
    }

    private function integrationGateway(): IntegrationGateway
    {
        return $this->gateway ?? app(IntegrationGateway::class);
    }
}
