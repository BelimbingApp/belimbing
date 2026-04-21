<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Definitions;

use App\Modules\Core\AI\Contracts\ProviderDefinition;
use App\Modules\Core\AI\Enums\AuthType;
use App\Modules\Core\AI\Enums\ProviderOperation;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\OpenAiCodexAuth\OpenAiCodexAuthManager;
use App\Modules\Core\AI\Values\ProviderField;
use App\Modules\Core\AI\Values\ResolvedProviderConfig;
use DateTimeImmutable;
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

    // Persisted credential bag keys (encrypted AiProvider.credentials).
    public const CRED_ACCESS_TOKEN = 'access_token';

    public const CRED_REFRESH_TOKEN = 'refresh_token';

    public const CRED_EXPIRES_AT = 'expires_at';

    public const CRED_ACCOUNT_ID = 'account_id';

    /**
     * Connection-config key for durable provider auth state.
     */
    public const AUTH_STATE_KEY = 'auth';

    public function __construct(
        private OpenAiCodexAuthManager $auth,
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
                self::AUTH_STATE_KEY => [
                    'status' => 'disconnected',
                    'mode' => 'browser_pkce',
                    'started_at' => null,
                    'completed_at' => null,
                    'last_refresh_at' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                    'plan_type' => null,
                ],
            ];
        }

        return $normalized;
    }

    public function resolveRuntime(AiProvider $provider): ResolvedProviderConfig
    {
        $accessToken = (string) ($provider->credentials[self::CRED_ACCESS_TOKEN] ?? '');
        $refreshToken = (string) ($provider->credentials[self::CRED_REFRESH_TOKEN] ?? '');
        $expiresAt = (string) ($provider->credentials[self::CRED_EXPIRES_AT] ?? '');

        if ($accessToken === '') {
            throw new \RuntimeException('OpenAI Codex is not connected.');
        }

        if ($expiresAt !== '' && $refreshToken !== '' && $this->isExpiredOrNearExpiry($expiresAt, skewSeconds: 60)) {
            $this->auth->refresh($provider);
            $provider = $provider->fresh();
            $accessToken = (string) ($provider?->credentials[self::CRED_ACCESS_TOKEN] ?? $accessToken);
        }

        return new ResolvedProviderConfig(
            baseUrl: $provider->base_url,
            apiKey: $accessToken,
            headers: [
                // Protocol client will add Authorization: Bearer automatically via apiKey.
                // chatgpt-account-id is derived from JWT in ProviderRequestHeaderResolver.
            ],
            metadata: [
                'provider_family' => 'openai_codex',
                'auth' => is_array($provider->connection_config[self::AUTH_STATE_KEY] ?? null)
                    ? $provider->connection_config[self::AUTH_STATE_KEY]
                    : null,
            ],
        );
    }

    public function discoverModels(AiProvider $provider): ?array
    {
        $models = config('ai.provider_overlay.'.self::KEY.'.curated_models', []);

        if (! is_array($models) || $models === []) {
            return [];
        }

        $result = [];

        foreach ($models as $modelId) {
            if (is_string($modelId) && $modelId !== '') {
                $result[] = [
                    'model_id' => $modelId,
                    'display_name' => $modelId,
                ];
            }
        }

        return $result;
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
