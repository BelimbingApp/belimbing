<?php

namespace App\Base\Authz\Contracts;

/**
 * Resolves the tenant a company belongs to.
 *
 * Owned by Authz (the consuming module) so the authorization engine can
 * enrich resource contexts without depending on a Tenancy/Core
 * implementation. The default binding is a null object; Core/Company binds
 * the real directory.
 */
interface TenantDirectory
{
    /**
     * The tenant ID for the given company, or null when unknown.
     */
    public function tenantIdForCompany(?int $companyId): ?int;

    /**
     * Company IDs belonging to the given tenant, oldest first.
     *
     * Used for tenant-level defaults that anchor on a company's own
     * configuration (e.g. AI providers). Returns an empty list when
     * tenancy is not mapped.
     *
     * @return list<int>
     */
    public function companyIdsInTenant(int $tenantId): array;
}
