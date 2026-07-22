<?php

namespace App\Base\Software;

use App\Base\Software\Console\Commands\DomainRuntimeReloadCommand;
use App\Base\Software\Console\Commands\SoftwareUpdateCommand;
use App\Base\Software\Console\Commands\SoftwareUpdateWatchdogCommand;
use App\Base\Software\Console\Commands\WarmInventorySnapshotCommand;
use App\Base\Software\Services\FrankenPhpWorkerStatusDiagnosticProvider;
use App\Base\Software\Services\FrontendBuildStatusDiagnosticProvider;
use App\Base\Software\Services\InventoryContributionDiscoveryService;
use App\Base\Software\Services\InventoryContributionRegistry;
use App\Base\Software\Services\PhpExtensionDriftStatusDiagnosticProvider;
use App\Base\Software\Services\SoftwareInventoryStatusDiagnosticProvider;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->commands([
            DomainRuntimeReloadCommand::class,
            SoftwareUpdateCommand::class,
            SoftwareUpdateWatchdogCommand::class,
            WarmInventorySnapshotCommand::class,
        ]);

        $this->app->singleton(InventoryContributionRegistry::class);
        $this->app->singleton(InventoryContributionDiscoveryService::class);
        $this->app->singleton(FrankenPhpWorkerStatusDiagnosticProvider::class);
        $this->app->singleton(FrontendBuildStatusDiagnosticProvider::class);
        $this->app->singleton(SoftwareInventoryStatusDiagnosticProvider::class);
        $this->app->singleton(PhpExtensionDriftStatusDiagnosticProvider::class);
        $this->app->tag(FrankenPhpWorkerStatusDiagnosticProvider::class, StatusBarDiagnosticProvider::CONTAINER_TAG);
        $this->app->tag(FrontendBuildStatusDiagnosticProvider::class, StatusBarDiagnosticProvider::CONTAINER_TAG);
        $this->app->tag(SoftwareInventoryStatusDiagnosticProvider::class, StatusBarDiagnosticProvider::CONTAINER_TAG);
        $this->app->tag(PhpExtensionDriftStatusDiagnosticProvider::class, StatusBarDiagnosticProvider::CONTAINER_TAG);

        // Register on booted() (not bootstrap/app.php withSchedule) so the
        // admin Schedule page sees it too. Warming every ten minutes keeps
        // the status-bar inventory snapshot inside its fresh window, so no
        // web request ever runs the nested git scan synchronously; the
        // Cache::flexible fallback in the provider still covers a scheduler
        // outage.
        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)
                ->command('blb:software:inventory:warm')
                ->everyTenMinutes()
                ->withoutOverlapping();
        });
    }

    public function boot(InventoryContributionDiscoveryService $contributions): void
    {
        $contributions->discoverInto($this->app->make(InventoryContributionRegistry::class));
    }
}
