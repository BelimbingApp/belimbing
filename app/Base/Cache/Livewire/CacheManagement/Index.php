<?php

namespace App\Base\Cache\Livewire\CacheManagement;

use App\Base\Authz\Livewire\Concerns\ChecksCapabilityAuthorization;
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
        $this->notify(__('All cache flushed successfully.'));
    }

    public function clearMenuCache(): void
    {
        if (! $this->checkCapability('admin.system.cache.manage')) {
            return;
        }

        app(MenuRegistry::class)->clear();
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
