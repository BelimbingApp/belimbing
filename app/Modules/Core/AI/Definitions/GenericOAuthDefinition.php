<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Definitions;

use App\Modules\Core\AI\Contracts\ProviderDefinition;
use App\Modules\Core\AI\Enums\AuthType;
use App\Modules\Core\AI\Enums\ProviderOperation;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Values\ProviderField;
use App\Modules\Core\AI\Values\ProviderOAuthState;
use App\Modules\Core\AI\Values\ResolvedProviderConfig;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Honest generic definition for providers that advertise OAuth but have no dedicated BLB flow yet.
 *
 * This keeps OAuth providers out of the generic API-key path until BLB ships
 * a provider-specific sign-in implementation.
 */
final readonly class GenericOAuthDefinition implements ProviderDefinition
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
        return AuthType::OAuth;
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

        $result = [
            'base_url' => $validated['base_url'],
            'auth_type' => AuthType::OAuth,
        ];

        if ($operation === ProviderOperation::Create) {
            $result['connection_config'] = [
                ProviderOAuthState::CONNECTION_CONFIG_KEY => ProviderOAuthState::defaults(),
            ];
        }

        return $result;
    }

    public function resolveRuntime(AiProvider $provider): ResolvedProviderConfig
    {
        throw new \RuntimeException('This provider requires a dedicated OAuth sign-in flow. BLB does not implement a generic OAuth runtime for it yet.');
    }

    public function discoverModels(AiProvider $provider): ?array
    {
        return [];
    }
}
