<?php
namespace App\Base\Menu\Services;

use App\Base\Menu\MenuItem;
use App\Base\Menu\MenuRegistry;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;

class PagePinResolver
{
    public function __construct(
        private readonly MenuRegistry $menuRegistry,
        private readonly PinMetadataNormalizer $pinMetadataNormalizer,
    ) {}

    /**
     * Resolve pin metadata for the current page.
     *
     * @param  array{label: string, url: string, icon?: string|null}|false|null  $pinnable
     * @return array{label: string, url: string, icon?: string|null}|null
     */
    public function resolve(
        string $title,
        array|bool|null $pinnable = null,
    ): ?array {
        if ($pinnable !== null) {
            return is_array($pinnable) ? $pinnable : null;
        }

        $route = request()->route();
        $routeName = $route?->getName();

        if (! $route instanceof Route || ! is_string($routeName)) {
            return null;
        }

        $menuItem = $this->findMatchingMenuItem($routeName);

        return [
            'label' => $this->buildLabel($title, $menuItem),
            'url' => request()->url(),
            'icon' => $menuItem?->icon,
        ];
    }

    private function buildLabel(string $title, ?MenuItem $menuItem): string
    {
        if ($menuItem !== null) {
            return $this->pinMetadataNormalizer->normalizeLabel(
                $menuItem->label,
            );
        }

        return $this->pinMetadataNormalizer->normalizeLabel($title);
    }

    private function findMatchingMenuItem(string $currentRoute): ?MenuItem
    {
        /** @var Collection<int, MenuItem> $items */
        $items = $this->menuRegistry->getAll();

        return $items
            ->filter(
                fn (MenuItem $item): bool => $this->routeMatches(
                    $item->route,
                    $currentRoute,
                ),
            )
            ->sortByDesc(
                fn (MenuItem $item): int => strlen((string) $item->route),
            )
            ->first();
    }

    private function routeMatches(
        ?string $menuRoute,
        string $currentRoute,
    ): bool {
        $matches = false;

        if ($menuRoute !== null) {
            $matches = $menuRoute === $currentRoute;

            if (! $matches && str_ends_with($menuRoute, '.index')) {
                $matches = str_starts_with(
                    $currentRoute,
                    substr($menuRoute, 0, -6),
                );
            }
        }

        return $matches;
    }
}
