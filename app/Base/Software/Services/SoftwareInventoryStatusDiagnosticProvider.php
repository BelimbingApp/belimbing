<?php

namespace App\Base\Software\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Enums\StatusVariant;
use App\Base\Software\Inventory\InstalledSource;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use App\Base\System\DTO\StatusBarDiagnostic;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

final class SoftwareInventoryStatusDiagnosticProvider implements StatusBarDiagnosticProvider
{
    private const INVENTORY_CACHE_KEY = 'software.inventory.status-diagnostics.v2';

    private const INVENTORY_FRESH_SECONDS = 300;

    private const INVENTORY_STALE_SECONDS = 3600;

    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly SoftwareInventoryService $inventory,
    ) {}

    /**
     * @return iterable<int, StatusBarDiagnostic>
     */
    public function diagnosticsFor(Authenticatable $user): iterable
    {
        if (! $this->canViewDomains($user)) {
            return [];
        }

        if ($this->isDomainsInventoryPage()) {
            return [];
        }

        if ($this->isSoftwareUpdatesPage()) {
            return $this->updatePageDependencyDiagnostics();
        }

        // Nested Git status is especially expensive on Windows. Keep navigation
        // off that synchronous path and refresh a shared snapshot after the
        // response once it becomes stale.
        $snapshot = Cache::flexible(
            self::INVENTORY_CACHE_KEY,
            [self::INVENTORY_FRESH_SECONDS, self::INVENTORY_STALE_SECONDS],
            fn (): array => $this->inventorySnapshot(),
        );
        $diagnostics = [];

        $dependencyIssueCount = array_sum(array_column($snapshot, 'dependency_issues'));
        if ($dependencyIssueCount > 0) {
            $diagnostics[] = $this->dependencyDiagnostic($dependencyIssueCount, $this->dependencySourceLabels($snapshot));
        }

        $driftedSources = $this->driftedAddInSources($snapshot);
        if ($driftedSources !== []) {
            $diagnostics[] = $this->driftDiagnostic($driftedSources);
        }

        return $diagnostics;
    }

    /**
     * Recompute the snapshot and store it exactly as Cache::flexible would,
     * so scheduled warming (software:inventory:warm, every ten minutes) keeps
     * the cache fresh and no page ever pays the multi-second git scan
     * synchronously. The companion `created` key is Cache::flexible's own
     * bookkeeping — writing both keeps the request-time fallback coherent.
     */
    public function warmInventorySnapshot(): void
    {
        Cache::putMany([
            self::INVENTORY_CACHE_KEY => $this->inventorySnapshot(),
            'illuminate:cache:flexible:created:'.self::INVENTORY_CACHE_KEY => now()->getTimestamp(),
        ], self::INVENTORY_STALE_SECONDS);
    }

    /**
     * The status bar needs only a few scalar facts per source. Cache those as
     * plain arrays: cache.serializable_classes is disabled (gadget-chain
     * hardening), so cached objects come back as __PHP_Incomplete_Class.
     *
     * @return list<array{label: string, kind: string, dirty: bool, unpushed: int, dependency_issues: int}>
     */
    private function inventorySnapshot(): array
    {
        return array_map(
            fn (InstalledSource $source): array => [
                'label' => $source->label,
                'kind' => $source->kind,
                'dirty' => $source->isDirty(),
                'unpushed' => $source->unpushed(),
                'dependency_issues' => count($source->dependencyIssues),
            ],
            $this->inventory->installedSourcesForStatusDiagnostics(),
        );
    }

    /**
     * @return list<StatusBarDiagnostic>
     */
    private function updatePageDependencyDiagnostics(): array
    {
        $issues = $this->inventory->dependencyIssuesForStatusDiagnostics();
        $issueCount = count($issues);

        if ($issueCount === 0) {
            return [];
        }

        return [
            $this->dependencyDiagnostic($issueCount, $this->dependencyIssueLabels($issues)),
        ];
    }

    /**
     * @param  list<string>  $affectedLabels
     */
    private function dependencyDiagnostic(int $issueCount, array $affectedLabels): StatusBarDiagnostic
    {
        return new StatusBarDiagnostic(
            id: 'software.module-dependencies',
            severity: StatusVariant::Error,
            source: __('Software'),
            summary: trans_choice(':count module dependency issue needs attention|:count module dependency issues need attention', $issueCount, [
                'count' => $issueCount,
            ]),
            detail: __('Open Domains to resolve missing or incompatible module dependencies before running migrations or enabling affected domains.'),
            target: $this->domainsUrl(),
            metadata: [
                'dependency_issues' => $issueCount,
                'affected_sources' => $affectedLabels,
            ],
        );
    }

    /**
     * @param  list<array{label: string, dependency_issues: int}>  $snapshot
     * @return list<string>
     */
    private function dependencySourceLabels(array $snapshot): array
    {
        return array_values(array_map(
            fn (array $source): string => $source['label'],
            array_filter($snapshot, fn (array $source): bool => $source['dependency_issues'] > 0),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<string>
     */
    private function dependencyIssueLabels(array $issues): array
    {
        return array_values(array_unique(array_map(
            fn (array $issue): string => (string) ($issue['requiring'] ?? $issue['requiring_module'] ?? __('Unknown module')),
            $issues,
        )));
    }

    /**
     * @param  list<array{kind: string, dirty: bool, unpushed: int}>  $snapshot
     * @return list<array{label: string, kind: string, dirty: bool, unpushed: int, dependency_issues: int}>
     */
    private function driftedAddInSources(array $snapshot): array
    {
        return array_values(array_filter($snapshot, function (array $source): bool {
            if ($source['kind'] === InstalledSource::KIND_PLATFORM) {
                return false;
            }

            return $source['dirty'] || $source['unpushed'] > 0;
        }));
    }

    /**
     * @param  list<array{label: string, dirty: bool, unpushed: int}>  $sources
     */
    private function driftDiagnostic(array $sources): StatusBarDiagnostic
    {
        $dirty = count(array_filter($sources, fn (array $source): bool => $source['dirty']));
        $unpushedCommits = array_sum(array_column($sources, 'unpushed'));

        return new StatusBarDiagnostic(
            id: 'software.source-drift',
            severity: StatusVariant::Warning,
            source: __('Software'),
            summary: trans_choice('{1} :count add-in source has local drift|[2,*] :count add-in sources have local drift', count($sources), [
                'count' => count($sources),
            ]),
            detail: __('One or more add-in sources have uncommitted changes or unpushed commits. Open Domains to see the affected checkout paths and resolve them before updating or changing add-ins.'),
            target: $this->domainsUrl('#add-in-source-drift'),
            metadata: [
                'affected_sources' => array_column($sources, 'label'),
                'dirty_sources' => $dirty,
                'unpushed_commits' => $unpushedCommits,
            ],
        );
    }

    private function domainsUrl(string $fragment = ''): ?string
    {
        if (! Route::has('admin.system.software.domains.index')) {
            return null;
        }

        return route('admin.system.software.domains.index').$fragment;
    }

    private function canViewDomains(Authenticatable $user): bool
    {
        try {
            return $this->authorizationService
                ->can(Actor::forUser($user), 'admin.system.software.domains.view')
                ->allowed;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isDomainsInventoryPage(): bool
    {
        try {
            return request()->routeIs('admin.system.software.domains.index');
        } catch (\Throwable) {
            return false;
        }
    }

    private function isSoftwareUpdatesPage(): bool
    {
        try {
            return request()->routeIs('admin.system.software.updates.index');
        } catch (\Throwable) {
            return false;
        }
    }
}
