<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Menu;

use App\Base\Menu\Contracts\MenuAccessChecker;
use App\Base\Menu\Contracts\NavigableMenuSnapshot;
use App\Base\Menu\Services\DefaultMenuAccessChecker;
use App\Base\Menu\Services\MenuConditionRegistry;
use App\Base\Menu\Services\MenuDiscoveryService;
use App\Base\Menu\Services\PagePinResolver;
use App\Base\Menu\Services\PinMetadataNormalizer;
use App\Base\Menu\Services\VisibleNavMenuItemsFlat;
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
        $this->app->singleton(MenuBuilder::class);
        $this->app->singleton(MenuConditionRegistry::class);
        $this->app->singleton(PagePinResolver::class);
        $this->app->singleton(PinMetadataNormalizer::class);
        $this->app->singleton(VisibleNavMenuItemsFlat::class);
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
