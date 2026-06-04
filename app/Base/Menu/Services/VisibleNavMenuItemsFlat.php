<?php

namespace App\Base\Menu\Services;

use App\Base\Menu\Contracts\MenuAccessChecker;
use App\Base\Menu\Contracts\NavigableMenuSnapshot;
use App\Base\Menu\MenuItem;
use App\Base\Menu\MenuRegistry;
use Illuminate\Support\Collection;

/**
 * Builds the navigable menu item map for the authenticated user, including
 * registry bootstrap identical to the layout view composer.
 */
final class VisibleNavMenuItemsFlat implements NavigableMenuSnapshot
{
    public function __construct(
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
                        'href' => $item->href(),
                        'route' => $item->route,
                    ],
                ],
            )
            ->all();
    }

    private function ensureMenuRegistryIsLoaded(): void
    {
        // Cache the discovered + validated registry in the cache store keyed by a
        // config fingerprint. Octane gives each request a fresh container, so the
        // in-memory singleton can't be reused — but the cache store persists, so
        // discover()+validate() runs only when a menu config actually changes
        // (auto-invalidating in both dev and production; no manual cache clear).
        $fingerprint = $this->discovery->configFingerprint();

        if ($this->registry->loadFromCache($fingerprint)) {
            return;
        }

        $this->refreshMenuRegistry(persist: true, fingerprint: $fingerprint);
    }

    private function refreshMenuRegistry(bool $persist, ?string $fingerprint = null): void
    {
        $this->registry->registerFromDiscovery($this->discovery->discover());

        $errors = $this->registry->validate();

        if (! empty($errors)) {
            logger()->error('Menu validation errors', ['errors' => $errors]);
        }

        if ($persist) {
            $this->registry->persist($fingerprint);
        }
    }
}
