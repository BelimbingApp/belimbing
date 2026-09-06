<?php

namespace App\Base\FeatureFlags;

use App\Base\FeatureFlags\Console\Commands\ListFeatureFlagsCommand;
use App\Base\FeatureFlags\Services\FeatureFlagDeclarationInventory;
use App\Base\FeatureFlags\Services\FeatureFlagRegistry;
use App\Base\FeatureFlags\Services\FeatureFlags;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FeatureFlagRegistry::class);
        $this->app->scoped(FeatureFlagDeclarationInventory::class);
        $this->app->singleton(FeatureFlags::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ListFeatureFlagsCommand::class,
            ]);
        }
    }
}
