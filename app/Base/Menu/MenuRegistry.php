<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Menu;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MenuRegistry
{
    /**
     * Registered menu items indexed by ID.
     */
    protected Collection $items;

    /**
     * Cache key for stored menu items.
     *
     * Bumped to v3 when source-attribution fields (sourceModule, sourceFile)
     * were added to MenuItem; v2 dropped the dead position field. Rotate the
     * suffix whenever the persisted shape changes.
     */
    protected const CACHE_KEY = 'blb.menu.registry.v3';

    public function __construct()
    {
        $this->items = collect();
    }

    /**
     * Register items from discovery.
     *
     * @param  Collection  $discoveredItems  Raw arrays from MenuDiscoveryService
     */
    public function registerFromDiscovery(Collection $discoveredItems): void
    {
        foreach ($discoveredItems as $item) {
            $menuItem = MenuItem::fromArray($item);

            // Last definition wins (enables extension override)
            if ($this->items->has($menuItem->id)) {
                Log::info('Menu item overridden', [
                    'id' => $menuItem->id,
                    'source' => $item['_source']['file'] ?? 'unknown',
                ]);
            }

            $this->items[$menuItem->id] = $menuItem;
        }
    }

    /**
     * Validate registered items.
     *
     * @return array Array of validation error messages
     */
    public function validate(): array
    {
        $errors = [];

        // Check for circular parent references
        foreach ($this->items as $item) {
            if ($this->hasCircularParent($item->id)) {
                $errors[] = "Circular parent reference detected for item: {$item->id}";
            }
        }

        // Warn about missing parents (but don't error - item becomes root)
        foreach ($this->items as $item) {
            if ($item->parent && ! $this->items->has($item->parent)) {
                Log::warning('Menu item parent not found', [
                    'item_id' => $item->id,
                    'parent_id' => $item->parent,
                ]);
            }
        }

        return $errors;
    }

    /**
     * Check if an item has a circular parent reference.
     *
     * @param  string  $itemId  The item ID to check
     * @param  array  $visited  Visited IDs in traversal
     */
    protected function hasCircularParent(string $itemId, array $visited = []): bool
    {
        if (in_array($itemId, $visited)) {
            return true;
        }

        $item = $this->items->get($itemId);
        if (! $item || ! $item->parent) {
            return false;
        }

        $visited[] = $itemId;

        return $this->hasCircularParent($item->parent, $visited);
    }

    /**
     * Get all registered items.
     */
    public function getAll(): Collection
    {
        return $this->items;
    }

    /**
     * Load items from cache.
     *
     * @return bool True if loaded from cache, false otherwise
     */
    public function loadFromCache(): bool
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached) {
            $this->items = collect($cached)->mapWithKeys(function ($data, $id) {
                return [$id => MenuItem::fromArray($data)];
            });

            return true;
        }

        return false;
    }

    /**
     * Persist items to cache.
     */
    public function persist(): void
    {
        // Convert MenuItem objects to arrays for serialization
        $data = $this->items->map(function (MenuItem $item) {
            return [
                'id' => $item->id,
                'label' => $item->label,
                'icon' => $item->icon,
                'route' => $item->route,
                'url' => $item->url,
                'parent' => $item->parent,
                'permission' => $item->permission,
                'condition' => $item->condition,
                'sourceModule' => $item->sourceModule,
                'sourceFile' => $item->sourceFile,
            ];
        })->all();

        Cache::forever(self::CACHE_KEY, $data);
    }

    /**
     * Clear menu cache.
     */
    public function clear(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->items = collect();
    }
}
