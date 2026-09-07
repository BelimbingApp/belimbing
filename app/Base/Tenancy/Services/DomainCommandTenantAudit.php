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
     * Stale entries retain their date, but have no discoverable Domain owner.
     *
     * @return list<array{name: string, domain: ?string, module: ?string, expires: string, days_left: int, reason: string, stale: bool}>
     */
    public function expiring(int $withinDays, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $allowlist = config('domain_commands.tenant_scope.allowlist', []);
        if (! is_array($allowlist)) {
            return [];
        }

        $commands = array_column($this->inventory->all(), null, 'name');
        $required = config('domain_commands.tenant_scope.required_domains', []);
        $warnings = [];

        foreach ($allowlist as $name => $entry) {
            $expiry = $this->validatedExpiry($entry);
            if (! is_string($name) || $expiry === null || $expiry->endOfDay()->lessThan($now)) {
                continue;
            }

            $daysLeft = (int) $now->startOfDay()->diffInDays($expiry->startOfDay());
            if ($daysLeft > $withinDays) {
                continue;
            }

            $command = $commands[$name] ?? null;
            if ($command !== null && ($command['tenant_scoped'] || ! is_array($required) || ! in_array($command['domain'], $required, true))) {
                continue;
            }

            $warnings[] = [
                'name' => $name,
                'domain' => $command['domain'] ?? null,
                'module' => $command['module'] ?? null,
                'expires' => $expiry->format('Y-m-d'),
                'days_left' => $daysLeft,
                'reason' => $entry['reason'],
                'stale' => $command === null,
            ];
        }

        usort($warnings, static fn (array $left, array $right): int => [$left['expires'], $left['name']] <=> [$right['expires'], $right['name']]);

        return $warnings;
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

        $expiry = $this->validatedExpiry($entry);

        return $expiry !== null && ! $expiry->endOfDay()->isPast();
    }

    private function validatedExpiry(mixed $entry): ?CarbonImmutable
    {
        if (! is_array($entry)) {
            return null;
        }

        $reason = $entry['reason'] ?? null;
        $expires = $entry['expires'] ?? null;

        if (! is_string($reason) || trim($reason) === '' || ! is_string($expires)) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) !== 1) {
            return null;
        }

        try {
            $expiry = CarbonImmutable::createFromFormat('!Y-m-d', $expires);
        } catch (InvalidFormatException) {
            return null;
        }

        if ($expiry === null || $expiry->format('Y-m-d') !== $expires) {
            return null;
        }

        return $expiry;
    }
}
