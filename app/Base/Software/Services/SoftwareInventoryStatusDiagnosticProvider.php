<?php

namespace App\Base\Software\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Enums\StatusVariant;
use App\Base\Software\Inventory\InstalledBundle;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use App\Base\System\DTO\StatusBarDiagnostic;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;

final class SoftwareInventoryStatusDiagnosticProvider implements StatusBarDiagnosticProvider
{
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

        $bundles = $this->inventory->installedBundlesForStatusDiagnostics();
        $diagnostics = [];

        $dependencyIssueCount = $this->dependencyIssueCount($bundles);
        if ($dependencyIssueCount > 0) {
            $diagnostics[] = $this->dependencyDiagnostic($dependencyIssueCount, $bundles);
        }

        $driftedBundles = $this->driftedAddInBundles($bundles);
        if ($driftedBundles !== []) {
            $diagnostics[] = $this->driftDiagnostic($driftedBundles);
        }

        return $diagnostics;
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
     * @param  list<InstalledBundle>  $bundles
     */
    private function dependencyDiagnostic(int $issueCount, array $bundles): StatusBarDiagnostic
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
                'affected_bundles' => $this->dependencyBundleLabels($bundles),
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
            summary: trans_choice(':count add-in bundle has local drift|:count add-in bundles have local drift', count($bundles), [
                'count' => count($bundles),
            ]),
            detail: __('One or more add-in bundles have uncommitted changes or unpushed commits. Open Modules before updating, disabling, or uninstalling add-ins.'),
            target: $this->modulesUrl(),
            metadata: [
                'affected_bundles' => array_map(fn (InstalledBundle $bundle): string => $bundle->label, $bundles),
                'dirty_bundles' => $dirty,
                'unpushed_commits' => $unpushedCommits,
            ],
        );
    }

    private function modulesUrl(): ?string
    {
        return Route::has('admin.system.software.modules.index')
            ? route('admin.system.software.modules.index')
            : null;
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
}
