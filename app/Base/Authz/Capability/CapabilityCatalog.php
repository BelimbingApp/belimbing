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
     * Capabilities dropped by validate() because they fail the key grammar
     * or reference an unknown domain/verb, keyed by capability with the
     * reason. A malformed entry from one module must not deny access to
     * every other capability app-wide - see validate().
     *
     * @var array<string, string>
     */
    private array $rejected = [];

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
     * Prune capabilities that fail grammar or domain/verb rules instead of
     * throwing, so one module's malformed authz.php entry cannot take the
     * whole app down - every request resolves this catalog to wire the
     * Gate. A dropped capability is simply absent from the registry, so
     * KnownCapabilityPolicy denies any check against it (fails closed,
     * same as a typo'd capability key always has). Rejections are reported
     * so they surface in logs instead of silently vanishing.
     */
    public function validate(): void
    {
        $this->rejected = [];

        $this->capabilities = array_values(array_filter(
            $this->capabilities,
            function (string $capability): bool {
                $reason = $this->invalidReason($capability);

                if ($reason === null) {
                    return true;
                }

                $this->rejected[$capability] = $reason;
                report(new InvalidArgumentException("Capability [$capability] excluded from the registry: $reason."));

                return false;
            }
        ));
    }

    /**
     * Capabilities dropped by the last validate() call, keyed by capability
     * with the rejection reason. Empty when the catalog is entirely clean.
     *
     * @return array<string, string>
     */
    public function rejected(): array
    {
        return $this->rejected;
    }

    private function invalidReason(string $capability): ?string
    {
        if (! CapabilityKey::isValid($capability)) {
            return 'does not match the domain.resource.action grammar';
        }

        $parts = CapabilityKey::parse($capability);

        if (! in_array($parts['domain'], $this->domains, true)) {
            return "unknown domain [{$parts['domain']}]";
        }

        if (! in_array($parts['action'], $this->verbs, true)) {
            return "unknown verb [{$parts['action']}]";
        }

        return null;
    }
}
