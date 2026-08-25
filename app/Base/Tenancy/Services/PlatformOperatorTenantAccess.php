<?php

namespace App\Base\Tenancy\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Models\Tenant;

/**
 * Resolve whether the current tenant is the platform operator.
 *
 * The current tenant comes exclusively from TenantContext and a missing or
 * deleted tenant fails closed.
 */
final readonly class PlatformOperatorTenantAccess
{
    public function __construct(private TenantContext $tenantContext) {}

    public function allows(): bool
    {
        $tenantId = $this->tenantContext->currentTenantId();

        return $tenantId !== null
            && Tenant::query()
                ->whereKey($tenantId)
                ->where('is_platform_operator', true)
                ->exists();
    }
}
