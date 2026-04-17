<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Menu\Services;

use App\Base\Menu\Contracts\MenuAccessChecker;
use App\Base\Menu\MenuItem;
use App\Base\Menu\MenuRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;

/**
 * Builds the navigable menu item map for the authenticated user, including
 * registry bootstrap identical to the layout view composer.
 */
final class VisibleNavMenuItemsFlat
{
    public function __construct(
        private readonly Application $app,
        private readonly MenuRegistry $registry,
        private readonly MenuAccessChecker $menuAccessChecker,
        private readonly MenuDiscoveryService $discovery,
        private readonly PinMetadataNormalizer $pinMetadataNormalizer,
    ) {}

    /**
     * @return array{filtered: Collection<int, MenuItem>, flat: array<string, array{label: string, pinLabel: string, icon: string, href: string|null, route: string|null}>}
     */
    public function snapshotForUser(mixed $user): array
    {
        $this->ensureMenuRegistryIsLoaded();

        $filtered = $this->registry
            ->getAll()
            ->filter(
                fn (MenuItem $item): bool => $this->menuAccessChecker->canView(
                    $item,
                    $user,
                ),
            );

        return [
            'filtered' => $filtered,
            'flat' => $this->buildFlat($filtered),
        ];
    }

    /**
     * @param  Collection<int, MenuItem>  $filteredItems
     * @return array<string, array{label: string, pinLabel: string, icon: string, href: string|null, route: string|null}>
     */
    private function buildFlat(Collection $filteredItems): array
    {
        return $filteredItems
            ->filter(fn (MenuItem $item) => $item->hasRoute())
            ->mapWithKeys(
                fn (MenuItem $item) => [
                    $item->id => [
                        'label' => $item->label,
                        'pinLabel' => $this->pinMetadataNormalizer->normalizeLabel(
                            $item->label,
                        ),
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

    private function ensureMenuRegistryIsLoaded(): void
    {
        if ($this->app->environment('local')) {
            $this->refreshMenuRegistry(persist: false);

            return;
        }

        if (! $this->registry->loadFromCache()) {
            $this->refreshMenuRegistry(persist: true);
        }
    }

    private function refreshMenuRegistry(bool $persist): void
    {
        $this->registry->registerFromDiscovery($this->discovery->discover());

        $errors = $this->registry->validate();

        if (! empty($errors)) {
            logger()->error('Menu validation errors', ['errors' => $errors]);
        }

        if ($persist) {
            $this->registry->persist();
        }
    }
}
