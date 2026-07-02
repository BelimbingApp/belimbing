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
        private readonly MenuRegistryLoader $loader,
        private readonly MenuLinkResolver $linkResolver,
        private readonly PinMetadataNormalizer $pinMetadataNormalizer,
    ) {}

    /**
     * @return array{filtered: Collection<int, MenuItem>, flat: array<string, array{label: string, pinLabel: string, icon: string, href: string|null, route: string|null}>}
     */
    public function snapshotForUser(mixed $user): array
    {
        $this->loader->ensureLoaded();

        $filtered = $this->registry
            ->getAll()
            ->filter(
                fn (MenuItem $item): bool => $this->menuAccessChecker->canView(
                    $item,
                    $user,
                ),
            );

        $resolvedHrefs = [];
        $filtered = $this->withoutUnresolvableLinks($filtered, $resolvedHrefs);

        return [
            'filtered' => $filtered,
            'flat' => $this->buildFlat($filtered, $resolvedHrefs),
        ];
    }

    /**
     * @param  Collection<int, MenuItem>  $filteredItems
     * @return array<string, array{label: string, pinLabel: string, icon: string, href: string|null, route: string|null}>
     */
    private function buildFlat(Collection $filteredItems, array $resolvedHrefs): array
    {
        return $filteredItems
            ->filter(fn (MenuItem $item) => $item->hasRoute())
            ->mapWithKeys(function (MenuItem $item) use ($resolvedHrefs): array {
                $href = $resolvedHrefs[$item->id]
                    ?? $this->linkResolver->resolve($item)->href;

                if ($href === null) {
                    return [];
                }

                return [
                    $item->id => [
                        'label' => $item->label,
                        'pinLabel' => $this->pinMetadataNormalizer->normalizeLabel(
                            $item->label,
                        ),
                        'icon' => $item->icon ?? 'heroicon-o-squares-2x2',
                        'href' => $href,
                        'route' => $item->route,
                    ],
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, MenuItem>  $items
     * @param  array<string, string>  $resolvedHrefs
     * @return Collection<int, MenuItem>
     */
    private function withoutUnresolvableLinks(Collection $items, array &$resolvedHrefs): Collection
    {
        return $items->reject(function (MenuItem $item) use (&$resolvedHrefs): bool {
            if (! $item->hasRoute()) {
                return false;
            }

            $resolution = $this->linkResolver->resolve($item);

            if ($resolution->isResolved()) {
                $resolvedHrefs[$item->id] = $resolution->href;

                return false;
            }

            $this->linkResolver->logUnresolvable($item, $resolution);

            return true;
        });
    }
}
