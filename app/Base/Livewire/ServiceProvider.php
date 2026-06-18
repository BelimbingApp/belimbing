<?php

namespace App\Base\Livewire;

use App\Base\Authz\Middleware\AuthorizeCapability;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Livewire\Livewire;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register the ComponentDiscoveryService as a singleton.
     */
    public function register(): void
    {
        $this->app->singleton(ComponentDiscoveryService::class);
    }

    /**
     * Register all module Livewire components with Livewire's component registry.
     *
     * Scans module directories for Component subclasses, derives their
     * component names from the view('livewire.xxx') call in render(),
     * and registers each with Livewire::component() so that string-based
     * resolution works for <livewire:name /> tags and Livewire::test('name').
     */
    public function boot(): void
    {
        Livewire::addPersistentMiddleware([
            'authz',
            AuthorizeCapability::class,
        ]);

        $components = $this->app->make(ComponentDiscoveryService::class)->discover();

        foreach ($components as $name => $class) {
            Livewire::component($name, $class);
        }
    }
}
