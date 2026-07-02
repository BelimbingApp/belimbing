<?php

namespace App\Base\Menu;

use App\Base\Menu\Contracts\MenuAccessChecker;
use App\Base\Menu\Contracts\NavigableMenuSnapshot;
use App\Base\Menu\Services\DefaultMenuAccessChecker;
use App\Base\Menu\Services\MenuConditionRegistry;
use App\Base\Menu\Services\MenuDiscoveryService;
use App\Base\Menu\Services\MenuLinkResolver;
use App\Base\Menu\Services\MenuRegistryLoader;
use App\Base\Menu\Services\MenuStatusDiagnosticProvider;
use App\Base\Menu\Services\PagePinResolver;
use App\Base\Menu\Services\PinMetadataNormalizer;
use App\Base\Menu\Services\VisibleNavMenuItemsFlat;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(MenuDiscoveryService::class);
        $this->app->singleton(MenuRegistry::class);
        $this->app->singleton(MenuRegistryLoader::class);
        $this->app->singleton(MenuBuilder::class);
        $this->app->singleton(MenuConditionRegistry::class);
        $this->app->singleton(MenuLinkResolver::class);
        $this->app->singleton(MenuStatusDiagnosticProvider::class);
        $this->app->singleton(PagePinResolver::class);
        $this->app->singleton(PinMetadataNormalizer::class);
        $this->app->singleton(VisibleNavMenuItemsFlat::class);
        $this->app->tag(MenuStatusDiagnosticProvider::class, StatusBarDiagnosticProvider::CONTAINER_TAG);
        $this->app->bind(NavigableMenuSnapshot::class, VisibleNavMenuItemsFlat::class);
        $this->app->bindIf(
            MenuAccessChecker::class,
            DefaultMenuAccessChecker::class,
            true,
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->registerViewComposer();
    }

    /**
     * Register view composer to provide menu data to layouts.
     */
    protected function registerViewComposer(): void
    {
        View::composer(['components.layouts.app', 'layouts::app'], function (
            $view,
        ): void {
            if (! auth()->check()) {
                $view->with('menuTree', []);

                return;
            }

            // On wire:navigate the persisted sidebar is kept client-side and the
            // freshly-rendered one is discarded — so building the menu tree (and
            // rendering the sidebar) is wasted work. Skip it for navigate requests;
            // the layout renders empty @persist markers and the client keeps its
            // existing chrome. Full loads (no header) still build the menu.
            //
            // INVARIANT: this assumes the client ALREADY has the persisted chrome,
            // which holds for app->app navigation. Pages on a non-app layout (the
            // guest auth layout) must redirect INTO the app with a full page load,
            // NOT navigate: true — otherwise there is no chrome to keep and the
            // sidebar/top/status bars render blank. See App\Modules\Core\User\Livewire\Auth.
            if (request()->hasHeader('X-Livewire-Navigate')) {
                $view->with('menuTree', []);
                $view->with('menuItemsFlat', []);
                $view->with('pins', []);

                return;
            }

            $builder = $this->app->make(MenuBuilder::class);
            $user = auth()->user();

            $snapshot = $this->app
                ->make(VisibleNavMenuItemsFlat::class)
                ->snapshotForUser($user);

            $filteredItems = $snapshot['filtered'];
            $menuItemsFlat = $snapshot['flat'];
            $pinMetadataNormalizer = $this->app->make(PinMetadataNormalizer::class);

            $view->with(
                'menuTree',
                $builder->build($filteredItems, request()->route()?->getName()),
            );
            $view->with('menuItemsFlat', $menuItemsFlat);
            $view->with(
                'pins',
                $pinMetadataNormalizer->mergeMissingPinIcons(
                    $this->resolvePins($user),
                    $menuItemsFlat,
                ),
            );
        });
    }

    private function resolvePins(mixed $user): array
    {
        try {
            return method_exists($user, 'getPins') ? $user->getPins() : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
