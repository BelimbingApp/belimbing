<?php

namespace App\Base\Foundation\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\ModuleManifest\BelimbingAppCatalogService;
use App\Base\Foundation\ModuleManifest\ModuleManifest;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Admin plugin-manager screen.
 *
 * Per docs/plans/plugin-manager-ui.md:
 *  - Installed tab: every BLB module the runtime sees, with manifest
 *    data and dependency health.
 *  - Available tab: plugins discovered from the BelimbingApp org.
 *    Cached; refreshable; surfaces install commands as copyable text.
 *
 * Read-only by design — no install or migration actions trigger from
 * this screen.
 */
class PluginManager extends Component
{
    #[Url(as: 'tab')]
    public string $tab = 'installed';

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['installed', 'available'], true) ? $tab : 'installed';
    }

    public function refreshCatalog(): void
    {
        if (! $this->canManage()) {
            abort(403);
        }

        app(BelimbingAppCatalogService::class)->refresh();
        $this->tab = 'available';

        session()->flash('success', __('Catalog refreshed from GitHub.'));
    }

    public function render(): View
    {
        $reader = $this->reader();
        $manifests = $reader->all();
        $unmet = $reader->verifyRequiredModules($manifests);

        $byRole = [
            'source' => [],
            'plugin' => [],
            'unknown' => [],
        ];
        foreach ($manifests as $m) {
            $role = isset($byRole[$m->role]) ? $m->role : 'unknown';
            $byRole[$role][] = $m;
        }

        $catalog = app(BelimbingAppCatalogService::class);
        $available = $catalog->available();
        $installedModuleIds = collect($manifests)
            ->map(fn (ModuleManifest $m): string => $m->module)
            ->filter()
            ->all();

        return view('livewire.base.foundation.plugin-manager', [
            'manifests' => $manifests,
            'byRole' => $byRole,
            'unmet' => $unmet,
            'requiredCount' => $this->countRequired($manifests),
            'optionalCount' => $this->countOptional($manifests),
            'catalogEntries' => $available,
            'installedModuleIds' => $installedModuleIds,
            'catalogLastFetchedAt' => $catalog->lastFetchedAt(),
            'canManage' => $this->canManage(),
        ]);
    }

    private function reader(): ModuleManifestReader
    {
        return new ModuleManifestReader([
            base_path('app/Base'),
            base_path('app/Modules/Core'),
            base_path('app/Modules/Commerce'),
            base_path('app/Modules/Operation'),
            base_path('app/Modules/People'),
            base_path('extensions'),
        ]);
    }

    /**
     * @param  list<ModuleManifest>  $manifests
     */
    private function countRequired(array $manifests): int
    {
        $count = 0;
        foreach ($manifests as $m) {
            $count += count($m->requiresModules);
        }

        return $count;
    }

    /**
     * @param  list<ModuleManifest>  $manifests
     */
    private function countOptional(array $manifests): int
    {
        $count = 0;
        foreach ($manifests as $m) {
            $count += count($m->optionalModules);
        }

        return $count;
    }

    private function canManage(): bool
    {
        $user = Auth::user();
        if ($user === null) {
            return false;
        }

        return app(AuthorizationService::class)
            ->can(Actor::forUser($user), 'admin.system.plugins.manage')
            ->allowed;
    }
}
