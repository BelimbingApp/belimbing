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
 * Definition for Cloudflare AI Gateway.
 *
 * Derives the base URL from Account ID + Gateway ID. Stores structured
 * connection config alongside the upstream provider's API key.
 */
final readonly class CloudflareGatewayDefinition implements ProviderDefinition
{
    public function key(): string
    {
        return 'cloudflare-ai-gateway';
    }

    public function authType(): AuthType
    {
        return AuthType::Custom;
    }

    public function defaultBaseUrl(): string
    {
        return 'https://gateway.ai.cloudflare.com/v1';
    }

    /**
     * @return list<ProviderField>
     */
    public function editorFields(ProviderOperation $operation): array
    {
        return [
            ProviderField::text('account_id', __('Account ID'), __('Cloudflare Account ID'))
                ->requiredOn(ProviderOperation::Create, ProviderOperation::Edit),
            ProviderField::text('gateway_id', __('Gateway ID'), __('AI Gateway name'))
                ->requiredOn(ProviderOperation::Create, ProviderOperation::Edit),
            ProviderField::secret('api_key', __('API Key'), __('Cloudflare API token'))
                ->requiredOn(ProviderOperation::Create),
        ];
    }

    /**
     * @throws ValidationException
     */
    public function validateAndNormalize(array $input, ProviderOperation $operation): array
    {
        $rules = [
            'account_id' => ['required', 'string', 'max:255'],
            'gateway_id' => ['required', 'string', 'max:255'],
            'api_key' => $operation === ProviderOperation::Create
                ? ['required', 'string', 'max:2048']
                : ['nullable', 'string', 'max:2048'],
        ];

        $validated = Validator::make($input, $rules)->validate();

        $accountId = trim($validated['account_id']);
        $gatewayId = trim($validated['gateway_id']);

        $result = [
            'base_url' => "https://gateway.ai.cloudflare.com/v1/{$accountId}/{$gatewayId}/openai",
            'auth_type' => AuthType::Custom,
            'connection_config' => [
                'account_id' => $accountId,
                'gateway_id' => $gatewayId,
            ],
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
