<?php

namespace App\Base\Foundation\Livewire;

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\Livewire\Concerns\AuthorizesDomainManagement;
use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Base\Foundation\ModuleManifest\BelimbingAppCatalogService;
use App\Base\Foundation\ModuleManifest\ModuleManifest;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Foundation\Services\DomainInstaller;
use App\Base\Foundation\Services\ExtensionInstaller;
use App\Base\Software\Inventory\InstalledSource;
use App\Base\Software\Services\ExtensionCatalogDiscovery;
use App\Base\Software\Services\SoftwareInventoryService;
use App\Base\Support\Str;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Defer;
use Livewire\Component;

/**
 * Admin Domains screen (admin/system/software/domains).
 *
 * The single place to manage installed software. The Installed tab lists
 * business domains with lifecycle actions (install / enable / disable /
 * uninstall) and drills down to each domain's module manifests; the Available
 * tab lists installable domains plus the BelimbingApp catalog.
 *
 * Merges the former Sources (inventory + catalog) and Business Domains
 * (lifecycle) screens. Lifecycle goes through DomainInstaller; manifest detail
 * through ModuleManifestReader; the catalog through BelimbingAppCatalogService.
 */
#[Defer]
class Domains extends Component
{
    use AuthorizesDomainManagement;
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

        $this->redirectRoute('admin.system.software.domains.index');
    }

    public function installExtension(string $folder, ExtensionInstaller $installer, ExtensionCatalogDiscovery $discovery): void
    {
        $this->authorizeManage();

        // Clone + migrate outlive a default PHP execution window.
        set_time_limit(0);

        // The wire call only ever carries the folder key; the repo URL resolves
        // server-side — config catalog first (inside install()), else a discovered
        // candidate from a token-holding owner. No free-text URL path.
        $repo = is_array(config('extensions.catalog.'.$folder))
            ? null
            : ($discovery->candidate($folder)['repo'] ?? null);

        $result = $installer->install($folder, $repo);

        session()->flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? __('Extension :folder installed. Its modules are live from the next page load.', ['folder' => $folder])
            : __('Extension :folder install failed.', ['folder' => $folder]));
        session()->flash('command-log', $result['log']);

        $this->redirectRoute('admin.system.software.domains.index');
    }

    public function disable(string $domain, DomainInstaller $installer): void
    {
        $this->authorizeManage();

        $reloadLog = $installer->disable($domain);

        session()->flash('success', __(':domain disabled. Its code stays on disk and its data stays claimed; discovery skips it from the next page load.', ['domain' => $domain]));
        $this->flashReloadLog($reloadLog);

        $this->redirectRoute('admin.system.software.domains.index');
    }

    public function enable(string $domain, DomainInstaller $installer): void
    {
        $this->authorizeManage();

        $reloadLog = $installer->enable($domain);

        session()->flash('success', __(':domain enabled.', ['domain' => $domain]));
        $this->flashReloadLog($reloadLog);

        $this->redirectRoute('admin.system.software.domains.index');
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

        $this->redirectRoute('admin.system.software.domains.index');
    }

    public function refreshCatalog(): void
    {
        $this->authorizeManage();

        app(BelimbingAppCatalogService::class)->refresh();
        $this->tab = 'available';

        $this->notify(__('Catalog refreshed from GitHub.'));
    }

    public function render(DomainInstaller $installer, ExtensionInstaller $extensions, SoftwareInventoryService $inventory, ExtensionCatalogDiscovery $discovery): View
    {
        $reader = $this->reader();
        $enabledManifests = $reader->all();
        $installedManifests = $reader->allIncludingDisabledDomains();
        $dependencyIssues = $reader->dependencyIssues($enabledManifests);
        $manifestsByDomain = $this->manifestsByDomain($installedManifests);

        // Software Inventory read model (grouped by software source). Drives the
        // Platform Baseline (Base + Core) and any nested module/slot source cards, and
        // lets each domain/extension card show its source's repo / branch / commit identity.
        $sources = $inventory->installedSources();
        $platformSource = collect($sources)->firstWhere('kind', InstalledSource::KIND_PLATFORM);
        $slotSources = collect($sources)
            ->where('kind', InstalledSource::KIND_SLOT_IMPLEMENTATION)
            ->values()
            ->all();
        $sourcesByLifecycle = collect($sources)
            ->filter(fn (InstalledSource $source): bool => $source->lifecycleName !== null)
            ->keyBy('lifecycleName')
            ->all();
        $driftedAddInSources = collect($sources)
            ->filter(fn (InstalledSource $source): bool => $source->kind !== InstalledSource::KIND_PLATFORM
                && ($source->isDirty() || $source->unpushed() > 0))
            ->values()
            ->all();

        $installed = $installer->installed(includeGit: false);
        foreach ($installed as $index => $domain) {
            $installed[$index]['manifests'] = $manifestsByDomain[$this->domainManifestKey($domain['name'])] ?? [];
            $installed[$index]['git'] = $this->gitStateForSource(
                $sourcesByLifecycle[$domain['name']] ?? null,
                $domain['git'],
            );
        }

        $installedExtensions = $extensions->installed(includeGit: false);
        foreach ($installedExtensions as $index => $extension) {
            $installedExtensions[$index]['manifests'] = $manifestsByDomain[$this->domainManifestKey($extension['name'])] ?? [];
            $installedExtensions[$index]['git'] = $this->gitStateForSource(
                $sourcesByLifecycle[$extension['name']] ?? null,
                $extension['git'],
            );
        }

        $discovered = $discovery->discover();
        $catalog = app(BelimbingAppCatalogService::class);
        $installedModuleIds = collect($installedManifests)
            ->map(fn (ModuleManifest $manifest): string => $manifest->module)
            ->filter()
            ->all();

        return view('livewire.base.foundation.domains', [
            'installed' => $installed,
            'extensions' => $installedExtensions,
            'platformSource' => $platformSource,
            'slotSources' => $slotSources,
            'sourcesByLifecycle' => $sourcesByLifecycle,
            'driftedAddInSources' => $driftedAddInSources,
            'available' => $installer->available(),
            'availableExtensions' => $this->mergeAvailableExtensions($extensions, $discovered),
            'extensionDiscoveryErrors' => $discovered['errors'],
            'dependencyIssues' => $dependencyIssues,
            'catalogEntries' => $catalog->available(),
            'installedModuleIds' => $installedModuleIds,
            'catalogLastFetchedAt' => $catalog->lastFetchedAt(),
            'canManage' => $this->canManage(),
        ]);
    }

    /**
     * Curated catalog entries plus discovered candidates, each tagged with its
     * source. A config catalog key always wins over a discovered candidate
     * (pin/override), and installed extensions never reappear.
     *
     * @param  array{candidates: array<string, array{repo: string, description: string, owner: string, has_token: bool}>, errors: array<string, string>}  $discovered
     * @return array<string, array{repo: string, description: string, owner: string|null, has_token: bool, source: string}>
     */
    private function mergeAvailableExtensions(ExtensionInstaller $extensions, array $discovered): array
    {
        $availableExtensions = array_map(
            fn (array $entry): array => $entry + ['source' => 'curated'],
            $extensions->available(),
        );

        foreach ($discovered['candidates'] as $folder => $candidate) {
            if (is_array(config('extensions.catalog.'.$folder)) || $extensions->isInstalled($folder)) {
                continue;
            }

            $availableExtensions[$folder] = $candidate + ['source' => 'discovered'];
        }

        return $availableExtensions;
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
            ApplicationTopology::baseRoot(),
            ApplicationTopology::coreRoot(),
            ApplicationTopology::domainsRoot(),
            ApplicationTopology::extensionsRoot(),
        ]);
    }

    /**
     * @param  array{hasGit: bool, dirty: bool, unpushed: int}  $fallback
     * @return array{hasGit: bool, dirty: bool, unpushed: int}
     */
    private function gitStateForSource(?InstalledSource $source, array $fallback): array
    {
        if ($source === null) {
            return $fallback;
        }

        return [
            'hasGit' => $source->hasGit,
            'dirty' => $source->isDirty(),
            'unpushed' => $source->unpushed(),
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
}
