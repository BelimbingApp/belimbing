<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Values;

/**
 * Resolved provider configuration consumed by runtime services.
 *
 * Replaces the untyped `['api_key' => ..., 'base_url' => ...]` arrays
 * previously threaded through ConfigResolver → RuntimeCredentialResolver → AgentRuntime.
 */
final readonly class ResolvedProviderConfig
{
    /**
     * @param  string  $baseUrl  Effective API endpoint
     * @param  string|null  $apiKey  Primary API key (null for keyless providers)
     * @param  array<string, string>  $headers  Additional headers required by the provider
     * @param  array<string, mixed>  $metadata  Provider-specific runtime metadata (e.g. token expiry)
     */
    public function __construct(
        public string $baseUrl,
        public ?string $apiKey = null,
        public array $headers = [],
        public array $metadata = [],
    ) {}
}
