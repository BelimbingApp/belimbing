<?php

namespace App\Base\Routing;

use App\Base\Authz\Middleware\AuthorizeCapability;
use App\Base\Tenancy\Middleware\RequireTenantContext;

/**
 * Flags People / PeopleConnector domain routes that lack the tenant assertion
 * middleware or any authorization middleware.
 */
final class DomainRouteMiddlewareAudit
{
    public function __construct(
        private readonly DomainRouteInventory $inventory,
    ) {}

    /**
     * @return list<array{
     *     domain: string,
     *     module: string,
     *     uri: string,
     *     name: string|null,
     *     methods: list<string>,
     *     middleware: list<string>,
     *     missing: list<string>
     * }>
     */
    public function failures(): array
    {
        $requiredDomains = config('domain_routes.tenant_context.required_domains', []);
        if (! is_array($requiredDomains) || $requiredDomains === []) {
            return [];
        }

        $required = array_values(array_filter(
            $requiredDomains,
            static fn (mixed $domain): bool => is_string($domain) && $domain !== '',
        ));

        $failures = [];

        foreach ($this->inventory->all() as $row) {
            if (! in_array($row['domain'], $required, true)) {
                continue;
            }

            if ($this->isAllowlisted($row['name'] ?? null)) {
                continue;
            }

            $missing = [];

            if (! $this->hasTenantAssertion($row)) {
                $missing[] = 'tenant';
            }

            if (! $this->hasAuthorization($row['middleware'])) {
                $missing[] = 'authorization';
            }

            if ($missing === []) {
                continue;
            }

            $failures[] = [
                ...$row,
                'missing' => $missing,
            ];
        }

        return $failures;
    }

    private function isAllowlisted(?string $name): bool
    {
        if ($name === null || $name === '') {
            return false;
        }

        $allowlist = config('domain_routes.middleware_audit.allowlist', []);
        if (! is_array($allowlist)) {
            return false;
        }

        $reason = $allowlist[$name] ?? null;

        return is_string($reason) && trim($reason) !== '';
    }

    /**
     * @param  array{name: string|null, middleware: list<string>}  $row
     */
    private function hasTenantAssertion(array $row): bool
    {
        if ($this->hasTenantMiddleware($row['middleware'])) {
            return true;
        }

        $name = $row['name'] ?? null;
        if (! is_string($name) || $name === '') {
            return false;
        }

        $exclusions = config('domain_routes.tenant_context.exclusions', []);
        if (! is_array($exclusions)) {
            return false;
        }

        $reason = $exclusions[$name] ?? null;

        return is_string($reason) && trim($reason) !== '';
    }

    /**
     * @param  list<string>  $middleware
     */
    private function hasTenantMiddleware(array $middleware): bool
    {
        return in_array(RequireTenantContext::class, $middleware, true);
    }

    /**
     * @param  list<string>  $middleware
     */
    private function hasAuthorization(array $middleware): bool
    {
        foreach ($middleware as $entry) {
            if ($entry === 'authz' || str_starts_with($entry, 'authz:')) {
                return true;
            }

            if ($entry === AuthorizeCapability::class || str_starts_with($entry, AuthorizeCapability::class.':')) {
                return true;
            }
        }

        return false;
    }
}
