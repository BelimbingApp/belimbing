<?php

namespace App\Base\Foundation\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Base\Foundation\ModuleManifest\BelimbingAppCatalogService;
use App\Base\Foundation\ModuleManifest\ModuleManifest;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Foundation\Services\DomainInstaller;
use App\Base\Foundation\Services\ExtensionInstaller;
use App\Base\Software\Inventory\InstalledBundle;
use App\Base\Software\Services\SoftwareInventoryService;
use App\Base\Support\Str;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Defer;
use Livewire\Component;

/**
 * Admin Modules screen (admin/system/software/modules).
 *
 * The single place to manage installed software. The Installed tab lists
 * business domains with lifecycle actions (install / enable / disable /
 * uninstall) and drills down to each domain's module manifests; the Available
 * tab lists installable domains plus the BelimbingApp catalog.
 *
 * Merges the former Bundles (inventory + catalog) and Business Domains
 * (lifecycle) screens. Lifecycle goes through DomainInstaller; manifest detail
 * through ModuleManifestReader; the catalog through BelimbingAppCatalogService.
 */
#[Defer]
class Modules extends Component
{
    use InteractsWithNotifications;

    /**
     * Active tab. The URL query string is owned by the x-ui.tabs primitive
     * (persistence="query"); this property stays in sync via setTab() and is
     * seeded from the request on mount for deep links.
     */
    public string $tab = 'installed';

    /**
     * Domain whose uninstall confirmation panel is open.
     */
    public ?string $uninstallTarget = null;

    /**
     * GitHub-style typed confirmation for uninstall.
     */
    public string $uninstallPhrase = '';

    /**
     * What the open uninstall panel targets: 'domain' or 'extension'.
     */
    public string $uninstallKind = 'domain';

    public function mount(?string $tab = null): void
    {
        $resolved = $tab ?? request()->query('tab') ?? $this->tabFromReferer();

        $this->tab = $resolved === 'available' ? 'available' : 'installed';
    }

    /**
     * The page renders #[Defer] (the inventory's nested git scans took ~5 s
     * synchronously), so mount() runs in a follow-up ajax request where the
     * original ?tab deep link only survives in the Referer — the same
     * fallback Livewire's own #[Url] hydration uses.
     */
    private function tabFromReferer(): ?string
    {
        parse_str((string) parse_url((string) request()->header('referer'), PHP_URL_QUERY), $query);

        return is_string($query['tab'] ?? null) ? $query['tab'] : null;
    }

    public function placeholder(): View
    {
        // Outside the livewire. view namespace on purpose: component-name
        // discovery keys off the first view('livewire.*') string in the file
        // (see ComponentDiscoveryService), which must stay the render() view.
        return view('placeholders.page');
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['installed', 'available'], true) ? $tab : 'installed';
    }

    public function install(string $domain, DomainInstaller $installer): void
    {
        $this->authorizeManage();

        // Clone + migrate outlive a default PHP execution window.
        set_time_limit(0);

        $result = $installer->install($domain);

        session()->flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? __(':domain installed. Its menus and routes are live from the next page load.', ['domain' => $domain])
            : __(':domain install failed.', ['domain' => $domain]));
        session()->flash('command-log', $result['log']);

        $this->redirectRoute('admin.system.software.modules.index');
    }

    public function installExtension(string $folder, ExtensionInstaller $installer): void
    {
        $this->authorizeManage();

        // Clone + migrate outlive a default PHP execution window.
        set_time_limit(0);

        $result = $installer->install($folder);

        session()->flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? __('Extension :folder installed. Its modules are live from the next page load.', ['folder' => $folder])
            : __('Extension :folder install failed.', ['folder' => $folder]));
        session()->flash('command-log', $result['log']);

        $this->redirectRoute('admin.system.software.modules.index');
    }

    public function disable(string $domain, DomainInstaller $installer): void
    {
        $this->authorizeManage();

        $reloadLog = $installer->disable($domain);

        session()->flash('success', __(':domain disabled. Its code stays on disk and its data stays claimed; discovery skips it from the next page load.', ['domain' => $domain]));
        $this->flashReloadLog($reloadLog);

        $this->redirectRoute('admin.system.software.modules.index');
    }

    public function enable(string $domain, DomainInstaller $installer): void
    {
        $this->authorizeManage();

        $reloadLog = $installer->enable($domain);

        session()->flash('success', __(':domain enabled.', ['domain' => $domain]));
        $this->flashReloadLog($reloadLog);

        $this->redirectRoute('admin.system.software.modules.index');
    }

    public function openUninstall(string $target, string $kind = 'domain'): void
    {
        $this->authorizeManage();

        $this->uninstallTarget = $target;
        $this->uninstallKind = in_array($kind, ['domain', 'extension'], true) ? $kind : 'domain';
        $this->uninstallPhrase = '';
        $this->resetErrorBag('uninstallPhrase');
    }

    public function cancelUninstall(): void
    {
        $this->reset('uninstallTarget', 'uninstallPhrase', 'uninstallKind');
        $this->resetErrorBag('uninstallPhrase');
    }

    public function uninstall(DomainInstaller $domains, ExtensionInstaller $extensions): void
    {
        $this->authorizeManage();

        $target = $this->uninstallTarget;

        if ($target === null) {
            return;
        }

        $dropTables = $this->parseUninstallPhrase($target);

        if ($dropTables === null) {
            $this->addError('uninstallPhrase', __('Type the exact phrase to confirm.'));

            return;
        }

        $result = $this->uninstallKind === 'extension'
            ? $extensions->uninstall($target, $dropTables)
            : $domains->uninstall($target, $dropTables);

        session()->flash('success', $dropTables
            ? __(':name uninstalled. :tables table(s) dropped, :ledger migration record(s) removed, :settings setting row(s) deleted.', [
                'name' => $target,
                'tables' => count($result['droppedTables']),
                'ledger' => $result['prunedLedger'],
                'settings' => $result['deletedSettings'],
            ])
            : __(':name uninstalled. Its database state was kept; reinstalling adopts it again, or clean it up under Database Residue.', ['name' => $target]));

        $this->flashReloadLog($result['reloadLog']);

        $this->redirectRoute('admin.system.software.modules.index');
    }

    public function refreshCatalog(): void
    {
        $this->authorizeManage();

        app(BelimbingAppCatalogService::class)->refresh();
        $this->tab = 'available';

        $this->notify(__('Catalog refreshed from GitHub.'));
    }

    public function render(DomainInstaller $installer, ExtensionInstaller $extensions, SoftwareInventoryService $inventory): View
    {
        $reader = $this->reader();
        $enabledManifests = $reader->all();
        $installedManifests = $reader->allIncludingDisabledDomains();
        $dependencyIssues = $reader->dependencyIssues($enabledManifests);
        $manifestsByDomain = $this->manifestsByDomain($installedManifests);

        // Software Inventory read model (grouped by Distribution Bundle). Drives the
        // Platform Baseline (Base + Core) and any nested module/slot bundle cards, and
        // lets each domain/extension card show its bundle's repo / branch / commit identity.
        $bundles = $inventory->installedBundles();
        $platformBundle = collect($bundles)->firstWhere('kind', InstalledBundle::KIND_PLATFORM);
        $slotBundles = collect($bundles)
            ->where('kind', InstalledBundle::KIND_SLOT_IMPLEMENTATION)
            ->values()
            ->all();
        $bundlesByLifecycle = collect($bundles)
            ->filter(fn (InstalledBundle $bundle): bool => $bundle->lifecycleName !== null)
            ->keyBy('lifecycleName')
            ->all();
        $driftedAddInBundles = collect($bundles)
            ->filter(fn (InstalledBundle $bundle): bool => $bundle->kind !== InstalledBundle::KIND_PLATFORM
                && ($bundle->isDirty() || $bundle->unpushed() > 0))
            ->values()
            ->all();

        $installed = $installer->installed(includeGit: false);
        foreach ($installed as $index => $domain) {
            $installed[$index]['manifests'] = $manifestsByDomain[$this->domainManifestKey($domain['name'])] ?? [];
            $installed[$index]['git'] = $this->gitStateForBundle(
                $bundlesByLifecycle[$domain['name']] ?? null,
                $domain['git'],
            );
        }

        $installedExtensions = $extensions->installed(includeGit: false);
        foreach ($installedExtensions as $index => $extension) {
            $installedExtensions[$index]['manifests'] = $manifestsByDomain[$this->domainManifestKey($extension['name'])] ?? [];
            $installedExtensions[$index]['git'] = $this->gitStateForBundle(
                $bundlesByLifecycle[$extension['name']] ?? null,
                $extension['git'],
            );
        }

        $catalog = app(BelimbingAppCatalogService::class);
        $installedModuleIds = collect($installedManifests)
            ->map(fn (ModuleManifest $manifest): string => $manifest->module)
            ->filter()
            ->all();

        return view('livewire.base.foundation.modules', [
            'installed' => $installed,
            'extensions' => $installedExtensions,
            'platformBundle' => $platformBundle,
            'slotBundles' => $slotBundles,
            'bundlesByLifecycle' => $bundlesByLifecycle,
            'driftedAddInBundles' => $driftedAddInBundles,
            'available' => $installer->available(),
            'availableExtensions' => $extensions->available(),
            'dependencyIssues' => $dependencyIssues,
            'catalogEntries' => $catalog->available(),
            'installedModuleIds' => $installedModuleIds,
            'catalogLastFetchedAt' => $catalog->lastFetchedAt(),
            'canManage' => $this->canManage(),
        ]);
    }

    /**
     * @param  list<ModuleManifest>  $manifests
     * @return array<string, list<ModuleManifest>>
     */
    private function manifestsByDomain(array $manifests): array
    {
        $manifestsByDomain = [];

        foreach ($manifests as $manifest) {
            $domainKey = strtolower(explode('/', $manifest->module)[0] ?? '');
            if ($domainKey !== '') {
                $manifestsByDomain[$domainKey][] = $manifest;
            }
        }

        return $manifestsByDomain;
    }

    private function reader(): ModuleManifestReader
    {
        return new ModuleManifestReader([
            app_path('Base'),
            app_path('Modules'),
            base_path('extensions'),
        ]);
    }

    /**
     * @param  array{hasGit: bool, dirty: bool, unpushed: int}  $fallback
     * @return array{hasGit: bool, dirty: bool, unpushed: int}
     */
    private function gitStateForBundle(?InstalledBundle $bundle, array $fallback): array
    {
        if ($bundle === null) {
            return $fallback;
        }

        return [
            'hasGit' => $bundle->hasGit,
            'dirty' => $bundle->isDirty(),
            'unpushed' => $bundle->unpushed(),
        ];
    }

    /**
     * Map the typed phrase to the uninstall mode; null means no match.
     */
    private function parseUninstallPhrase(string $domain): ?bool
    {
        $name = strtolower($domain);

        return match (trim($this->uninstallPhrase)) {
            "uninstall {$name}" => false,
            "uninstall {$name} and drop all tables" => true,
            default => null,
        };
    }

    private function domainManifestKey(string $domain): string
    {
        return strtoupper($domain) === $domain
            ? strtolower($domain)
            : Str::pascalToKebab($domain);
    }

    private function authorizeManage(): void
    {
        if (! $this->canManage()) {
            abort(403);
        }
    }

    private function canManage(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return app(AuthorizationService::class)
            ->can(Actor::forUser($user), 'admin.system.software.modules.manage')
            ->allowed;
    }

    /**
     * @param  list<string>  $log
     */
    private function flashReloadLog(array $log): void
    {
        if ($log !== []) {
            session()->flash('command-log', implode("\n", $log));
        }
    }
}
