<?php

namespace App\Base\FeatureFlags\Services;

use App\Base\FeatureFlags\Models\FeatureFlagOverride;
use App\Base\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only manifest inventory for operator diagnostics.
 *
 * Runtime flag resolution stays strict in FeatureFlagRegistry. This inventory
 * groups duplicates so operators can see and repair an otherwise fatal
 * configuration conflict.
 */
final class FeatureFlagDeclarationInventory
{
    public function __construct(
        private readonly FeatureFlagRegistry $registry,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @return list<array{
     *     flag: string,
     *     declarations: list<array{module: string, default: bool, description: string}>,
     *     conflict: bool,
     *     overridden: bool,
     *     override_enabled: bool|null
     * }>
     */
    public function forCurrentTenant(): array
    {
        $tenantId = $this->tenants->requireTenantId();
        $grouped = [];

        foreach ($this->registry->declarations() as $flag => $declarations) {
            foreach ($declarations as $declaration) {
                $grouped[$flag][] = [
                    'module' => $declaration->module,
                    'default' => $declaration->default,
                    'description' => $declaration->description,
                ];
            }
        }
        $overrides = $this->overrides($tenantId, array_keys($grouped));
        $rows = [];

        foreach ($grouped as $flag => $declarations) {
            usort($declarations, fn (array $left, array $right): int => $left['module'] <=> $right['module']);
            $override = $overrides[$flag] ?? null;
            $rows[] = [
                'flag' => $flag,
                'declarations' => $declarations,
                'conflict' => count($declarations) > 1,
                'overridden' => $override !== null,
                'override_enabled' => $override,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $flags
     * @return array<string, bool>
     */
    private function overrides(int $tenantId, array $flags): array
    {
        if ($flags === [] || ! Schema::hasTable((new FeatureFlagOverride)->getTable())) {
            return [];
        }

        return FeatureFlagOverride::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('flag', $flags)
            ->get(['flag', 'enabled'])
            ->mapWithKeys(fn (FeatureFlagOverride $override): array => [
                $override->flag => (bool) $override->enabled,
            ])
            ->all();
    }
}
