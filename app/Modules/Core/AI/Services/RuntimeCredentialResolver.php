<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\GithubCopilotAuthService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Resolves API credentials for runtime calls, including provider-specific exchanges.
 *
 * Beyond credential resolution, performs provider-specific pre-flight checks:
 * - github-copilot: exchanges device token for a Copilot API token
 * - copilot-proxy: verifies the local proxy server is reachable
 */
class RuntimeCredentialResolver
{
    public function __construct(
        private readonly GithubCopilotAuthService $githubCopilotAuth,
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
     * Resolve provider-specific credentials after basic configuration is validated.
     *
     * @param  array<string, mixed>  $config
     * @return array{api_key: string, base_url: string}|array{runtime_error: AiRuntimeError}
     */
    private function resolveCredentials(array $config): array
    {
        $apiKey = $config['api_key'];
        $baseUrl = $config['base_url'];

        if ($config['provider_name'] === 'github-copilot') {
            try {
                $copilot = $this->githubCopilotAuth->exchangeForCopilotToken($apiKey);
                $apiKey = $copilot['token'];
                $baseUrl = $copilot['base_url'];
            } catch (\RuntimeException $e) {
                return [
                    'runtime_error' => AiRuntimeError::fromType(
                        AiErrorType::AuthError,
                        'Copilot token exchange failed: '.$e->getMessage(),
                        'Re-authenticate via the GitHub Copilot device flow.',
                    ),
                ];
            }
        }

        if ($config['provider_name'] === 'copilot-proxy') {
            $connectivityError = $this->checkLocalConnectivity($baseUrl);

            if ($connectivityError !== null) {
                return ['runtime_error' => $connectivityError];
            }
        }

        return ['api_key' => $apiKey, 'base_url' => $baseUrl];
    }

    /**
     * Verify a local provider endpoint is reachable by probing its /models listing.
     */
    private function checkLocalConnectivity(string $baseUrl): ?AiRuntimeError
    {
        try {
            $response = Http::timeout(5)
                ->get(rtrim($baseUrl, '/').'/models');

            if ($response->failed()) {
                return AiRuntimeError::fromType(
                    AiErrorType::ConnectionError,
                    "Copilot Proxy at {$baseUrl} returned HTTP {$response->status()}",
                    'Ensure the proxy extension is running in VS Code.',
                    httpStatus: $response->status(),
                );
            }
        } catch (ConnectionException) {
            return AiRuntimeError::fromType(
                AiErrorType::ConnectionError,
                "Could not connect to Copilot Proxy at {$baseUrl}",
                'Is the VS Code extension running?',
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{runtime_error: AiRuntimeError}|null
     */
    private function configurationError(array $config): ?array
    {
        $providerName = $config['provider_name'] ?? 'default';

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
