<?php
namespace App\Modules\Core\AI\Services;

use App\Base\AI\Services\GithubCopilotAuthService;
use Illuminate\Cache\Repository as CacheRepository;

/**
 * Orchestrates interactive auth flows during the provider connect wizard.
 *
 * Manages the lifecycle of device flow and other non-trivial auth mechanisms.
 * Sensitive data (device_code, tokens) stays in server-side cache — only UI-safe
 * state (status, user_code, verification_uri) is returned to callers.
 *
 * Each provider key is dispatched to the appropriate auth handler:
 *   - `github-copilot` → GithubCopilotAuthService (device flow)
 *
 * Future candidates:
 *   - Qwen Portal (device flow)
 *   - Chutes, Google Gemini CLI (OAuth redirect — requires callback infrastructure)
 */
class ProviderAuthFlowService
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly GithubCopilotAuthService $githubCopilotAuth,
    ) {}

    /**
     * Start an interactive auth flow for a provider.
     *
     * Returns UI-safe state for the flow. Sensitive data (device_code) is
     * stored in cache, not returned.
     *
     * @param  string  $providerKey  Template key (e.g., 'github-copilot')
     * @param  int  $companyId  Company ID for cache scoping
     * @param  int  $formIndex  Connect form index
     * @return array{status: string, user_code: string|null, verification_uri: string|null, error: string|null}
     */
    public function startFlow(string $providerKey, int $companyId, int $formIndex): array
    {
        return match ($providerKey) {
            'github-copilot' => $this->startGithubDeviceFlow($companyId, $formIndex),
            default => [
                'status' => 'idle',
                'user_code' => null,
                'verification_uri' => null,
                'error' => null,
            ],
        };
    }

    /**
     * Poll an active auth flow for completion.
     *
     * Returns the updated flow state. When status is 'pending', the caller
     * should not update UI state (no change). On 'success', the result
     * includes `api_key` and `base_url` for the connect form. On terminal
     * failure, `error` describes what went wrong.
     *
     * @param  string  $providerKey  Template key
     * @param  int  $companyId  Company ID
     * @param  int  $formIndex  Connect form index
     * @return array{status: string, error: string|null, api_key?: string, base_url?: string}
     */
    public function pollFlow(string $providerKey, int $companyId, int $formIndex): array
    {
        return match ($providerKey) {
            'github-copilot' => $this->pollGithubDeviceFlow($companyId, $formIndex),
            default => ['status' => 'idle', 'error' => null],
        };
    }

    /**
     * Clean up cached auth flow data for the given form indices.
     *
     * @param  int  $companyId  Company ID
     * @param  array<int>  $formIndices  Connect form indices with active flows
     */
    public function cleanupFlows(int $companyId, array $formIndices): void
    {
        foreach ($formIndices as $index) {
            $this->cache->forget($this->cacheKey($companyId, $index));
        }
    }

    /**
     * Start the GitHub OAuth device flow.
     *
     * Requests a device code from GitHub and stores the sensitive device_code
     * in cache. Returns UI-safe state with the user_code and verification_uri.
     *
     * @return array{status: string, user_code: string|null, verification_uri: string|null, error: string|null}
     */
    private function startGithubDeviceFlow(int $companyId, int $formIndex): array
    {
        try {
            $result = $this->githubCopilotAuth->requestDeviceCode();

            $this->cache->put($this->cacheKey($companyId, $formIndex), [
                'device_code' => $result['device_code'],
                'interval' => $result['interval'],
                'expires_at' => now()->addSeconds($result['expires_in'])->timestamp,
            ], $result['expires_in']);

            return [
                'status' => 'pending',
                'user_code' => $result['user_code'],
                'verification_uri' => $result['verification_uri'],
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'user_code' => null,
                'verification_uri' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Poll GitHub for device flow authorization.
     *
     * On success, exchanges the GitHub token for a Copilot API token to derive
     * the base URL. Returns credentials (api_key, base_url) for the connect form.
     *
     * @return array{status: string, error: string|null, api_key?: string, base_url?: string}
     */
    private function pollGithubDeviceFlow(int $companyId, int $formIndex): array
    {
        $cacheKey = $this->cacheKey($companyId, $formIndex);
        $flowData = $this->cache->get($cacheKey);

        if (! is_array($flowData)) {
            return [
                'status' => 'expired',
                'error' => __('Device flow expired. Please try again.'),
            ];
        }

        if (time() >= ($flowData['expires_at'] ?? 0)) {
            $this->cache->forget($cacheKey);

            return [
                'status' => 'expired',
                'error' => __('Device flow expired. Please try again.'),
            ];
        }

        $result = $this->githubCopilotAuth->pollForAccessToken($flowData['device_code']);

        if ($result['status'] === 'success') {
            $this->cache->forget($cacheKey);

            $baseUrl = GithubCopilotAuthService::DEFAULT_BASE_URL;

            try {
                $copilot = $this->githubCopilotAuth->exchangeForCopilotToken($result['token']);
                $baseUrl = $copilot['base_url'];
            } catch (\Exception) {
                // Token exchange may fail on first attempt — proceed with default URL
            }

            return [
                'status' => 'success',
                'error' => null,
                'api_key' => $result['token'],
                'base_url' => $baseUrl,
            ];
        }

        if ($result['status'] !== 'pending') {
            $this->cache->forget($cacheKey);

            return [
                'status' => $result['status'],
                'error' => $result['error'] ?? __('Unknown error'),
            ];
        }

        return ['status' => 'pending', 'error' => null];
    }

    /**
     * Build a cache key for auth flow data.
     */
    private function cacheKey(int $companyId, int $formIndex): string
    {
        return "provider_auth_flow:{$companyId}:{$formIndex}";
    }
}
