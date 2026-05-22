<?php

namespace App\Modules\Commerce\Plugins;

use App\Modules\Commerce\Plugins\Services\CommercePluginDiscoveryService;
use App\Modules\Commerce\Plugins\Services\CommercePluginRegistry;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CommercePluginRegistry::class);
        $this->app->singleton(CommercePluginDiscoveryService::class);
    }

    public function boot(CommercePluginDiscoveryService $discovery, CommercePluginRegistry $registry): void
    {
        $discovery->discoverInto($registry);
    }
}
