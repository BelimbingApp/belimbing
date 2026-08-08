<?php

namespace App\Base\Tenancy\Contracts;

use App\Base\Tenancy\Exceptions\TenantContextMissingException;

/**
 * The current execution's tenant boundary.
 *
 * Tenant context is resolved once per execution (web request, queue job,
 * console command) and carried explicitly. Consumers fail closed on null:
 * no tenant context must never widen into unscoped access.
 */
interface TenantContext
{
    /**
     * The current tenant ID, or null when no tenant context is resolved.
     */
    public function currentTenantId(): ?int;

    /**
     * Whether a tenant context is currently resolved.
     */
    public function hasTenant(): bool;

    /**
     * The current tenant ID; throws when no tenant context is resolved.
     *
     * @throws TenantContextMissingException
     */
    public function requireTenantId(): int;

    /**
     * Set the current tenant context (null clears it).
     */
    public function set(?int $tenantId): void;

    /**
     * Clear the current tenant context.
     */
    public function clear(): void;

    /**
     * Run the callback under the given tenant context, restoring the
     * previous context afterwards — including when the callback throws.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runForTenant(?int $tenantId, callable $callback): mixed;
}
