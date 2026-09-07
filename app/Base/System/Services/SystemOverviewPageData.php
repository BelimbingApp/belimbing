<?php

namespace App\Base\System\Services;

use App\Base\Authz\Capability\CapabilityInventory;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\FeatureFlags\Services\FeatureFlagDeclarationInventory;
use App\Base\Routing\Services\TenantAuditPageData;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Capability-gated headline cards for the system overview page.
 *
 * Counts come only from the same page-data / inventory services the
 * operator surfaces already use. A card is omitted when the actor lacks
 * that surface's view capability — the check lives here so a mutant that
 * drops it makes a forbidden card appear.
 */
final class SystemOverviewPageData
{
    public const FEATURE_FLAGS_VIEW = 'admin.system.feature-flags.view';

    public const AUDIT_VIEW = 'admin.system.audit.view';

    public const CAPABILITIES_VIEW = CapabilityInventory::VIEW;

    /** @var list<string> */
    public const VIEW_CAPABILITIES = [
        self::FEATURE_FLAGS_VIEW,
        self::AUDIT_VIEW,
        self::CAPABILITIES_VIEW,
    ];

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly FeatureFlagDeclarationInventory $declarations,
        private readonly TenantAuditPageData $tenantAudit,
        private readonly CapabilityInventory $capabilities,
    ) {}

    public function actorCanView(Authenticatable $user): bool
    {
        $actor = Actor::forUser($user);

        foreach (self::VIEW_CAPABILITIES as $capability) {
            if ($this->authorization->can($actor, $capability)->allowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{
     *     key: string,
     *     title: string,
     *     count: int,
     *     count_label: string,
     *     href: string,
     *     capability: string
     * }>
     */
    public function cardsFor(Authenticatable $user): array
    {
        $actor = Actor::forUser($user);
        $cards = [];

        if ($this->authorization->can($actor, self::FEATURE_FLAGS_VIEW)->allowed) {
            $declared = $this->declarations->forCurrentTenant();
            $overridden = count(array_filter(
                $declared,
                static fn (array $row): bool => $row['overridden'] && ! $row['conflict'],
            ));
            $conflicts = count(array_filter(
                $declared,
                static fn (array $row): bool => $row['conflict'],
            ));

            $cards[] = [
                'key' => 'feature-flags',
                'title' => __('Feature flags'),
                'count' => $overridden,
                'count_label' => __('Flags overridden'),
                'href' => route('admin.system.feature-flags.index'),
                'capability' => self::FEATURE_FLAGS_VIEW,
            ];

            $cards[] = [
                'key' => 'declared-flags',
                'title' => __('Declared flags'),
                'count' => $conflicts,
                'count_label' => __('Ownership collisions'),
                'href' => route('admin.system.feature-flags.index').'#declared',
                'capability' => self::FEATURE_FLAGS_VIEW,
            ];
        }

        if ($this->authorization->can($actor, self::AUDIT_VIEW)->allowed) {
            $cards[] = [
                'key' => 'tenant-audit',
                'title' => __('Tenant audit'),
                'count' => count($this->tenantAudit->routeRows()),
                'count_label' => __('Audit findings'),
                'href' => route('admin.system.tenant-audit.index'),
                'capability' => self::AUDIT_VIEW,
            ];
        }

        if ($this->authorization->can($actor, self::CAPABILITIES_VIEW)->allowed) {
            $conflicted = count(array_filter(
                $this->capabilities->rows(),
                static fn ($row): bool => $row->conflicted,
            ));

            $cards[] = [
                'key' => 'capabilities',
                'title' => __('Capabilities'),
                'count' => $conflicted,
                'count_label' => __('Capability conflicts'),
                'href' => route('admin.system.capabilities.index'),
                'capability' => self::CAPABILITIES_VIEW,
            ];
        }

        return $cards;
    }
}
