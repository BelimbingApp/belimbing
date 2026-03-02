<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Cache\Controllers;

use App\Base\Menu\MenuRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class CacheController
{
    /**
     * Show cache configuration and actions.
     */
    public function index(): View
    {
        $driver = config('cache.default');
        $storeConfig = config('cache.stores.'.$driver, []);

        return view('admin.system.cache.index', compact('driver', 'storeConfig'));
    }

    /**
     * Clear cache using the selected action.
     */
    public function clear(Request $request): RedirectResponse
    {
        $action = $request->string('action')->toString();

        if ($action === 'flush') {
            Cache::flush();
            session()->flash('success', __('All cache flushed successfully.'));
        }

        if ($action === 'menu') {
            app(MenuRegistry::class)->clear();
            session()->flash('success', __('Menu cache cleared successfully.'));
        }

        return redirect()->route('admin.system.cache.index');
    }
}
