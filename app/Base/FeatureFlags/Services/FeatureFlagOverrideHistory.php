<?php

namespace App\Base\FeatureFlags\Services;

use App\Base\Audit\Models\AuditMutation;
use App\Base\Audit\Services\AuditLogPresenter;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Tenancy\Contracts\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tenant-scoped override mutation history for the operator feature-flags page.
 *
 * Reads `base_audit_mutations` for `feature-flag` subjects only. Always
 * filters by the ambient tenant so another tenant's toggles cannot appear.
 */
final class FeatureFlagOverrideHistory
{
    public const PER_FLAG_LIMIT = 10;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuditLogPresenter $presenter,
    ) {}

    /**
     * Last override mutations for the current tenant, grouped by flag.
     *
     * @return array<string, list<array{flag: string, actor: string, tenant_id: int, old_enabled: ?bool, new_enabled: ?bool, event: string, occurred_at: ?string}>>
     */
    public function forCurrentTenant(int $perFlag = self::PER_FLAG_LIMIT): array
    {
        return $this->forTenant($this->tenants->requireTenantId(), $perFlag);
    }

    /**
     * @return array<string, list<array{flag: string, actor: string, tenant_id: int, old_enabled: ?bool, new_enabled: ?bool, event: string, occurred_at: ?string}>>
     */
    public function forTenant(int $tenantId, int $perFlag = self::PER_FLAG_LIMIT): array
    {
        $perFlag = max(1, $perFlag);
        /** @var array<string, list<array{flag: string, actor: string, tenant_id: int, old_enabled: ?bool, new_enabled: ?bool, event: string, occurred_at: ?string}>> $grouped */
        $grouped = [];

        /** @var list<AuditMutation> $mutations */
        $mutations = $this->mutationsQuery()
            ->where('base_audit_mutations.tenant_id', $tenantId)
            ->orderByDesc('base_audit_mutations.occurred_at')
            ->orderByDesc('base_audit_mutations.id')
            ->get()
            ->all();

        foreach ($mutations as $mutation) {
            $flag = (string) $mutation->subject_id;
            if ($flag === '') {
                continue;
            }
            if (! isset($grouped[$flag])) {
                $grouped[$flag] = [];
            }
            if (count($grouped[$flag]) >= $perFlag) {
                continue;
            }
            $grouped[$flag][] = $this->entry($mutation);
        }

        ksort($grouped);

        return $grouped;
    }

    private function mutationsQuery(): Builder
    {
        return AuditMutation::query()
            ->leftJoin('users', function ($join): void {
                $join->on('base_audit_mutations.actor_id', '=', 'users.id')
                    ->where('base_audit_mutations.actor_type', '=', PrincipalType::USER->value);
            })
            ->select('base_audit_mutations.*', 'users.name as actor_name')
            ->where('base_audit_mutations.subject_name', 'feature-flag')
            ->where('base_audit_mutations.source', '!=', 'expanded')
            ->whereNotNull('base_audit_mutations.subject_id');
    }

    /**
     * @return array{flag: string, actor: string, tenant_id: int, old_enabled: ?bool, new_enabled: ?bool, event: string, occurred_at: ?string}
     */
    private function entry(AuditMutation $mutation): array
    {
        return [
            'flag' => (string) $mutation->subject_id,
            'actor' => $this->presenter->actorLabel($mutation),
            'tenant_id' => (int) $mutation->tenant_id,
            'old_enabled' => $this->enabledValue($mutation->old_values),
            'new_enabled' => $this->enabledValue($mutation->new_values),
            'event' => (string) $mutation->event,
            'occurred_at' => $mutation->occurred_at instanceof CarbonInterface
                ? $mutation->occurred_at->toIso8601String()
                : null,
        ];
    }

    private function enabledValue(mixed $values): ?bool
    {
        if (! is_array($values) || ! array_key_exists('enabled', $values)) {
            return null;
        }

        $raw = $values['enabled'];

        if (is_bool($raw)) {
            return $raw;
        }

        if ($raw === 0 || $raw === 1 || $raw === '0' || $raw === '1') {
            return (bool) (int) $raw;
        }

        return null;
    }
}
