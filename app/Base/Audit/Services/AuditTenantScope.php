<?php

namespace App\Base\Audit\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single tenant predicate for audit readers (#873).
 *
 * Default: require ambient tenant and filter `$table.tenant_id`. Platform
 * operators may pass `allTenants: true` to keep the unfiltered cross-tenant
 * view (including null-tenant rows from anonymous capture).
 */
final class AuditTenantScope
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function apply(Builder $query, string $table, bool $allTenants = false): Builder
    {
        $tenantId = $this->tenants->requireTenantId();

        if ($allTenants && Tenant::query()->find($tenantId)?->isPlatformOperator()) {
            return $query;
        }

        return $query->where("{$table}.tenant_id", $tenantId);
    }

    public function ambientIsPlatformOperator(): bool
    {
        $tenantId = $this->tenants->currentTenantId();
        if ($tenantId === null) {
            return false;
        }

        return Tenant::query()->find($tenantId)?->isPlatformOperator() === true;
    }
}
