<?php
namespace App\Base\Routing;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(RouteDiscoveryService::class);
    }

    /**
     * Bootstrap module route discovery and registration.
     *
     * Discovers route files from all modules and loads them
     * with the appropriate middleware group (web or api).
     */
    public function boot(): void
    {
        $discovered = $this->app->make(RouteDiscoveryService::class)->discover();

        foreach ($discovered['web'] ?? [] as $file) {
            Route::middleware('web')->group($file);
        }

        foreach ($discovered['api'] ?? [] as $file) {
            Route::middleware('api')->prefix('api')->group($file);
        }
    }
}
