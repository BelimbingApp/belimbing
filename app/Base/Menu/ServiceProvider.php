<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Menu;

use App\Base\Menu\Contracts\MenuAccessChecker;
use App\Base\Menu\Services\DefaultMenuAccessChecker;
use App\Base\Menu\Services\MenuConditionRegistry;
use App\Base\Menu\Services\MenuDiscoveryService;
use App\Base\Menu\Services\PagePinResolver;
use App\Base\Menu\Services\PinMetadataNormalizer;
use Illuminate\Support\Collection;
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

            $registry = $this->app->make(MenuRegistry::class);
            $builder = $this->app->make(MenuBuilder::class);
            $menuAccessChecker = $this->app->make(MenuAccessChecker::class);
            $user = auth()->user();

            $this->ensureMenuRegistryIsLoaded($registry);

            $filteredItems = $this->filterVisibleMenuItems(
                $registry,
                $menuAccessChecker,
                $user,
            );

            $view->with(
                'menuTree',
                $builder->build($filteredItems, request()->route()?->getName()),
            );
            $view->with(
                'menuItemsFlat',
                $this->buildMenuItemsFlat($filteredItems),
            );
            $view->with('pins', $this->resolvePins($user));
        });
    }

    private function ensureMenuRegistryIsLoaded(MenuRegistry $registry): void
    {
        if ($this->app->environment('local')) {
            $this->refreshMenuRegistry($registry, persist: false);

            return;
        }

        if (! $registry->loadFromCache()) {
            $this->refreshMenuRegistry($registry, persist: true);
        }
    }

    private function refreshMenuRegistry(
        MenuRegistry $registry,
        bool $persist,
    ): void {
        $discovery = $this->app->make(MenuDiscoveryService::class);
        $registry->registerFromDiscovery($discovery->discover());

        $errors = $registry->validate();

        if (! empty($errors)) {
            logger()->error('Menu validation errors', ['errors' => $errors]);
        }

        if ($persist) {
            $registry->persist();
        }
    }

    private function filterVisibleMenuItems(
        MenuRegistry $registry,
        MenuAccessChecker $menuAccessChecker,
        mixed $user,
    ): Collection {
        return $registry
            ->getAll()
            ->filter(function (MenuItem $item) use (
                $menuAccessChecker,
                $user,
            ): bool {
                return $menuAccessChecker->canView($item, $user);
            });
    }

    /**
     * @param  Collection<int, MenuItem>  $filteredItems
     * @return array<string, array{label: string, pinLabel: string, icon: string, href: string|null, route: string|null}>
     */
    private function buildMenuItemsFlat(
        Collection $filteredItems,
    ): array {
        return $filteredItems
            ->filter(fn (MenuItem $item) => $item->hasRoute())
            ->mapWithKeys(
                fn (MenuItem $item) => [
                    $item->id => [
                        'label' => $item->label,
                        'pinLabel' => $this->buildPinLabel($item),
                        'icon' => $item->icon ?? 'heroicon-o-squares-2x2',
                        'href' => $item->route
                            ? route($item->route)
                            : $item->url,
                        'route' => $item->route,
                    ],
                ],
            )
            ->all();
    }

    private function buildPinLabel(MenuItem $item): string
    {
        return $this->app
            ->make(PinMetadataNormalizer::class)
            ->normalizeLabel($item->label);
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
