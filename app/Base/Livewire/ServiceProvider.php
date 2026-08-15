<?php

namespace App\Base\Livewire;

use App\Base\Authz\Middleware\AuthorizeCapability;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Livewire\Livewire;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register the ComponentDiscoveryService and the action-failure hook.
     */
    public function register(): void
    {
        $this->app->singleton(ComponentDiscoveryService::class);

        // Must be the register phase, not boot: ComponentHookRegistry::boot()
        // wires each registered hook into Livewire's mount/hydrate listeners
        // once, from LivewireServiceProvider::boot(). A hook added after that
        // is never wired for the current process — and because the registry
        // holds hooks in a static, it would silently start working on the
        // *next* boot, which under a FrankenPHP worker means the failure only
        // shows up on the first request after a restart.
        Livewire::componentHook(RecoverFromActionFailure::class);
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
