<?php

namespace App\Base\Foundation\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Base\Foundation\ModuleManifest\BelimbingAppCatalogService;
use App\Base\Foundation\ModuleManifest\ModuleManifest;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Foundation\Services\DomainInstaller;
use App\Base\Support\Str;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
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
class Modules extends Component
{
    use InteractsWithNotifications;

    #[Url(as: 'tab')]
    public string $tab = 'installed';

    /**
     * Domain whose uninstall confirmation panel is open.
     */
    public ?string $uninstallTarget = null;

    /**
     * GitHub-style typed confirmation for uninstall.
     */
    public string $uninstallPhrase = '';

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

    public function openUninstall(string $domain): void
    {
        $this->authorizeManage();

        $this->uninstallTarget = $domain;
        $this->uninstallPhrase = '';
        $this->resetErrorBag('uninstallPhrase');
    }

    public function cancelUninstall(): void
    {
        $this->reset('uninstallTarget', 'uninstallPhrase');
        $this->resetErrorBag('uninstallPhrase');
    }

    public function uninstall(DomainInstaller $installer): void
    {
        $this->authorizeManage();

        $domain = $this->uninstallTarget;

        if ($domain === null) {
            return;
        }

        $dropTables = $this->parseUninstallPhrase($domain);

        if ($dropTables === null) {
            $this->addError('uninstallPhrase', __('Type the exact phrase to confirm.'));

            return;
        }

        $result = $installer->uninstall($domain, $dropTables);

        session()->flash('success', $dropTables
            ? __(':domain uninstalled. :tables table(s) dropped, :ledger migration record(s) removed, :settings setting row(s) deleted.', [
                'domain' => $domain,
                'tables' => count($result['droppedTables']),
                'ledger' => $result['prunedLedger'],
                'settings' => $result['deletedSettings'],
            ])
            : __(':domain uninstalled. Its database state was kept; reinstalling adopts it again, or clean it up under Database Residue.', ['domain' => $domain]));

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

    public function render(DomainInstaller $installer): View
    {
        $reader = $this->reader();
        $enabledManifests = $reader->all();
        $installedManifests = $reader->allIncludingDisabledDomains();
        $dependencyIssues = $reader->dependencyIssues($enabledManifests);
        $manifestsByDomain = $this->manifestsByDomain($installedManifests);

        $installed = $installer->installed();
        foreach ($installed as $index => $domain) {
            $installed[$index]['manifests'] = $manifestsByDomain[$this->domainManifestKey($domain['name'])] ?? [];
        }

        $catalog = app(BelimbingAppCatalogService::class);
        $installedModuleIds = collect($installedManifests)
            ->map(fn (ModuleManifest $manifest): string => $manifest->module)
            ->filter()
            ->all();

        return view('livewire.base.foundation.modules', [
            'installed' => $installed,
            'available' => $installer->available(),
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
