<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Definitions;

use App\Base\AI\Services\GithubCopilotAuthService;
use App\Modules\Core\AI\Contracts\ProviderDefinition;
use App\Modules\Core\AI\Enums\AuthType;
use App\Modules\Core\AI\Enums\ProviderOperation;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Values\ProviderField;
use App\Modules\Core\AI\Values\ResolvedProviderConfig;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Definition for GitHub Copilot (device flow + token exchange).
 *
 * Setup: device flow obtains a GitHub OAuth token stored as the api_key.
 * Runtime: exchanges the stored token for a short-lived Copilot API token.
 */
final readonly class GithubCopilotDefinition implements ProviderDefinition
{
    public function __construct(
        private GithubCopilotAuthService $copilotAuth,
    ) {}

    public function key(): string
    {
        return 'github-copilot';
    }

    public function authType(): AuthType
    {
        return AuthType::DeviceFlow;
    }

    public function defaultBaseUrl(): string
    {
        return 'https://api.individual.githubcopilot.com';
    }

    /**
     * @return list<ProviderField>
     */
    public function editorFields(ProviderOperation $operation): array
    {
        // Device flow providers don't have manual credential entry fields.
        // The API key is obtained through the interactive device flow.
        return [
            ProviderField::text('base_url', __('Base URL'))
                ->requiredOn(ProviderOperation::Create, ProviderOperation::Edit),
            ProviderField::secret('api_key', __('API Key'))
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
            'auth_type' => AuthType::DeviceFlow,
            'connection_config' => [],
        ];

        $apiKey = $validated['api_key'] ?? null;

        if ($apiKey !== null && $apiKey !== '') {
            $result['credentials'] = ['api_key' => $apiKey];
        }

        return $result;
    }

    /**
     * Exchanges the stored GitHub OAuth token for a short-lived Copilot API token.
     *
     * The Copilot token exchange may also update the effective base URL.
     */
    public function resolveRuntime(AiProvider $provider): ResolvedProviderConfig
    {
        $storedToken = $provider->credentials['api_key'] ?? '';

        $copilot = $this->copilotAuth->exchangeForCopilotToken($storedToken);

        return new ResolvedProviderConfig(
            baseUrl: $copilot['base_url'] ?? $provider->base_url,
            apiKey: $copilot['token'],
        );
    }

    public function discoverModels(AiProvider $provider): ?array
    {
        return null;
    }
}
