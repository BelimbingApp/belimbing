<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\OpenAiCodexAuth;

use App\Modules\Core\AI\Models\AiProvider;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Codex OAuth (ChatGPT subscription) auth manager.
 *
 * Mirrors the Codex CLI flow shape:
 * - PKCE browser authorization
 * - code exchange at auth.openai.com
 * - account_id derived from JWT claim payload
 *
 * Trust boundary: this integration is compatibility-based and may break without notice.
 */
final class OpenAiCodexAuthManager
{
    // Values observed in the Codex/OpenClaw implementation.
    private const CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';

    private const REDIRECT_URI = 'http://localhost:1455/auth/callback';

    private const AUTHORIZE_URL = 'https://auth.openai.com/oauth/authorize';

    private const TOKEN_URL = 'https://auth.openai.com/oauth/token';

    private const SCOPE = 'openid profile email offline_access';

    private const ORIGINATOR = 'openclaw';

    private const DEFAULT_JWT_CLAIM_PATH = 'https://api.openai.com/auth';

    private const CACHE_TTL_SECONDS = 600;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly OpenAiCodexAuthStorage $storage,
    ) {}

    /**
     * Start a browser PKCE login and return the authorize URL.
     *
     * @return array{authorize_url: string, state: string}
     */
    public function startLogin(AiProvider $provider): array
    {
        $state = bin2hex(random_bytes(16));
        $verifier = $this->base64UrlEncode(random_bytes(32));
        $challenge = $this->pkceChallenge($verifier);

        $this->cache->put($this->cacheKey($state), [
            'provider_id' => $provider->id,
            'company_id' => $provider->company_id,
            'verifier' => $verifier,
            'redirect_uri' => self::REDIRECT_URI,
            'created_at' => now()->toIso8601String(),
        ], self::CACHE_TTL_SECONDS);

        $this->storage->markPending($provider, mode: 'browser_pkce');

        $query = [
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => self::SCOPE,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
            'id_token_add_organizations' => 'true',
            'codex_cli_simplified_flow' => 'true',
            'originator' => self::ORIGINATOR,
        ];

        return [
            'authorize_url' => self::AUTHORIZE_URL.'?'.http_build_query($query),
            'state' => $state,
        ];
    }

    public function redirectUri(): string
    {
        return self::REDIRECT_URI;
    }

    /**
     * Handle the OAuth callback and persist refreshed credentials.
     *
     * @return AiProvider The updated provider record
     */
    public function completeCallback(Request $request): AiProvider
    {
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        return $this->completeAuthorization($code, $state);
    }

    public function completeManualInput(string $input): AiProvider
    {
        $parsed = $this->parseAuthorizationInput($input);
        $state = $parsed['state'] ?? '';
        $code = $parsed['code'] ?? '';

        if ($code === '' || $state === '') {
            throw new OpenAiCodexOAuthException(
                'callback_validation_failed',
                'Paste the full redirect URL from http://localhost:1455/auth/callback so Belimbing can read both code and state.',
            );
        }

        return $this->completeAuthorization($code, $state);
    }

    /**
     * @return AiProvider The updated provider record
     */
    private function completeAuthorization(string $code, string $state): AiProvider
    {
        if ($state === '' || $code === '') {
            throw new OpenAiCodexOAuthException('callback_validation_failed', 'Missing authorization code or state.');
        }

        $pending = $this->cache->get($this->cacheKey($state));
        if (! is_array($pending)) {
            throw new OpenAiCodexOAuthException('callback_validation_failed', 'OAuth state expired. Please try again.');
        }

        $providerId = (int) ($pending['provider_id'] ?? 0);
        $provider = AiProvider::query()->find($providerId);
        if (! $provider) {
            throw new OpenAiCodexOAuthException('callback_validation_failed', 'Provider not found for OAuth session.');
        }

        $redirectUri = (string) ($pending['redirect_uri'] ?? '');
        $verifier = (string) ($pending['verifier'] ?? '');

        if ($redirectUri === '' || $verifier === '') {
            $this->storage->markError($provider, 'callback_validation_failed', 'OAuth session data incomplete.');
            throw new OpenAiCodexOAuthException('callback_validation_failed', 'OAuth session data incomplete.');
        }

        try {
            $token = $this->exchangeAuthorizationCode($code, $verifier, $redirectUri);
            $accountId = $this->extractAccountIdFromJwt($token['access_token']);

            $this->storage->persistCredentials($provider, [
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'],
                'expires_at' => $token['expires_at'],
                'account_id' => $accountId,
            ]);
            $this->storage->markConnected($provider);
            $this->cache->forget($this->cacheKey($state));

            return $provider->fresh();
        } catch (OpenAiCodexOAuthException $e) {
            $this->storage->markError($provider, $e->errorCode, $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $this->storage->markError($provider, 'callback_failed', $e->getMessage());
            throw new OpenAiCodexOAuthException('callback_failed', $e->getMessage(), $e);
        }
    }

    /**
     * Disconnect locally (no remote revoke contract assumed yet).
     */
    public function logout(AiProvider $provider): void
    {
        $this->storage->clearCredentials($provider);
        $this->storage->markDisconnected($provider);
    }

    public function refresh(AiProvider $provider): void
    {
        $refreshToken = (string) ($provider->credentials['refresh_token'] ?? '');

        if ($refreshToken === '') {
            $exception = new OpenAiCodexOAuthException('refresh_failed', 'Missing refresh token.');
            $this->storage->markExpired($provider, $exception->errorCode, $exception->getMessage());

            throw $exception;
        }

        try {
            $response = Http::asForm()->post(self::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => self::CLIENT_ID,
            ]);

            if (! $response->successful()) {
                throw new OpenAiCodexOAuthException('refresh_failed', 'Token refresh failed ('.$response->status().').');
            }

            $json = $response->json();
            $access = is_string($json['access_token'] ?? null) ? (string) $json['access_token'] : '';
            $nextRefresh = is_string($json['refresh_token'] ?? null) ? (string) $json['refresh_token'] : '';
            $expiresIn = $json['expires_in'] ?? null;

            if ($access === '' || $nextRefresh === '' || ! is_numeric($expiresIn)) {
                throw new OpenAiCodexOAuthException('refresh_failed', 'Refresh response missing required fields.');
            }

            $expiresAt = now()->addSeconds((int) $expiresIn)->toIso8601String();
            $accountId = $this->extractAccountIdFromJwt($access);

            $this->storage->persistCredentials($provider, [
                'access_token' => $access,
                'refresh_token' => $nextRefresh,
                'expires_at' => $expiresAt,
                'account_id' => $accountId,
            ]);
            $this->storage->markRefreshed($provider);
        } catch (OpenAiCodexOAuthException $e) {
            $this->storage->markExpired($provider, $e->errorCode, $e->getMessage());

            throw $e;
        } catch (\Throwable $e) {
            $this->storage->markError($provider, 'refresh_failed', $e->getMessage());

            throw new OpenAiCodexOAuthException('refresh_failed', $e->getMessage(), $e);
        }
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_at: string}
     */
    private function exchangeAuthorizationCode(string $code, string $verifier, string $redirectUri): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => $redirectUri,
        ]);

        if (! $response->successful()) {
            throw new OpenAiCodexOAuthException('token_exchange_failed', 'Token exchange failed ('.$response->status().').');
        }

        $json = $response->json();
        $access = is_string($json['access_token'] ?? null) ? (string) $json['access_token'] : '';
        $refresh = is_string($json['refresh_token'] ?? null) ? (string) $json['refresh_token'] : '';
        $expiresIn = $json['expires_in'] ?? null;

        if ($access === '' || $refresh === '' || ! is_numeric($expiresIn)) {
            throw new OpenAiCodexOAuthException('token_exchange_failed', 'Token response missing required fields.');
        }

        $expiresAt = now()->addSeconds((int) $expiresIn)->toIso8601String();

        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_at' => $expiresAt,
        ];
    }

    private function extractAccountIdFromJwt(string $jwt): string
    {
        // JWT: header.payload.signature (base64url)
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new OpenAiCodexOAuthException('account_id_failed', 'Access token is not a JWT.');
        }

        $payloadJson = $this->base64UrlDecode($parts[1] ?? '');
        $payload = json_decode($payloadJson, true);

        if (! is_array($payload)) {
            throw new OpenAiCodexOAuthException('account_id_failed', 'Failed to decode token payload.');
        }

        $claimPath = config('services.openai_codex.jwt_claim_path') ?? self::DEFAULT_JWT_CLAIM_PATH;
        $claim = $payload[$claimPath] ?? null;
        $accountId = is_array($claim) ? ($claim['chatgpt_account_id'] ?? null) : null;

        if (! is_string($accountId) || $accountId === '') {
            throw new OpenAiCodexOAuthException('account_id_failed', 'No account ID in token.');
        }

        return $accountId;
    }

    /**
     * Parse a pasted OAuth completion value.
     *
     * Mirrors OpenClaw's manual fallback parsing rules:
     * - full redirect URL
     * - `code#state`
     * - raw query string (`code=...&state=...`)
     *
     * @return array{code?: string, state?: string}
     */
    private function parseAuthorizationInput(string $input): array
    {
        $value = trim($input);

        if ($value === '') {
            return [];
        }

        $code = null;
        $state = null;

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            [$code, $state] = $this->parseCallbackUrl($value);
        } elseif (str_contains($value, '#')) {
            [$code, $state] = $this->parseHashSeparatedInput($value);
        } elseif (str_contains($value, 'code=')) {
            [$code, $state] = $this->parseQueryStringInput($value);
        } else {
            $code = $value;
        }

        $result = [];
        if (is_string($code) && $code !== '') {
            $result['code'] = $code;
        }
        if (is_string($state) && $state !== '') {
            $result['state'] = $state;
        }

        return $result;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function parseCallbackUrl(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return [null, null];
        }

        parse_str($query, $params);

        return [
            is_string($params['code'] ?? null) ? $params['code'] : null,
            is_string($params['state'] ?? null) ? $params['state'] : null,
        ];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function parseHashSeparatedInput(string $value): array
    {
        [$code, $state] = explode('#', $value, 2);

        return [trim($code), trim($state)];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function parseQueryStringInput(string $value): array
    {
        parse_str($value, $params);

        return [
            is_string($params['code'] ?? null) ? $params['code'] : null,
            is_string($params['state'] ?? null) ? $params['state'] : null,
        ];
    }

    private function pkceChallenge(string $verifier): string
    {
        return $this->base64UrlEncode(hash('sha256', $verifier, true));
    }

    private function cacheKey(string $state): string
    {
        return 'openai_codex_oauth:'.$state;
    }

    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $encoded): string
    {
        $encoded = strtr($encoded, '-_', '+/');
        $pad = strlen($encoded) % 4;
        if ($pad !== 0) {
            $encoded .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($encoded, true);

        return $decoded === false ? '' : $decoded;
    }
}
