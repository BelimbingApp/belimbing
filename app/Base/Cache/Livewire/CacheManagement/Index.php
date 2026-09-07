<?php

namespace App\Base\Cache\Livewire\CacheManagement;

use App\Base\Authz\Livewire\Concerns\ChecksCapabilityAuthorization;
use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Menu\MenuRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class Index extends Component
{
    use ChecksCapabilityAuthorization;

    public function flushAll(): void
    {
        if (! $this->checkCapability('admin.system.cache.manage')) {
            return;
        }

        Cache::flush();

        app(SemanticActionRecorder::class)->record(
            event: 'system.cache.flushed',
            summary: __('Flushed all application cache'),
            source: __('Cache'),
            subject: ['name' => 'cache', 'id' => 'all', 'identifier' => 'all'],
            surface: 'admin.system.cache',
            uiElement: __('Flush all'),
        );

        $this->notify(__('All cache flushed successfully.'));
    }

    public function clearMenuCache(): void
    {
        if (! $this->checkCapability('admin.system.cache.manage')) {
            return;
        }

        app(MenuRegistry::class)->clear();

        app(SemanticActionRecorder::class)->record(
            event: 'system.menu_cache.cleared',
            summary: __('Cleared menu cache'),
            source: __('Cache'),
            subject: ['name' => 'menu_cache', 'id' => 'menu', 'identifier' => 'menu'],
            surface: 'admin.system.cache',
            uiElement: __('Clear menu cache'),
        );

        $this->notify(__('Menu cache cleared successfully.'));
    }

    public function render(): View
    {
        $driver = config('cache.default');
        $storeConfig = config('cache.stores.'.$driver, []);

        return view('livewire.admin.system.cache.index', [
            'driver' => $driver,
            'storeConfig' => $storeConfig,
        ]);
    }
}
