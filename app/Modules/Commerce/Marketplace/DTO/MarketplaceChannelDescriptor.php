<?php
namespace App\Modules\Commerce\Marketplace\DTO;

use App\Modules\Commerce\Marketplace\Contracts\MarketplaceChannel;

/**
 * @phpstan-type CapabilityMap array<string, bool>
 */
final readonly class MarketplaceChannelDescriptor
{
    /**
     * @param  class-string<MarketplaceChannel>  $channelClass
     * @param  array<string, bool>  $capabilities
     * @param  array<string, string|null>  $routes
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $channelClass,
        public array $capabilities = [],
        public array $routes = [],
        public ?string $settingsGroup = null,
        public ?string $icon = null,
    ) {}

    public function supports(string $operation): bool
    {
        return (bool) ($this->capabilities[$operation] ?? false);
    }
}
