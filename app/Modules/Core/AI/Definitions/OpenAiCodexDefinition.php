<?php

namespace App\Modules\Core\AI\Definitions;

use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\AI\Contracts\ProviderDefinition;
use App\Modules\Core\AI\Enums\AuthType;
use App\Modules\Core\AI\Enums\ProviderOperation;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\OpenAiCodexAuth\OpenAiCodexAuthManager;
use App\Modules\Core\AI\Services\OpenAiCodexClientVersionResolver;
use App\Modules\Core\AI\Values\ModelsDiscoveryProfile;
use App\Modules\Core\AI\Values\ProviderAdvancedSetting;
use App\Modules\Core\AI\Values\ProviderField;
use App\Modules\Core\AI\Values\ProviderOAuthState;
use App\Modules\Core\AI\Values\ResolvedProviderConfig;
use DateTimeImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Dedicated definition for OpenAI Codex subscription traffic.
 *
 * This is an OAuth/subscription-backed provider. It does NOT use the public
 * OpenAI API key platform, and it uses a separate ChatGPT-backend API family.
 */
final readonly class OpenAiCodexDefinition implements ProviderDefinition
{
    public const KEY = 'openai-codex';

    // Default `client_version` query param for ChatGPT Codex models discovery.
    public const MODELS_DISCOVERY_DEFAULT_CLIENT_VERSION = '0.144.5';

    public const MODELS_DISCOVERY_CLIENT_VERSION_SETTINGS_KEY = 'ai.openai_codex.models_discovery_client_version';

    // Persisted credential bag keys (encrypted AiProvider.credentials).
    public const CRED_ACCESS_TOKEN = 'access_token';

    public const CRED_REFRESH_TOKEN = 'refresh_token';

    public const CRED_EXPIRES_AT = 'expires_at';

    public const CRED_ACCOUNT_ID = 'account_id';

    /**
     * Connection-config key for durable provider auth state.
     */
    public const AUTH_STATE_KEY = ProviderOAuthState::CONNECTION_CONFIG_KEY;

    public function __construct(
        private OpenAiCodexAuthManager $auth,
        private OpenAiCodexClientVersionResolver $clientVersions,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function authType(): AuthType
    {
        return AuthType::OAuth;
    }

    public function defaultBaseUrl(): string
    {
        return 'https://chatgpt.com/backend-api';
    }

    /**
     * @return list<ProviderField>
     */
    public function editorFields(ProviderOperation $operation): array
    {
        // Phase 1 boundary: show the endpoint only. OAuth credentials are handled by a dedicated flow in Phase 2.
        return [
            ProviderField::text('base_url', __('Base URL'))
                ->requiredOn(ProviderOperation::Create, ProviderOperation::Edit),
        ];
    }

    /**
     * @throws ValidationException
     */
    public function validateAndNormalize(array $input, ProviderOperation $operation): array
    {
        $validated = Validator::make($input, [
            'base_url' => ['required', 'string', 'max:2048'],
        ])->validate();

        $normalized = [
            'base_url' => $validated['base_url'],
            'auth_type' => AuthType::OAuth,
        ];

        if ($operation === ProviderOperation::Create) {
            // Durable auth state lives in connection_config; pending OAuth handshake state remains ephemeral.
            $normalized['connection_config'] = [
                self::AUTH_STATE_KEY => ProviderOAuthState::defaults(mode: 'browser_pkce'),
            ];
        }

        return $normalized;
    }

    public function resolveRuntime(AiProvider $provider): ResolvedProviderConfig
    {
        $accessToken = (string) ($provider->credentials[self::CRED_ACCESS_TOKEN] ?? '');
        $refreshToken = (string) ($provider->credentials[self::CRED_REFRESH_TOKEN] ?? '');
        $expiresAt = (string) ($provider->credentials[self::CRED_EXPIRES_AT] ?? '');
        $accountId = (string) ($provider->credentials[self::CRED_ACCOUNT_ID] ?? '');

        if ($accessToken === '') {
            throw new OpenAiCodexRuntimeResolutionException('OpenAI Codex is not connected.');
        }

        if ($expiresAt !== '' && $refreshToken !== '' && $this->isExpiredOrNearExpiry($expiresAt, skewSeconds: 60)) {
            $this->auth->refresh($provider);
            $provider = $provider->fresh() ?? $provider;
            $accessToken = (string) ($provider?->credentials[self::CRED_ACCESS_TOKEN] ?? $accessToken);
            $accountId = (string) ($provider->credentials[self::CRED_ACCOUNT_ID] ?? $accountId);
        }

        if ($accountId === '') {
            throw new OpenAiCodexRuntimeResolutionException('OpenAI Codex is missing the ChatGPT account ID. Reconnect the provider.');
        }

        return new ResolvedProviderConfig(
            baseUrl: $provider->base_url,
            apiKey: $accessToken,
            headers: [
                // BearerAuthProvider inserts "ChatGPT-Account-ID"; HTTP semantics match this spelling.
                'chatgpt-account-id' => $accountId,
            ],
            metadata: [
                'provider_family' => 'openai_codex',
                'auth' => ProviderOAuthState::normalize(
                    $provider->connection_config[self::AUTH_STATE_KEY] ?? null,
                    mode: 'browser_pkce',
                ),
            ],
        );
    }

    public function advancedSettings(): array
    {
        $default = self::MODELS_DISCOVERY_DEFAULT_CLIENT_VERSION;

        return [
            new ProviderAdvancedSetting(
                stateKey: 'modelsDiscoveryClientVersion',
                settingsKey: self::MODELS_DISCOVERY_CLIENT_VERSION_SETTINGS_KEY,
                label: __('Model discovery client version'),
                help: __('Sent as client_version when Belimbing asks the ChatGPT Codex backend for available models. By default Belimbing follows the latest stable Codex CLI release automatically (checked daily); save a value here only to pin a specific version, and use Reset to return to automatic.'),
                inputType: 'text',
                default: $default,
                rules: ['required', 'string', 'max:32'],
            ),
        ];
    }

    public function modelsDiscoveryProfile(AiProvider $provider, ResolvedProviderConfig $resolved): ModelsDiscoveryProfile
    {
        $headers = [];
        foreach ($resolved->headers as $name => $value) {
            if (is_string($name) && $name !== '' && is_string($value) && $value !== '') {
                $headers[$name] = $value;
            }
        }

        $base = rtrim($resolved->baseUrl, '/');

        // BLB keeps chat at …/backend-api + /codex/responses; model list follows …/backend-api/codex/models.
        if (str_ends_with($base, '/backend-api')) {
            $base .= '/codex';
        }

        if (! $this->headersContainName($headers, 'User-Agent')) {
            $headers['User-Agent'] = 'codex-cli';
        }

        // Precedence: operator-pinned setting → latest stable Codex CLI release
        // (cached daily) → shipped constant. The backend filters the model list
        // by client_version, so the constant alone goes stale between releases.
        $clientVersion = $this->pinnedClientVersion()
            ?? $this->clientVersions->latest()
            ?? self::MODELS_DISCOVERY_DEFAULT_CLIENT_VERSION;

        $query = [];
        if ($clientVersion !== '') {
            $query['client_version'] = $clientVersion;
        }

        return new ModelsDiscoveryProfile(baseUrl: $base, headers: $headers, query: $query);
    }

    public function discoverModels(AiProvider $provider): ?array
    {
        return null;
    }

    /**
     * Operator-pinned client_version from settings, or null when unset
     * (the advanced-settings "Reset" removes the key, restoring auto mode).
     */
    private function pinnedClientVersion(): ?string
    {
        if (! Schema::hasTable('base_settings')) {
            return null;
        }

        try {
            $settings = app(SettingsService::class);

            if (! $settings->has(self::MODELS_DISCOVERY_CLIENT_VERSION_SETTINGS_KEY, scope: null)) {
                return null;
            }

            $pinned = $settings->get(self::MODELS_DISCOVERY_CLIENT_VERSION_SETTINGS_KEY, scope: null);

            return is_string($pinned) && $pinned !== '' ? $pinned : null;
        } catch (\Throwable) {
            // Unit tests may not load the settings tables; fall back to auto.
            return null;
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function headersContainName(array $headers, string $needle): bool
    {
        foreach (array_keys($headers) as $name) {
            if (is_string($name) && strcasecmp($name, $needle) === 0) {
                return true;
            }
        }

        return false;
    }

    private function isExpiredOrNearExpiry(string $expiresAt, int $skewSeconds): bool
    {
        try {
            $ts = (new DateTimeImmutable($expiresAt))->getTimestamp();
        } catch (\Exception) {
            return false;
        }

        return time() >= ($ts - $skewSeconds);
    }
}

final class OpenAiCodexRuntimeResolutionException extends \RuntimeException {}
