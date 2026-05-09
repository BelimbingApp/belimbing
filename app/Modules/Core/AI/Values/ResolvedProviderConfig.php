<?php
namespace App\Modules\Core\AI\Values;

/**
 * Resolved provider configuration consumed by runtime services.
 *
 * Replaces the untyped `['api_key' => ..., 'base_url' => ...]` arrays
 * previously threaded through ConfigResolver → RuntimeCredentialResolver → runtime execution layers.
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
