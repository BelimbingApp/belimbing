<?php

namespace App\Base\Tenancy\Services;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Flags Domain Artisan commands that do not extend TenantScopedCommand.
 */
final class DomainCommandTenantAudit
{
    public function __construct(
        private readonly DomainCommandInventory $inventory,
    ) {}

    /**
     * @return list<array{
     *     domain: string,
     *     module: string,
     *     name: string,
     *     class: class-string,
     *     tenant_scoped: bool,
     *     missing: list<string>
     * }>
     */
    public function failures(): array
    {
        $requiredDomains = config('domain_commands.tenant_scope.required_domains', []);
        if (! is_array($requiredDomains) || $requiredDomains === []) {
            return [];
        }

        $required = array_values(array_filter(
            $requiredDomains,
            static fn (mixed $domain): bool => is_string($domain) && $domain !== '',
        ));

        if ($required === []) {
            return [];
        }

        $failures = [];

        foreach ($this->inventory->all() as $row) {
            if (! in_array($row['domain'], $required, true)) {
                continue;
            }

            if ($this->isAllowlisted($row['name'])) {
                continue;
            }

            if ($row['tenant_scoped']) {
                continue;
            }

            $failures[] = [
                ...$row,
                'missing' => ['tenant_scoped'],
            ];
        }

        return $failures;
    }

    /**
     * An entry exempts a command only with a non-blank reason and an
     * expiry date that has not passed. A bare string reason never expires
     * and is accepted only for fixtures; shipped entries are dated.
     */
    private function isAllowlisted(string $name): bool
    {
        $allowlist = config('domain_commands.tenant_scope.allowlist', []);
        if (! is_array($allowlist)) {
            return false;
        }

        $entry = $allowlist[$name] ?? null;

        if (is_string($entry)) {
            return trim($entry) !== '';
        }

        if (! is_array($entry)) {
            return false;
        }

        $reason = $entry['reason'] ?? null;
        $expires = $entry['expires'] ?? null;

        if (! is_string($reason) || trim($reason) === '' || ! is_string($expires)) {
            return false;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) !== 1) {
            return false;
        }

        try {
            $expiry = CarbonImmutable::createFromFormat('Y-m-d', $expires);
        } catch (InvalidFormatException) {
            return false;
        }

        if ($expiry === null || $expiry->format('Y-m-d') !== $expires) {
            return false;
        }

        return ! $expiry->endOfDay()->isPast();
    }
}
