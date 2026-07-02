<?php

namespace App\Base\Software\Services;

use App\Base\Foundation\ModuleManifest\ModuleManifest;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Foundation\Services\DomainState;
use App\Base\Software\Inventory\ContributionSummary;
use App\Base\Software\Inventory\InstalledBundle;
use App\Base\Software\Inventory\InstalledModule;

/**
 * Software Inventory read model: the installed view grouped by Distribution Bundle.
 *
 * Joins git-backed Bundle discovery (DistributionBundleRepository) with module
 * manifests (ModuleManifestReader) so the UI can say which Bundles are installed,
 * which Modules each contains, and each Bundle's git/dependency health — without the
 * page re-deriving the filesystem. Each Module is attributed to its *nearest* Bundle
 * root (the longest bundle path that contains it); Platform Baseline modules
 * (Base/Core) and other non-nested code fall back to the platform Bundle.
 */
class SoftwareInventoryService
{
    private const STATUS_DIAGNOSTIC_GIT_TIMEOUT_SECONDS = 3;

    public function __construct(
        private readonly DistributionBundleRepository $bundles,
        private readonly InventoryContributionRegistry $contributions,
    ) {}

    /**
     * @return list<InstalledBundle>
     */
    public function installedBundles(): array
    {
        $reader = $this->reader();

        return $this->assemble(
            $this->bundles->localStatus(),
            $reader->allIncludingDisabledDomains(),
            $reader->dependencyIssues($reader->all()),
            array_values(DomainState::disabled()),
            $this->contributions->contributions(),
        );
    }

    /**
     * Same grouping model as installedBundles(), but avoids the expensive platform
     * working-tree scan. Status-bar diagnostics only care about add-in drift and
     * dependency health, so platform checkout dirtiness is intentionally out of scope.
     *
     * @return list<InstalledBundle>
     */
    public function installedBundlesForStatusDiagnostics(): array
    {
        $reader = $this->reader();

        return $this->assemble(
            $this->bundles->localStatus(
                includePlatformWorkingTree: false,
                gitTimeoutSeconds: self::STATUS_DIAGNOSTIC_GIT_TIMEOUT_SECONDS,
            ),
            $reader->allIncludingDisabledDomains(),
            $reader->dependencyIssues($reader->all()),
            array_values(DomainState::disabled()),
            $this->contributions->contributions(),
        );
    }

    /**
     * Module dependency diagnostics for pages that should not pay the Git inventory
     * scan. This reads only manifests and dependency declarations.
     *
     * @return list<array{issue: 'missing'|'incompatible', requiring: string, requiring_module: string, required: string, constraint: string, installed_version?: string}>
     */
    public function dependencyIssuesForStatusDiagnostics(): array
    {
        $reader = $this->reader();

        return $reader->dependencyIssues($reader->all());
    }

    /**
     * Pure assembly of the read model from already-gathered inputs. Kept separate from
     * the git/filesystem gathering above so the grouping rules are unit-testable without
     * touching disk.
     *
     * @param  list<array<string, mixed>>  $bundleStatuses  rows from DistributionBundleRepository::localStatus()
     * @param  list<ModuleManifest>  $manifests  every installed manifest, including disabled domains
     * @param  list<array<string, mixed>>  $dependencyIssues  rows from ModuleManifestReader::dependencyIssues()
     * @param  list<string>  $disabledDomains  disabled business-domain names
     * @param  list<ContributionSummary>  $contributions  discovered runtime contributions
     * @return list<InstalledBundle>
     */
    public function assemble(array $bundleStatuses, array $manifests, array $dependencyIssues, array $disabledDomains = [], array $contributions = []): array
    {
        $byKey = [];
        foreach ($bundleStatuses as $status) {
            $byKey[$status['key']] = ['status' => $status, 'modules' => [], 'issues' => [], 'contributions' => []];
        }

        // Match each module to the deepest bundle that contains it: longest path first.
        $sortedKeys = array_keys($byKey);
        usort($sortedKeys, fn (string $a, string $b): int => strlen($this->normalizePath((string) $byKey[$b]['status']['absolutePath']))
            <=> strlen($this->normalizePath((string) $byKey[$a]['status']['absolutePath'])));

        $moduleKeys = $this->attachModules($byKey, $manifests, $sortedKeys);

        // Dependency issues surface at the row of the Bundle that owns the requiring module.
        foreach ($dependencyIssues as $issue) {
            $key = $moduleKeys['manifest'][$issue['requiring'] ?? ''] ?? null;

            if ($key !== null) {
                $byKey[$key]['issues'][] = $issue;
            }
        }

        $this->attachContributions($byKey, $contributions, $moduleKeys['module']);

        $bundles = [];
        foreach ($byKey as $key => $data) {
            $bundles[] = $this->buildBundle((string) $key, $data, $disabledDomains);
        }

        return $bundles;
    }

    /**
     * Place each manifest's Module under its nearest Bundle and return the
     * manifest-name and module-id → bundle-key maps used for attribution.
     *
     * @param  array<string, array<string, mixed>>  $byKey
     * @param  list<ModuleManifest>  $manifests
     * @param  list<string>  $sortedKeys  bundle keys ordered longest-path-first
     * @return array{manifest: array<string, string>, module: array<string, string>}
     */
    private function attachModules(array &$byKey, array $manifests, array $sortedKeys): array
    {
        $manifestBundleKey = [];
        $moduleBundleKey = [];

        foreach ($manifests as $manifest) {
            $bundleKey = $this->nearestBundleKey($this->normalizePath($manifest->path), $sortedKeys, $byKey);

            if ($bundleKey === null) {
                continue;
            }

            $byKey[$bundleKey]['modules'][] = new InstalledModule(
                module: $manifest->module,
                name: $manifest->name,
                path: $this->relativePath($manifest->path),
                version: $manifest->version,
                description: $manifest->description,
                requiresModules: $manifest->requiresModules,
                optionalModules: $manifest->optionalModules,
                publishesEvents: $manifest->publishesEvents,
                consumesEvents: $manifest->consumesEvents,
            );

            $manifestBundleKey[$manifest->name] = $bundleKey;

            if ($manifest->module !== '') {
                $moduleBundleKey[$manifest->module] = $bundleKey;
            }
        }

        return ['manifest' => $manifestBundleKey, 'module' => $moduleBundleKey];
    }

    /**
     * Surface contributions under the Bundle that delivers the providing module —
     * by exact module manifest when available, else by the module's domain bundle
     * (so a domain like Commerce that ships no per-module manifests still attributes).
     *
     * @param  array<string, array<string, mixed>>  $byKey
     * @param  list<ContributionSummary>  $contributions
     * @param  array<string, string>  $moduleBundleKey
     */
    private function attachContributions(array &$byKey, array $contributions, array $moduleBundleKey): void
    {
        $domainKeyByName = [];
        foreach ($byKey as $bundleKey => $data) {
            $kind = $this->classifyKind((string) $bundleKey, (string) $data['status']['path']);
            $lifecycleName = $this->lifecycleName($kind, (string) $data['status']['absolutePath']);

            if ($kind === InstalledBundle::KIND_BUSINESS_DOMAIN && $lifecycleName !== null) {
                $domainKeyByName[strtolower($lifecycleName)] = $bundleKey;
            }
        }

        foreach ($contributions as $contribution) {
            $module = $contribution->attributedModule();
            $key = $moduleBundleKey[$module]
                ?? $domainKeyByName[strtolower(explode('/', $module)[0] ?? '')]
                ?? null;

            if ($key !== null) {
                $byKey[$key]['contributions'][] = $contribution;
            }
        }
    }

    /**
     * @param  array{status: array<string, mixed>, modules: list<InstalledModule>, issues: list<array<string, mixed>>, contributions: list<ContributionSummary>}  $data
     * @param  list<string>  $disabledDomains
     */
    private function buildBundle(string $key, array $data, array $disabledDomains): InstalledBundle
    {
        $status = $data['status'];
        $kind = $this->classifyKind($key, (string) $status['path']);
        $lifecycleName = $this->lifecycleName($kind, (string) $status['absolutePath']);

        return new InstalledBundle(
            key: $key,
            label: (string) $status['label'],
            kind: $kind,
            path: (string) $status['path'],
            hasGit: $status['branch'] !== null,
            repo: $status['repo'],
            branch: $status['branch'],
            commit: $status['current'],
            workingTree: $status['working_tree'],
            disabled: $kind === InstalledBundle::KIND_BUSINESS_DOMAIN
                && $lifecycleName !== null
                && in_array($lifecycleName, $disabledDomains, true),
            modules: $this->sortModules($data['modules']),
            dependencyIssues: $data['issues'],
            lifecycleName: $lifecycleName,
            contributions: $data['contributions'],
        );
    }

    /**
     * @param  list<string>  $sortedKeys  bundle keys ordered longest-path-first
     * @param  array<string, array{status: array<string, mixed>, modules: list<InstalledModule>, issues: list<array<string, mixed>>}>  $byKey
     */
    private function nearestBundleKey(string $manifestPath, array $sortedKeys, array $byKey): ?string
    {
        foreach ($sortedKeys as $key) {
            $bundlePath = $this->normalizePath((string) $byKey[$key]['status']['absolutePath']);

            if ($manifestPath === $bundlePath || str_starts_with($manifestPath, $bundlePath.'/')) {
                return $key;
            }
        }

        return null;
    }

    private function classifyKind(string $key, string $relativePath): string
    {
        if ($key === 'platform') {
            return InstalledBundle::KIND_PLATFORM;
        }

        $rel = trim(str_replace('\\', '/', $relativePath), '/');

        return match (true) {
            str_starts_with($rel, 'extensions/') => InstalledBundle::KIND_EXTENSION,
            str_starts_with($rel, 'app/Modules/') => count(explode('/', $rel)) >= 4
                ? InstalledBundle::KIND_SLOT_IMPLEMENTATION
                : InstalledBundle::KIND_BUSINESS_DOMAIN,
            default => InstalledBundle::KIND_PLATFORM,
        };
    }

    private function lifecycleName(string $kind, string $absolutePath): ?string
    {
        return in_array($kind, [InstalledBundle::KIND_BUSINESS_DOMAIN, InstalledBundle::KIND_EXTENSION], true)
            ? basename($absolutePath)
            : null;
    }

    /**
     * @param  list<InstalledModule>  $modules
     * @return list<InstalledModule>
     */
    private function sortModules(array $modules): array
    {
        usort($modules, fn (InstalledModule $a, InstalledModule $b): int => strcmp($a->label(), $b->label()));

        return array_values($modules);
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function relativePath(string $absolute): string
    {
        $base = $this->normalizePath(base_path());
        $norm = $this->normalizePath($absolute);

        if ($norm === $base) {
            return '.';
        }

        return str_starts_with($norm, $base.'/') ? substr($norm, strlen($base) + 1) : $norm;
    }

    private function reader(): ModuleManifestReader
    {
        return new ModuleManifestReader([
            app_path('Base'),
            app_path('Modules'),
            base_path('extensions'),
        ]);
    }
}
