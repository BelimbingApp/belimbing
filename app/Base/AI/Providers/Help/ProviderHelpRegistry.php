<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Providers\Help;

/**
 * Returns the appropriate help object for a given provider key and auth type.
 *
 * Resolution order:
 *   1. Provider key (exact match for providers with unique setup).
 *   2. Auth type (generic class for the authentication pattern).
 *   3. DefaultProviderHelp (catch-all).
 */
final class ProviderHelpRegistry
{
    /**
     * Get help content for a provider.
     *
     * @param  string  $providerKey  The provider's unique key (e.g. 'copilot-proxy', 'openai').
     * @param  string|null  $authType  The provider's auth type (e.g. 'local', 'api_key', 'device_flow').
     */
    public function get(string $providerKey, ?string $authType = null): ProviderHelpContract
    {
        return match ($providerKey) {
            'copilot-proxy' => new CopilotProxyHelp,
            'github-copilot' => new GithubCopilotHelp,
            'cloudflare-ai-gateway' => new CloudflareAiGatewayHelp,
            'ollama' => new OllamaHelp,
            'lmstudio' => new LmStudioHelp,
            default => $this->fromAuthType($authType),
        };
    }

    /**
     * Fall back to a generic help class based on auth type.
     */
    private function fromAuthType(?string $authType): ProviderHelpContract
    {
        return match ($authType) {
            'local' => new LocalServerHelp,
            'device_flow' => new DeviceFlowHelp,
            'api_key', 'oauth',
            'subscription', 'custom' => new ApiKeyHelp,
            default => new DefaultProviderHelp,
        };
    }
}
