<?php

namespace App\Base\Tenancy\Services;

use App\Base\Tenancy\Contracts\TenantContext;

/**
 * Partitions tenant-owned storage paths by tenant.
 *
 * When a tenant context is active, caller-supplied directories are prefixed
 * with `tenants/{id}/` so tenant files never share a directory root. With no
 * tenant context (guests, platform operations), the path is unchanged, so
 * existing single-tenant installs keep their current layout.
 */
final readonly class TenantStoragePath
{
    public function __construct(
        private TenantContext $context,
    ) {}

    /**
     * Prefix a caller-supplied storage directory with the active tenant.
     */
    public function prefix(string $directory): string
    {
        $tenantId = $this->context->currentTenantId();

        if ($tenantId === null) {
            return $directory;
        }

        $directory = trim($directory, '/');

        return "tenants/{$tenantId}".($directory !== '' ? "/{$directory}" : '');
    }
}
