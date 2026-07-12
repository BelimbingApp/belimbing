<?php

namespace App\Base\Software\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Enums\StatusVariant;
use App\Base\Software\Inventory\InstalledBundle;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use App\Base\System\DTO\StatusBarDiagnostic;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

final class SoftwareInventoryStatusDiagnosticProvider implements StatusBarDiagnosticProvider
{
    private const INVENTORY_CACHE_KEY = 'software.inventory.status-diagnostics';

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
        if (! $this->canViewModules($user)) {
            return [];
        }

        if ($this->isModulesInventoryPage()) {
            return [];
        }

        if ($this->isSoftwareUpdatesPage()) {
            return $this->updatePageDependencyDiagnostics();
        }

        // Nested Git status is especially expensive on Windows. Keep navigation
        // off that synchronous path and refresh a shared snapshot after the
        // response once it becomes stale.
        $bundles = Cache::flexible(
            self::INVENTORY_CACHE_KEY,
            [self::INVENTORY_FRESH_SECONDS, self::INVENTORY_STALE_SECONDS],
            fn (): array => $this->inventory->installedBundlesForStatusDiagnostics(),
        );
        $diagnostics = [];

        $dependencyIssueCount = $this->dependencyIssueCount($bundles);
        if ($dependencyIssueCount > 0) {
            $diagnostics[] = $this->dependencyDiagnostic($dependencyIssueCount, $this->dependencyBundleLabels($bundles));
        }

        $driftedBundles = $this->driftedAddInBundles($bundles);
        if ($driftedBundles !== []) {
            $diagnostics[] = $this->driftDiagnostic($driftedBundles);
        }

        return $diagnostics;
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
     * @param  list<InstalledBundle>  $bundles
     */
    private function dependencyIssueCount(array $bundles): int
    {
        return array_sum(array_map(
            fn (InstalledBundle $bundle): int => count($bundle->dependencyIssues),
            $bundles,
        ));
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
            detail: __('Open Modules to resolve missing or incompatible module dependencies before running migrations or enabling affected domains.'),
            target: $this->modulesUrl(),
            metadata: [
                'dependency_issues' => $issueCount,
                'affected_bundles' => $affectedLabels,
            ],
        );
    }

    /**
     * @param  list<InstalledBundle>  $bundles
     * @return list<string>
     */
    private function dependencyBundleLabels(array $bundles): array
    {
        return array_values(array_map(
            fn (InstalledBundle $bundle): string => $bundle->label,
            array_filter($bundles, fn (InstalledBundle $bundle): bool => $bundle->hasDependencyIssues()),
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
     * @param  list<InstalledBundle>  $bundles
     * @return list<InstalledBundle>
     */
    private function driftedAddInBundles(array $bundles): array
    {
        return array_values(array_filter($bundles, function (InstalledBundle $bundle): bool {
            if ($bundle->kind === InstalledBundle::KIND_PLATFORM) {
                return false;
            }

            return $bundle->isDirty() || $bundle->unpushed() > 0;
        }));
    }

    /**
     * @param  list<InstalledBundle>  $bundles
     */
    private function driftDiagnostic(array $bundles): StatusBarDiagnostic
    {
        $dirty = count(array_filter($bundles, fn (InstalledBundle $bundle): bool => $bundle->isDirty()));
        $unpushedCommits = array_sum(array_map(fn (InstalledBundle $bundle): int => $bundle->unpushed(), $bundles));

        return new StatusBarDiagnostic(
            id: 'software.bundle-drift',
            severity: StatusVariant::Warning,
            source: __('Software'),
            summary: trans_choice('{1} :count add-in bundle has local drift|[2,*] :count add-in bundles have local drift', count($bundles), [
                'count' => count($bundles),
            ]),
            detail: __('One or more add-in bundles have uncommitted changes or unpushed commits. Open Modules to see the affected checkout paths and resolve them before updating or changing add-ins.'),
            target: $this->modulesUrl('#add-in-bundle-drift'),
            metadata: [
                'affected_bundles' => array_map(fn (InstalledBundle $bundle): string => $bundle->label, $bundles),
                'dirty_bundles' => $dirty,
                'unpushed_commits' => $unpushedCommits,
            ],
        );
    }

    private function modulesUrl(string $fragment = ''): ?string
    {
        if (! Route::has('admin.system.software.modules.index')) {
            return null;
        }

        return route('admin.system.software.modules.index').$fragment;
    }

    private function canViewModules(Authenticatable $user): bool
    {
        try {
            return $this->authorizationService
                ->can(Actor::forUser($user), 'admin.system.software.modules.view')
                ->allowed;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isModulesInventoryPage(): bool
    {
        try {
            return request()->routeIs('admin.system.software.modules.index');
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
