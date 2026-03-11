<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Menu\Services;

use App\Base\Menu\MenuItem;
use App\Base\Menu\MenuRegistry;
use BackedEnum;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;

class PagePinResolver
{
    public function __construct(
        private readonly MenuRegistry $menuRegistry,
    ) {}

    /**
     * Resolve pin metadata for the current page.
     *
     * @param  array{pinnableId: string, label: string, url: string, icon?: string|null}|false|null  $pinnable
     * @return array{pinnableId: string, label: string, url: string, icon?: string|null}|null
     */
    public function resolve(string $title, array|bool|null $pinnable = null): ?array
    {
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
            'pinnableId' => $this->buildPinnableId($route),
            'label' => $this->buildLabel($title, $menuItem),
            'url' => request()->url(),
            'icon' => $menuItem?->icon,
        ];
    }

    private function buildPinnableId(Route $route): string
    {
        $routeName = $route->getName();
        $baseId = 'page:route:'.$routeName;
        $parameterSignature = collect($route->parametersWithoutNulls())
            ->map(fn (mixed $value, string $key): string => $key.'='.$this->normalizeParameter($value))
            ->implode(';');

        if ($parameterSignature === '') {
            return $baseId;
        }

        $resolvedId = $baseId.':'.$parameterSignature;

        return strlen($resolvedId) <= 150
            ? $resolvedId
            : $baseId.':'.sha1($parameterSignature);
    }

    private function buildLabel(string $title, ?MenuItem $menuItem): string
    {
        if ($menuItem === null) {
            return $title;
        }

        $menuLabel = $this->buildPinLabel($menuItem, $this->menuRegistry->getAll());
        $lastSegment = strrchr($menuLabel, '/');
        $leafLabel = $lastSegment === false ? $menuLabel : substr($lastSegment, 1);

        return $leafLabel === $title
            ? $menuLabel
            : $menuLabel.'/'.$title;
    }

    private function findMatchingMenuItem(string $currentRoute): ?MenuItem
    {
        /** @var Collection<int, MenuItem> $items */
        $items = $this->menuRegistry->getAll();

        return $items
            ->filter(fn (MenuItem $item): bool => $this->routeMatches($item->route, $currentRoute))
            ->sortByDesc(fn (MenuItem $item): int => strlen((string) $item->route))
            ->first();
    }

    private function routeMatches(?string $menuRoute, string $currentRoute): bool
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
     * @param  Collection<int, MenuItem>  $allItems
     */
    private function buildPinLabel(MenuItem $item, Collection $allItems): string
    {
        $segments = [];
        $current = $item;

        while ($current !== null) {
            array_unshift($segments, $current->label);
            $current = $current->parent ? $allItems->get($current->parent) : null;
        }

        $pinLabel = implode('/', $segments);
        $firstSlash = strpos($pinLabel, '/');

        if ($firstSlash !== false) {
            return substr($pinLabel, $firstSlash + 1);
        }

        return $pinLabel;
    }

    private function normalizeParameter(mixed $value): string
    {
        if ($value instanceof UrlRoutable) {
            return rawurlencode((string) $value->getRouteKey());
        }

        if ($value instanceof BackedEnum) {
            return rawurlencode((string) $value->value);
        }

        if (is_array($value)) {
            ksort($value);

            return rawurlencode(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        if (is_object($value)) {
            return rawurlencode($value::class);
        }

        return rawurlencode((string) $value);
    }
}
