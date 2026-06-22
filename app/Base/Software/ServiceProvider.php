<?php

namespace App\Base\Software;

use App\Base\Software\Console\Commands\DomainRuntimeReloadCommand;
use App\Base\Software\Services\InventoryContributionDiscoveryService;
use App\Base\Software\Services\InventoryContributionRegistry;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->commands([
            DomainRuntimeReloadCommand::class,
        ]);

        $this->app->singleton(InventoryContributionRegistry::class);
        $this->app->singleton(InventoryContributionDiscoveryService::class);
    }

    public function boot(InventoryContributionDiscoveryService $contributions): void
    {
        $contributions->discoverInto($this->app->make(InventoryContributionRegistry::class));
    }
}
