<?php

namespace App\Base\Audit\Services;

use App\Base\Audit\Models\AuditAction;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
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

    /**
     * Retention line shared by tenant-scoped admin logs (#894 / audit actions).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $oldestSource
     */
    public function retentionCaption(
        string $allTenantsLabel,
        string $currentTenantLabel,
        bool $allTenants,
        int $retentionDays,
        Builder $oldestSource,
        string $oldestTable,
        string $pruneCommandNeedle,
    ): string {
        $scopeLabel = $this->ambientIsPlatformOperator() && $allTenants
            ? $allTenantsLabel
            : $currentTenantLabel;

        $oldest = $this->apply($oldestSource, $oldestTable, $allTenants)->min('occurred_at');
        $lastPrune = $this->apply(
            AuditAction::query()
                ->where('event', 'console.command')
                ->where('url', 'like', '%'.$pruneCommandNeedle.'%')
                ->orderByDesc('occurred_at'),
            'base_audit_actions',
            $allTenants,
        )->value('occurred_at');

        return $scopeLabel.' — '.__('Retention :days days, oldest row :oldest, last prune :prune', [
            'days' => $retentionDays,
            'oldest' => $oldest !== null ? Carbon::parse($oldest)->toDateString() : __('none'),
            'prune' => $lastPrune !== null ? Carbon::parse($lastPrune)->format('Y-m-d H:i') : __('never'),
        ]);
    }
}
