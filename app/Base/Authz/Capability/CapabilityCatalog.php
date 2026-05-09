<?php
namespace App\Base\Authz\Capability;

use InvalidArgumentException;

final class CapabilityCatalog
{
    /**
     * @var array<int, string>
     */
    private array $domains;

    /**
     * @var array<int, string>
     */
    private array $verbs;

    /**
     * @var array<int, string>
     */
    private array $capabilities;

    /**
     * @param  array<int, string>  $domains
     * @param  array<int, string>  $verbs
     * @param  array<int, string>  $capabilities
     */
    public function __construct(array $domains, array $verbs, array $capabilities)
    {
        $this->domains = array_values(array_unique(array_map('strtolower', $domains)));
        $this->verbs = array_values(array_unique(array_map('strtolower', $verbs)));
        $this->capabilities = array_values(array_unique(array_map('strtolower', $capabilities)));
    }

    /**
     * Create catalog from application configuration.
     */
    public static function fromConfig(array $config): self
    {
        /** @var array<int, string> $domains */
        $domains = array_keys($config['domains'] ?? []);

        /** @var array<int, string> $verbs */
        $verbs = $config['verbs'] ?? [];

        /** @var array<int, string> $capabilities */
        $capabilities = $config['capabilities'] ?? [];

        return new self($domains, $verbs, $capabilities);
    }

    /**
     * @return array<int, string>
     */
    public function domains(): array
    {
        return $this->domains;
    }

    /**
     * @return array<int, string>
     */
    public function verbs(): array
    {
        return $this->verbs;
    }

    /**
     * @return array<int, string>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * Validate catalog against grammar and domain/verb rules.
     */
    public function validate(): void
    {
        foreach ($this->capabilities as $capability) {
            if (! CapabilityKey::isValid($capability)) {
                throw new InvalidArgumentException("Invalid capability key [$capability].");
            }

            $parts = CapabilityKey::parse($capability);

            if (! in_array($parts['domain'], $this->domains, true)) {
                throw new InvalidArgumentException("Unknown capability domain [{$parts['domain']}] for [$capability].");
            }

            if (! in_array($parts['action'], $this->verbs, true)) {
                throw new InvalidArgumentException("Unknown capability verb [{$parts['action']}] for [$capability].");
            }
        }
    }
}
