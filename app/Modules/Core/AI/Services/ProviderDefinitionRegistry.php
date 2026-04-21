<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\Services\GithubCopilotAuthService;
use App\Base\AI\Services\ModelCatalogService;
use App\Modules\Core\AI\Contracts\ProviderDefinition;
use App\Modules\Core\AI\Definitions\CloudflareGatewayDefinition;
use App\Modules\Core\AI\Definitions\CopilotProxyDefinition;
use App\Modules\Core\AI\Definitions\GenericApiKeyDefinition;
use App\Modules\Core\AI\Definitions\GenericLocalDefinition;
use App\Modules\Core\AI\Definitions\GenericOAuthDefinition;
use App\Modules\Core\AI\Definitions\GithubCopilotDefinition;
use App\Modules\Core\AI\Definitions\OpenAiCodexDefinition;
use App\Modules\Core\AI\Services\OpenAiCodexAuth\OpenAiCodexAuthManager;

/**
 * Resolves the ProviderDefinition for a given provider key.
 *
 * Builds generic definitions from the catalog overlay for standard providers.
 * Returns dedicated definition classes for outliers (Cloudflare, GitHub Copilot).
 */
class ProviderDefinitionRegistry
{
    /** @var array<string, ProviderDefinition> */
    private array $definitions = [];

    private bool $built = false;

    public function __construct(
        private readonly ModelCatalogService $catalog,
        private readonly GithubCopilotAuthService $copilotAuth,
    ) {}

    /**
     * Resolve the definition for a provider key.
     *
     * Falls back to a generic API key definition if the provider key is
     * not found in the catalog (e.g. manually added providers).
     */
    public function for(string $providerKey): ProviderDefinition
    {
        $this->ensureBuilt();

        return $this->definitions[$providerKey]
            ?? new GenericApiKeyDefinition($providerKey);
    }

    /**
     * Get all registered definitions.
     *
     * @return array<string, ProviderDefinition>
     */
    public function all(): array
    {
        $this->ensureBuilt();

        return $this->definitions;
    }

    /**
     * Build definitions from the catalog overlay on first access.
     */
    private function ensureBuilt(): void
    {
        if ($this->built) {
            return;
        }

        $this->built = true;

        // Register dedicated definitions for outlier providers
        $this->register(new CloudflareGatewayDefinition);
        $this->register(new CopilotProxyDefinition);
        $this->register(new GithubCopilotDefinition($this->copilotAuth));
        $this->register(new OpenAiCodexDefinition(app(OpenAiCodexAuthManager::class)));

        // Build generic definitions from catalog overlay
        $providers = $this->catalog->getProviders();

        foreach ($providers as $key => $template) {
            if (isset($this->definitions[$key])) {
                continue; // Dedicated definition already registered
            }

            $authType = $template['auth_type'] ?? 'api_key';
            $baseUrl = $template['base_url'] ?? '';

            $this->definitions[$key] = match ($authType) {
                'local' => new GenericLocalDefinition($key, $baseUrl),
                'oauth' => new GenericOAuthDefinition($key, $baseUrl),
                default => new GenericApiKeyDefinition($key, $baseUrl),
            };
        }
    }

    private function register(ProviderDefinition $definition): void
    {
        $this->definitions[$definition->key()] = $definition;
    }
}
