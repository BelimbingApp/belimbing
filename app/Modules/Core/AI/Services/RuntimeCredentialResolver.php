<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

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
     * @param  array{api_key: string, base_url: string, provider_name: string|null}  $config
     * @return array{api_key: string, base_url: string}|array{error: string, error_type: string}
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
     * @param  array{api_key: string, base_url: string, provider_name: string|null}  $config
     * @return array{api_key: string, base_url: string}|array{error: string, error_type: string}
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
                    'error' => __('Copilot token exchange failed: :error', ['error' => $e->getMessage()]),
                    'error_type' => 'auth_error',
                ];
            }
        }

        if ($config['provider_name'] === 'copilot-proxy') {
            $connectivityError = $this->checkLocalConnectivity($baseUrl);

            if ($connectivityError !== null) {
                return $connectivityError;
            }
        }

        return ['api_key' => $apiKey, 'base_url' => $baseUrl];
    }

    /**
     * Verify a local provider endpoint is reachable by probing its /models listing.
     *
     * @return array{error: string, error_type: string}|null
     */
    private function checkLocalConnectivity(string $baseUrl): ?array
    {
        try {
            $response = Http::timeout(5)
                ->get(rtrim($baseUrl, '/').'/models');

            if ($response->failed()) {
                return [
                    'error' => __('Copilot Proxy at :url returned HTTP :status. Ensure the proxy extension is running in VS Code.', [
                        'url' => $baseUrl,
                        'status' => $response->status(),
                    ]),
                    'error_type' => 'connection_error',
                ];
            }
        } catch (ConnectionException) {
            return [
                'error' => __('Could not connect to Copilot Proxy at :url — is the VS Code extension running?', [
                    'url' => $baseUrl,
                ]),
                'error_type' => 'connection_error',
            ];
        }

        return null;
    }

    /**
     * @param  array{api_key: string, base_url: string, provider_name: string|null}  $config
     * @return array{error: string, error_type: string}|null
     */
    private function configurationError(array $config): ?array
    {
        if (empty($config['api_key'])) {
            return [
                'error' => __('API key is not configured for provider :provider.', [
                    'provider' => $config['provider_name'] ?? 'default',
                ]),
                'error_type' => 'config_error',
            ];
        }

        if (empty($config['base_url'])) {
            return [
                'error' => __('Base URL is not configured for provider :provider.', [
                    'provider' => $config['provider_name'] ?? 'default',
                ]),
                'error_type' => 'config_error',
            ];
        }

        return null;
    }
}
