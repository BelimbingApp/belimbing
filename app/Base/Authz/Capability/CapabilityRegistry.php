<?php
namespace App\Base\Authz\Capability;

use App\Base\Authz\Exceptions\UnknownCapabilityException;

final class CapabilityRegistry
{
    /**
     * @var array<string, true>
     */
    private array $lookup = [];

    /**
     * @param  array<int, string>  $capabilities
     */
    public function __construct(private readonly array $capabilities)
    {
        foreach ($capabilities as $capability) {
            $this->lookup[$capability] = true;
        }
    }

    /**
     * Build a registry from catalog data.
     */
    public static function fromCatalog(CapabilityCatalog $catalog): self
    {
        $catalog->validate();

        return new self($catalog->capabilities());
    }

    /**
     * Determine whether the capability exists in the registry.
     */
    public function has(string $capability): bool
    {
        return isset($this->lookup[strtolower($capability)]);
    }

    /**
     * Assert capability exists in registry.
     */
    public function assertKnown(string $capability): void
    {
        if ($this->has($capability)) {
            return;
        }

        throw UnknownCapabilityException::fromKey($capability);
    }

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        return $this->capabilities;
    }

    /**
     * @return array<int, string>
     */
    public function forDomain(string $domain): array
    {
        $prefix = strtolower($domain).'.';

        return array_values(array_filter(
            $this->capabilities,
            static fn (string $capability): bool => str_starts_with($capability, $prefix)
        ));
    }
}
