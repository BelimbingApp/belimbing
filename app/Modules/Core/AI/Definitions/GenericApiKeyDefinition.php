<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Definitions;

use App\Modules\Core\AI\Contracts\ProviderDefinition;
use App\Modules\Core\AI\Enums\AuthType;
use App\Modules\Core\AI\Enums\ProviderOperation;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Values\ProviderField;
use App\Modules\Core\AI\Values\ResolvedProviderConfig;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Configurable definition for standard OpenAI-compatible API-key providers.
 *
 * Handles ~35 providers (OpenAI, Anthropic, Google, xAI, Mistral, etc.)
 * through constructor parameters. No subclassing needed.
 */
final readonly class GenericApiKeyDefinition implements ProviderDefinition
{
    public function __construct(
        private string $providerKey,
        private string $defaultUrl = '',
    ) {}

    public function key(): string
    {
        return $this->providerKey;
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function defaultBaseUrl(): string
    {
        return $this->defaultUrl;
    }

    /**
     * @return list<ProviderField>
     */
    public function editorFields(ProviderOperation $operation): array
    {
        return [
            ProviderField::text('base_url', __('Base URL'), __('e.g. https://api.openai.com/v1'))
                ->requiredOn(ProviderOperation::Create, ProviderOperation::Edit),
            ProviderField::secret('api_key', __('API Key'), __('Paste your API key'))
                ->requiredOn(ProviderOperation::Create),
        ];
    }

    /**
     * @throws ValidationException
     */
    public function validateAndNormalize(array $input, ProviderOperation $operation): array
    {
        $rules = [
            'base_url' => ['required', 'string', 'max:2048'],
            'api_key' => $operation === ProviderOperation::Create
                ? ['required', 'string', 'max:2048']
                : ['nullable', 'string', 'max:2048'],
        ];

        $validated = Validator::make($input, $rules)->validate();

        $result = [
            'base_url' => $validated['base_url'],
            'auth_type' => AuthType::ApiKey,
            'connection_config' => [],
        ];

        $apiKey = $validated['api_key'] ?? null;

        if ($apiKey !== null && $apiKey !== '') {
            $result['credentials'] = ['api_key' => $apiKey];
        }

        return $result;
    }

    public function resolveRuntime(AiProvider $provider): ResolvedProviderConfig
    {
        return new ResolvedProviderConfig(
            baseUrl: $provider->base_url,
            apiKey: $provider->credentials['api_key'] ?? null,
        );
    }

    public function discoverModels(AiProvider $provider): ?array
    {
        return null;
    }
}
