<?php

namespace App\Core\Company\Services;

use App\Base\Authz\Contracts\TenantDirectory;
use Illuminate\Support\Facades\DB;

/**
 * Maps companies to their tenant for the authorization engine.
 *
 * Memoized per instance: a company's tenant assignment is set at creation
 * and treated as immutable, so no cache invalidation is needed within a
 * process. Unknown companies resolve to null, leaving the resource's tenant
 * unresolved (company scope remains the guard).
 */
final class CompanyTenantDirectory implements TenantDirectory
{
    /**
     * @var array<int, int|null>
     */
    private array $memo = [];

    public function tenantIdForCompany(?int $companyId): ?int
    {
        if ($companyId === null) {
            return null;
        }

        if (! array_key_exists($companyId, $this->memo)) {
            $tenantId = DB::table('companies')
                ->where('id', $companyId)
                ->whereNull('deleted_at')
                ->value('tenant_id');

            $this->memo[$companyId] = $tenantId !== null ? (int) $tenantId : null;
        }

        return $this->memo[$companyId];
    }

    public function companyIdsInTenant(int $tenantId): array
    {
        return DB::table('companies')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
