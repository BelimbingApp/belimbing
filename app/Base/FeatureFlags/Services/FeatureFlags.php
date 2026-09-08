<?php

namespace App\Base\FeatureFlags\Services;

use App\Base\FeatureFlags\Exceptions\FeatureFlagStillDeclaredException;
use App\Base\FeatureFlags\Exceptions\UndeclaredFeatureFlagException;
use App\Base\FeatureFlags\Models\FeatureFlagOverride;
use App\Base\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Facades\Schema;

/**
 * Resolve declared feature flags for the current tenant.
 *
 * Declaration lives in module descriptors (`extra.blb.feature-flags`).
 * Per-tenant rows override the declared default; undeclared names refuse.
 */
final class FeatureFlags
{
    public function __construct(
        private readonly FeatureFlagRegistry $registry,
        private readonly TenantContext $tenants,
    ) {}

    public function enabled(string $flag): bool
    {
        $definition = $this->registry->get($flag);
        $override = $this->overrideFor($flag, $this->tenants->requireTenantId());

        return $override ?? $definition->default;
    }

    /**
     * Persist a per-tenant override for a declared flag.
     */
    public function override(string $flag, bool $enabled): void
    {
        $this->registry->get($flag);
        $tenantId = $this->tenants->requireTenantId();

        FeatureFlagOverride::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'flag' => $flag],
            ['enabled' => $enabled],
        );
    }

    /**
     * Clear a tenant override so the declared default applies again.
     */
    public function clearOverride(string $flag): void
    {
        $this->registry->get($flag);
        FeatureFlagOverride::query()
            ->where('tenant_id', $this->tenants->requireTenantId())
            ->where('flag', $flag)
            ->get()
            ->each(fn (FeatureFlagOverride $row): bool => $row->delete());
    }

    /**
     * Delete an override whose flag is not declared by any enabled module.
     *
     * Declared names still clear through clearOverride() so the two paths
     * cannot be confused.
     */
    public function purgeOrphanedOverride(string $flag): void
    {
        try {
            $this->registry->get($flag);
            throw FeatureFlagStillDeclaredException::forFlag($flag);
        } catch (UndeclaredFeatureFlagException) {
            // Expected: only undeclared names may be purged here.
        }

        FeatureFlagOverride::query()
            ->where('tenant_id', $this->tenants->requireTenantId())
            ->where('flag', $flag)
            ->get()
            ->each(fn (FeatureFlagOverride $row): bool => $row->delete());
    }

    /**
     * @return list<array{flag: string, module: string|null, description: string, default: bool|null, enabled: bool, overridden: bool, orphaned: bool}>
     */
    public function listForCurrentTenant(): array
    {
        $tenantId = $this->tenants->requireTenantId();
        $rows = [];
        $declared = [];

        foreach ($this->registry->all() as $definition) {
            $declared[$definition->flag] = true;
            $override = $this->overrideFor($definition->flag, $tenantId);
            $rows[] = [
                'flag' => $definition->flag,
                'module' => $definition->module,
                'description' => $definition->description,
                'default' => $definition->default,
                'enabled' => $override ?? $definition->default,
                'overridden' => $override !== null,
                'orphaned' => false,
            ];
        }

        if ($this->overridesTableReady()) {
            $orphans = FeatureFlagOverride::query()
                ->where('tenant_id', $tenantId)
                ->when($declared !== [], fn ($query) => $query->whereNotIn('flag', array_keys($declared)))
                ->orderBy('flag')
                ->get(['flag', 'enabled']);

            foreach ($orphans as $orphan) {
                $rows[] = [
                    'flag' => (string) $orphan->flag,
                    'module' => null,
                    'description' => '',
                    'default' => null,
                    'enabled' => (bool) $orphan->enabled,
                    'overridden' => true,
                    'orphaned' => true,
                ];
            }
        }

        return $rows;
    }

    private function overrideFor(string $flag, int $tenantId): ?bool
    {
        if (! $this->overridesTableReady()) {
            return null;
        }

        $row = FeatureFlagOverride::query()
            ->where('tenant_id', $tenantId)
            ->where('flag', $flag)
            ->first();

        return $row === null ? null : (bool) $row->enabled;
    }

    private function overridesTableReady(): bool
    {
        try {
            return Schema::hasTable('base_feature_flag_overrides');
        } catch (\Throwable) {
            return false;
        }
    }
}
