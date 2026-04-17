<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Menu\Services;

class PinMetadataNormalizer
{
    /**
     * Normalize a pin label to a compact, user-facing leaf label.
     *
     * Accepts breadcrumb-style labels using "/" separators and returns
     * the final non-empty segment. Whitespace around segments is trimmed.
     */
    public function normalizeLabel(string $label): string
    {
        $segments = array_values(array_filter(
            array_map(static fn (string $segment): string => trim($segment), explode('/', $label)),
            static fn (string $segment): bool => $segment !== ''
        ));

        if ($segments === []) {
            return trim($label);
        }

        return $segments[array_key_last($segments)];
    }

    /**
     * Normalize a URL to its path only (scheme, host, query, and fragment ignored).
     *
     * Used to match pins to menu entries when the stored pin URL includes query
     * parameters but the menu href does not, or when {@see normalizeUrl} keys
     * would otherwise diverge.
     */
    public function normalizePathKey(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return $this->normalizePath(trim($url));
        }

        $path = isset($parts['path']) && is_string($parts['path'])
            ? $this->normalizePath($parts['path'])
            : '/';

        return $path;
    }

    /**
     * Fill missing pin icons from visible navigable menu items matched by path.
     *
     * @param  list<array{id: int, label: string, url: string, icon: string|null}>  $pins
     * @param  array<string, array{label: string, pinLabel: string, icon: string, href: string|null, route: string|null}>  $menuItemsFlat
     * @return list<array{id: int, label: string, url: string, icon: string|null}>
     */
    public function mergeMissingPinIcons(array $pins, array $menuItemsFlat): array
    {
        $pathToIcon = [];

        foreach ($menuItemsFlat as $item) {
            $href = $item['href'] ?? null;

            if (! is_string($href) || $href === '') {
                continue;
            }

            $pathToIcon[$this->normalizePathKey($href)] = $item['icon'] ?? 'heroicon-o-squares-2x2';
        }

        return array_map(function (array $pin) use ($pathToIcon): array {
            if (! empty($pin['icon'])) {
                return $pin;
            }

            $key = $this->normalizePathKey($pin['url']);

            if (isset($pathToIcon[$key])) {
                $pin['icon'] = $pathToIcon[$key];
            }

            return $pin;
        }, $pins);
    }

    /**
     * Normalize a URL into a destination key suitable for duplicate detection.
     *
     * Compares app-internal destinations by:
     * - path
     * - sorted query string
     *
     * Ignores:
     * - scheme
     * - host
     * - port
     * - fragment
     *
     * This allows equivalent URLs such as absolute and relative forms of the
     * same internal route to collapse to a single canonical destination key.
     */
    public function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return trim($url);
        }

        $path = isset($parts['path']) && is_string($parts['path'])
            ? $this->normalizePath($parts['path'])
            : '/';

        $query = $parts['query'] ?? null;

        if (! is_string($query) || $query === '') {
            return $path;
        }

        return $path.'?'.$this->normalizeQueryString($query);
    }

    /**
     * Normalize the path portion of a URL.
     */
    private function normalizePath(string $path): string
    {
        $normalized = trim($path);

        if ($normalized === '') {
            return '/';
        }

        if (! str_starts_with($normalized, '/')) {
            $normalized = '/'.$normalized;
        }

        if ($normalized !== '/') {
            $normalized = rtrim($normalized, '/');
        }

        return $normalized;
    }

    /**
     * Normalize a query string by sorting keys recursively and rebuilding it.
     */
    private function normalizeQueryString(string $query): string
    {
        $parameters = [];
        parse_str($query, $parameters);

        $this->sortRecursive($parameters);

        return http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Recursively sort an array by key to ensure deterministic query ordering.
     *
     * @param  array<mixed>  $value
     */
    private function sortRecursive(array &$value): void
    {
        ksort($value);

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursive($item);
            }
        }
    }
}
