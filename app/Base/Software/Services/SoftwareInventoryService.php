<?php

namespace App\Base\Software\Services;

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\ModuleManifest\ModuleManifest;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Foundation\Services\DomainState;
use App\Base\Software\Inventory\ContributionSummary;
use App\Base\Software\Inventory\InstalledModule;
use App\Base\Software\Inventory\InstalledSource;

/**
 * Software Inventory read model: the installed view grouped by software source.
 *
 * Joins git-backed Source discovery (SoftwareSourceRepository) with module
 * manifests (ModuleManifestReader) so the UI can say which Sources are installed,
 * which Modules each contains, and each Source's git/dependency health — without the
 * page re-deriving the filesystem. Each Module is attributed to its *nearest* Source
 * root (the longest source path that contains it); Platform Baseline modules
 * (Base/Core) and other non-nested code fall back to the platform Source.
 */
class SoftwareInventoryService
{
    private const STATUS_DIAGNOSTIC_GIT_TIMEOUT_SECONDS = 3;

    public function __construct(
        private readonly SoftwareSourceRepository $sources,
        private readonly InventoryContributionRegistry $contributions,
    ) {}

    /**
     * @return list<InstalledSource>
     */
    public function installedSources(): array
    {
        $reader = $this->reader();

        return $this->assemble(
            $this->sources->localStatus(),
            $reader->allIncludingDisabledDomains(),
            $reader->dependencyIssues($reader->all()),
            array_values(DomainState::disabled()),
            $this->contributions->contributions(),
        );
    }

    /**
     * Same grouping model as installedSources(), but avoids the expensive platform
     * working-tree scan. Status-bar diagnostics only care about add-in drift and
     * dependency health, so platform checkout dirtiness is intentionally out of scope.
     *
     * @return list<InstalledSource>
     */
    public function installedSourcesForStatusDiagnostics(): array
    {
        $reader = $this->reader();

        return $this->assemble(
            $this->sources->localStatus(
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
     * @param  list<array<string, mixed>>  $sourceStatuses  rows from SoftwareSourceRepository::localStatus()
     * @param  list<ModuleManifest>  $manifests  every installed manifest, including disabled domains
     * @param  list<array<string, mixed>>  $dependencyIssues  rows from ModuleManifestReader::dependencyIssues()
     * @param  list<string>  $disabledDomains  disabled optional Domain names
     * @param  list<ContributionSummary>  $contributions  discovered runtime contributions
     * @return list<InstalledSource>
     */
    public function assemble(array $sourceStatuses, array $manifests, array $dependencyIssues, array $disabledDomains = [], array $contributions = []): array
    {
        $byKey = [];
        foreach ($sourceStatuses as $status) {
            $byKey[$status['key']] = ['status' => $status, 'modules' => [], 'issues' => [], 'contributions' => []];
        }

        // Match each module to the deepest source that contains it: longest path first.
        $sortedKeys = array_keys($byKey);
        usort($sortedKeys, fn (string $a, string $b): int => strlen($this->normalizePath((string) $byKey[$b]['status']['absolutePath']))
            <=> strlen($this->normalizePath((string) $byKey[$a]['status']['absolutePath'])));

        $moduleKeys = $this->attachModules($byKey, $manifests, $sortedKeys);

        // Dependency issues surface at the row of the Source that owns the requiring module.
        foreach ($dependencyIssues as $issue) {
            $key = $moduleKeys['manifest'][$issue['requiring'] ?? ''] ?? null;

            if ($key !== null) {
                $byKey[$key]['issues'][] = $issue;
            }
        }

        $this->attachContributions($byKey, $contributions, $moduleKeys['module']);

        $sources = [];
        foreach ($byKey as $key => $data) {
            $sources[] = $this->buildSource((string) $key, $data, $disabledDomains);
        }

        return $sources;
    }

    /**
     * Place each manifest's Module under its nearest Source and return the
     * manifest-name and module-id → source-key maps used for attribution.
     *
     * @param  array<string, array<string, mixed>>  $byKey
     * @param  list<ModuleManifest>  $manifests
     * @param  list<string>  $sortedKeys  source keys ordered longest-path-first
     * @return array{manifest: array<string, string>, module: array<string, string>}
     */
    private function attachModules(array &$byKey, array $manifests, array $sortedKeys): array
    {
        $manifestSourceKey = [];
        $moduleSourceKey = [];

        foreach ($manifests as $manifest) {
            $sourceKey = $this->nearestSourceKey($this->normalizePath($manifest->path), $sortedKeys, $byKey);

            if ($sourceKey === null) {
                continue;
            }

            $byKey[$sourceKey]['modules'][] = new InstalledModule(
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

            $manifestSourceKey[$manifest->name] = $sourceKey;

            if ($manifest->module !== '') {
                $moduleSourceKey[$manifest->module] = $sourceKey;
            }
        }

        return ['manifest' => $manifestSourceKey, 'module' => $moduleSourceKey];
    }

    /**
     * Surface contributions under the Source that delivers the providing module —
     * by exact module manifest when available, else by the module's domain source
     * (so a domain like Commerce that ships no per-module manifests still attributes).
     *
     * @param  array<string, array<string, mixed>>  $byKey
     * @param  list<ContributionSummary>  $contributions
     * @param  array<string, string>  $moduleSourceKey
     */
    private function attachContributions(array &$byKey, array $contributions, array $moduleSourceKey): void
    {
        $domainKeyByName = [];
        foreach ($byKey as $sourceKey => $data) {
            $kind = $this->classifyKind((string) $sourceKey, (string) $data['status']['path']);
            $lifecycleName = $this->lifecycleName($kind, (string) $data['status']['absolutePath']);

            if ($kind === InstalledSource::KIND_DOMAIN && $lifecycleName !== null) {
                $domainKeyByName[strtolower($lifecycleName)] = $sourceKey;
            }
        }

        foreach ($contributions as $contribution) {
            $module = $contribution->attributedModule();
            $key = $moduleSourceKey[$module]
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
    private function buildSource(string $key, array $data, array $disabledDomains): InstalledSource
    {
        $status = $data['status'];
        $kind = $this->classifyKind($key, (string) $status['path']);
        $lifecycleName = $this->lifecycleName($kind, (string) $status['absolutePath']);

        return new InstalledSource(
            key: $key,
            label: (string) $status['label'],
            kind: $kind,
            path: (string) $status['path'],
            hasGit: $status['branch'] !== null,
            repo: $status['repo'],
            branch: $status['branch'],
            commit: $status['current'],
            workingTree: $status['working_tree'],
            disabled: $kind === InstalledSource::KIND_DOMAIN
                && $lifecycleName !== null
                && in_array($lifecycleName, $disabledDomains, true),
            modules: $this->sortModules($data['modules']),
            dependencyIssues: $data['issues'],
            lifecycleName: $lifecycleName,
            contributions: $data['contributions'],
        );
    }

    /**
     * @param  list<string>  $sortedKeys  source keys ordered longest-path-first
     * @param  array<string, array{status: array<string, mixed>, modules: list<InstalledModule>, issues: list<array<string, mixed>>}>  $byKey
     */
    private function nearestSourceKey(string $manifestPath, array $sortedKeys, array $byKey): ?string
    {
        foreach ($sortedKeys as $key) {
            $sourcePath = $this->normalizePath((string) $byKey[$key]['status']['absolutePath']);

            if ($manifestPath === $sourcePath || str_starts_with($manifestPath, $sourcePath.'/')) {
                return $key;
            }
        }

        return null;
    }

    private function classifyKind(string $key, string $relativePath): string
    {
        if ($key === 'platform') {
            return InstalledSource::KIND_PLATFORM;
        }

        $rel = trim(str_replace('\\', '/', $relativePath), '/');

        return match (true) {
            ApplicationTopology::belongsToRoot($rel, ApplicationTopology::EXTENSIONS) => InstalledSource::KIND_EXTENSION,
            ApplicationTopology::belongsToRoot($rel, ApplicationTopology::DOMAINS) => count(explode('/', $rel)) >= 4
                ? InstalledSource::KIND_SLOT_IMPLEMENTATION
                : InstalledSource::KIND_DOMAIN,
            default => InstalledSource::KIND_PLATFORM,
        };
    }

    private function lifecycleName(string $kind, string $absolutePath): ?string
    {
        return in_array($kind, [InstalledSource::KIND_DOMAIN, InstalledSource::KIND_EXTENSION], true)
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
            ApplicationTopology::baseRoot(),
            ApplicationTopology::coreRoot(),
            ApplicationTopology::domainsRoot(),
            ApplicationTopology::extensionsRoot(),
        ]);
    }
}
