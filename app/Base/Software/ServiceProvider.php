<?php

namespace App\Base\Software;

use App\Base\Software\Console\Commands\DomainRuntimeReloadCommand;
use App\Base\Software\Services\FrankenPhpWorkerStatusDiagnosticProvider;
use App\Base\Software\Services\InventoryContributionDiscoveryService;
use App\Base\Software\Services\InventoryContributionRegistry;
use App\Base\Software\Services\SoftwareInventoryStatusDiagnosticProvider;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
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
        $this->app->singleton(FrankenPhpWorkerStatusDiagnosticProvider::class);
        $this->app->singleton(SoftwareInventoryStatusDiagnosticProvider::class);
        $this->app->tag(FrankenPhpWorkerStatusDiagnosticProvider::class, StatusBarDiagnosticProvider::CONTAINER_TAG);
        $this->app->tag(SoftwareInventoryStatusDiagnosticProvider::class, StatusBarDiagnosticProvider::CONTAINER_TAG);
    }

    public function boot(InventoryContributionDiscoveryService $contributions): void
    {
        $contributions->discoverInto($this->app->make(InventoryContributionRegistry::class));
    }
}
