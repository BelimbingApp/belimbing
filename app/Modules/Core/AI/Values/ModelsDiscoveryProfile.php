<?php
namespace App\Modules\Core\AI\Values;

/**
 * Provider-owned HTTP inputs for models discovery.
 */
final readonly class ModelsDiscoveryProfile
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $query
     */
    public function __construct(
        public string $baseUrl,
        public array $headers = [],
        public array $query = [],
    ) {}
}
