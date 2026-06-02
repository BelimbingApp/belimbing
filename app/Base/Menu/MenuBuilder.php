<?php

namespace App\Base\Menu;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class MenuBuilder
{
    /**
     * Cache key for built menu tree.
     */
    protected const CACHE_KEY = 'blb.menu.tree';

    /**
     * Build hierarchical menu tree from flat items.
     *
     * @param  Collection  $items  Flat collection of MenuItem objects
     * @param  string|null  $currentRoute  Current route name for active marking
     */
    public function build(Collection $items, ?string $currentRoute = null): array
    {
        // Build tree structure
        $tree = $this->buildTree($items, null);

        // Mark active items
        if ($currentRoute) {
            // If any menu item anywhere in the tree exactly matches the current
            // route, only exact matches are allowed to mark a node active.
            // Otherwise prefix fallback (`.index` stripped) is permitted so
            // that e.g. admin.companies.show highlights admin.companies.index.
            $hasExactMatch = $items->contains(
                fn (MenuItem $item) => $item->route === $currentRoute,
            );

            $tree = $this->markActive($tree, $currentRoute, $hasExactMatch);
        }

        return $tree;
    }

    /**
     * Build tree recursively.
     *
     * Items at each level are sorted alphabetically by label. Alphabetical
     * ordering keeps the menu predictable as new items are added; explicit
     * curated positions invite bitrot (collisions, drift, items "moving" when
     * unrelated edits land), so we do not support a position field.
     *
     * @param  Collection  $items  All menu items
     * @param  string|null  $parentId  Current parent ID (null = root level)
     */
    protected function buildTree(Collection $items, ?string $parentId): array
    {
        $children = $items
            ->filter(fn (MenuItem $item) => $item->parent === $parentId)
            ->sortBy(fn (MenuItem $item) => mb_strtolower($item->label))
            ->values();

        return $children->map(function (MenuItem $item) use ($items) {
            $childTree = $this->buildTree($items, $item->id);

            // Hide containers that have no visible children after permission filtering
            if ($item->isContainer() && empty($childTree)) {
                return null;
            }

            return [
                'item' => $item,
                'is_active' => false,
                'has_active_child' => false,
                'children' => $childTree,
            ];
        })->filter()->values()->all();
    }

    /**
     * Mark active item and parent chain.
     *
     * Uses prefix matching so that child routes (e.g. admin.companies.show,
     * admin.companies.edit) highlight the parent menu item (admin.companies.index).
     *
     * @param  array  $tree  Menu tree
     * @param  string  $currentRoute  Current route name
     */
    protected function markActive(array $tree, string $currentRoute, bool $hasExactMatch): array
    {
        foreach ($tree as &$node) {
            if (! empty($node['children'])) {
                $node['children'] = $this->markActive($node['children'], $currentRoute, $hasExactMatch);
            }

            $node['has_active_child'] = $this->hasActiveDescendant($node['children']);
        }
        unset($node);

        foreach ($tree as &$node) {
            if ($node['has_active_child']) {
                continue;
            }

            $node['is_active'] = $this->shouldMarkNodeActive($node, $currentRoute, $hasExactMatch);
        }
        unset($node);

        return $tree;
    }

    /**
     * @param  array<int, array{item: MenuItem, is_active: bool, has_active_child: bool, children: array}>  $children
     */
    protected function hasActiveDescendant(array $children): bool
    {
        foreach ($children as $child) {
            if ($child['is_active'] || $child['has_active_child']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{item: MenuItem, is_active: bool, has_active_child: bool, children: array}  $node
     */
    protected function shouldMarkNodeActive(array $node, string $currentRoute, bool $hasExactMatch): bool
    {
        if ($node['item']->route === $currentRoute) {
            return true;
        }

        return ! $hasExactMatch && $this->routeMatches($node['item']->route, $currentRoute);
    }

    /**
     * Check if the current route matches a menu item's route.
     *
     * Strips the trailing ".index" from the menu route to form a prefix,
     * then checks if the current route starts with that prefix.
     * Falls back to exact match for non-index routes.
     *
     * @param  string|null  $menuRoute  The menu item's route name
     * @param  string  $currentRoute  The current request route name
     */
    protected function routeMatches(?string $menuRoute, string $currentRoute): bool
    {
        if ($menuRoute === null) {
            return false;
        }

        if ($menuRoute === $currentRoute) {
            return true;
        }

        if (str_ends_with($menuRoute, '.index')) {
            $prefix = substr($menuRoute, 0, -6);

            return str_starts_with($currentRoute, $prefix);
        }

        return false;
    }

    /**
     * Build and cache menu tree.
     *
     * @param  Collection  $items  Flat collection of MenuItem objects
     * @param  string|null  $currentRoute  Current route name
     */
    public function buildAndCache(Collection $items, ?string $currentRoute = null): array
    {
        $cacheKey = self::CACHE_KEY.($currentRoute ? ".{$currentRoute}" : '');

        // Stale-while-revalidate: serve the cached tree instantly, and once it
        // goes stale refresh it in the background (after the response) instead of
        // blocking a request on the rebuild. Menu changes still invalidate
        // explicitly via clearCache(); these TTLs are the fallback refresh.
        return Cache::flexible($cacheKey, [3600, 21600], function () use ($items, $currentRoute) {
            return $this->build($items, $currentRoute);
        });
    }

    /**
     * Clear menu tree cache.
     */
    public function clearCache(): void
    {
        // Clear all menu tree cache keys (pattern matching not available in all cache drivers)
        // So we clear the base key; route-specific keys expire naturally
        Cache::forget(self::CACHE_KEY);
    }
}
