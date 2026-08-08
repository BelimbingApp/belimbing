<?php

namespace App\Base\Authz\Support;

use App\Base\Authz\Contracts\TenantDirectory;

/**
 * Default TenantDirectory before the real directory is bound.
 *
 * Returns null so resources keep an unresolved tenant; TenantScopePolicy
 * abstains on unresolved tenants and CompanyScopePolicy remains the guard.
 */
final class NullTenantDirectory implements TenantDirectory
{
    public function tenantIdForCompany(?int $companyId): ?int
    {
        return null;
    }

    public function companyIdsInTenant(int $tenantId): array
    {
        return [];
    }
}
