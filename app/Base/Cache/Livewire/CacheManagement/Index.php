<?php

namespace App\Base\Cache\Livewire\CacheManagement;

use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Base\Menu\MenuRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class Index extends Component
{
    use InteractsWithNotifications;

    public function flushAll(): void
    {
        Cache::flush();
        $this->notify(__('All cache flushed successfully.'));
    }

    public function clearMenuCache(): void
    {
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
