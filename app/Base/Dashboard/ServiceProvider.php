<?php

namespace App\Base\Dashboard;

use App\Base\Dashboard\Services\DashboardLayout;
use App\Base\Dashboard\Services\WidgetDiscoveryService;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WidgetDiscoveryService::class);
        $this->app->singleton(WidgetRegistry::class);
        $this->app->singleton(DashboardLayout::class);
    }
}
