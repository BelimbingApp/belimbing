<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Exceptions\GithubCopilotAuthException;
use App\Modules\Core\AI\Models\AiProvider;

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

        if ($providerName === 'default') {
            return [
                'api_key' => $config['api_key'],
                'base_url' => $config['base_url'],
            ];
        }

        return $this->resolveViaDefinition($this->providerFromConfig($providerName, $config));
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
        } catch (GithubCopilotAuthException $e) {
            return [
                'runtime_error' => AiRuntimeError::fromType(
                    AiErrorType::AuthError,
                    "Provider {$provider->name}: {$e->getMessage()}",
                    'Re-authenticate via the GitHub Copilot device flow.',
                ),
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
     * Build a transient provider model from resolved runtime config.
     *
     * @param  array<string, mixed>  $config
     */
    private function providerFromConfig(string $providerName, array $config): AiProvider
    {
        $providerId = $config['provider_id'] ?? null;

        if (is_numeric($providerId)) {
            $provider = AiProvider::query()->find((int) $providerId);

            if ($provider instanceof AiProvider) {
                return $provider;
            }
        }

        $credentials = $config['credentials'] ?? null;
        if (! is_array($credentials)) {
            $credentials = ['api_key' => (string) ($config['api_key'] ?? '')];
        }

        $connectionConfig = $config['connection_config'] ?? null;
        if (! is_array($connectionConfig)) {
            $connectionConfig = [];
        }

        return new AiProvider([
            'name' => $providerName,
            'base_url' => (string) ($config['base_url'] ?? ''),
            'credentials' => $credentials,
            'connection_config' => $connectionConfig,
        ]);
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
