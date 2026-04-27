<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Cache\Livewire\CacheManagement;

use App\Base\Menu\MenuRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class Index extends Component
{
    public function flushAll(): void
    {
        Cache::flush();
        session()->flash('success', __('All cache flushed successfully.'));
    }

    public function clearMenuCache(): void
    {
        app(MenuRegistry::class)->clear();
        session()->flash('success', __('Menu cache cleared successfully.'));
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
