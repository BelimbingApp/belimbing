<?php

namespace App\Base\Tenancy\Services;

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

    private function isAllowlisted(string $name): bool
    {
        $allowlist = config('domain_commands.tenant_scope.allowlist', []);
        if (! is_array($allowlist)) {
            return false;
        }

        $reason = $allowlist[$name] ?? null;

        return is_string($reason) && trim($reason) !== '';
    }
}
