<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\Enums\AiErrorType;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Values\ResolvedProviderConfig;

/**
 * Resolves API credentials for runtime calls by dispatching through provider definitions.
 *
 * Each provider's definition owns its credential transformation logic
 * (e.g. token exchange for GitHub Copilot, connectivity probes for local providers).
 */
class RuntimeCredentialResolver
{
    public function __construct(
        private readonly ProviderDefinitionRegistry $registry,
    ) {}

    /**
     * Resolve API credentials for a runtime request.
     *
     * @param  array<string, mixed>  $config  Provider config with api_key, base_url, provider_name
     * @return array{api_key: string, base_url: string}|array{runtime_error: AiRuntimeError}
     */
    public function resolve(array $config): array
    {
        $configurationError = $this->configurationError($config);

        if ($configurationError !== null) {
            return $configurationError;
        }

        return $this->resolveCredentials($config);
    }

    /**
     * Resolve credentials by dispatching to the provider's definition.
     *
     * @param  array<string, mixed>  $config
     * @return array{api_key: string, base_url: string}|array{runtime_error: AiRuntimeError}
     */
    private function resolveCredentials(array $config): array
    {
        $providerName = $config['provider_name'] ?? 'default';

        // Load the provider record to dispatch through its definition
        $provider = $this->findProvider($providerName, $config);

        if ($provider !== null) {
            return $this->resolveViaDefinition($provider);
        }

        // Fallback for configs without a matching DB record (e.g. manual overrides)
        return [
            'api_key' => $config['api_key'],
            'base_url' => $config['base_url'],
        ];
    }

    /**
     * Resolve credentials through the provider's definition.
     *
     * @return array{api_key: string, base_url: string}|array{runtime_error: AiRuntimeError}
     */
    private function resolveViaDefinition(AiProvider $provider): array
    {
        $definition = $this->registry->for($provider->name);

        try {
            $resolved = $definition->resolveRuntime($provider);

            return [
                'api_key' => $resolved->apiKey ?? '',
                'base_url' => $resolved->baseUrl,
            ];
        } catch (\RuntimeException $e) {
            return [
                'runtime_error' => AiRuntimeError::fromType(
                    AiErrorType::ConnectionError,
                    "Provider {$provider->name}: {$e->getMessage()}",
                ),
            ];
        }
    }

    /**
     * Find the provider record by name for definition dispatch.
     *
     * @param  array<string, mixed>  $config
     */
    private function findProvider(string $providerName, array $config): ?AiProvider
    {
        if ($providerName === 'default') {
            return null;
        }

        // Try to find the provider by name — the config carries the provider_name
        // but may not carry the DB record. Look it up for definition dispatch.
        return AiProvider::query()
            ->where('name', $providerName)
            ->where('base_url', $config['base_url'] ?? '')
            ->active()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{runtime_error: AiRuntimeError}|null
     */
    private function configurationError(array $config): ?array
    {
        $providerName = $config['provider_name'] ?? 'default';

        // Local providers may not require an API key
        $definition = $this->registry->for($providerName);

        if (! $definition->authType()->requiresApiKey()) {
            // Only base_url is required for local/keyless providers
            if (empty($config['base_url'])) {
                return [
                    'runtime_error' => AiRuntimeError::fromType(
                        AiErrorType::ConfigError,
                        "Base URL is not configured for provider {$providerName}",
                    ),
                ];
            }

            return null;
        }

        if (empty($config['api_key'])) {
            return [
                'runtime_error' => AiRuntimeError::fromType(
                    AiErrorType::ConfigError,
                    "API key is not configured for provider {$providerName}",
                ),
            ];
        }

        if (empty($config['base_url'])) {
            return [
                'runtime_error' => AiRuntimeError::fromType(
                    AiErrorType::ConfigError,
                    "Base URL is not configured for provider {$providerName}",
                ),
            ];
        }

        return null;
    }
}
