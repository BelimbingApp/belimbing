<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Menu\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class MenuDiscoveryService
{
    /**
     * Glob patterns for menu file discovery.
     */
    /**
     * Glob patterns for menu file discovery.
     *
     * One-level patterns (e.g. `app/Modules/<Domain>/Config/menu.php`) are
     * "domain anchors" — stable files where a domain declares its top-level
     * bucket. Leaf modules / packages live one level deeper and declare only
     * leaves and intermediate containers, never a root. Extensions follow the
     * same shape: `extensions/<vendor>/Config/menu.php` for vendor-anchored
     * buckets, `extensions/<vendor>/<package>/Config/menu.php` for package
     * leaves.
     */
    protected array $scanPatterns = [
        'app/Base/*/Config/menu.php',
        'app/Modules/*/Config/menu.php',
        'app/Modules/*/*/Config/menu.php',
        'extensions/*/Config/menu.php',
        'extensions/*/*/Config/menu.php',
    ];

    /**
     * Discover all menu items from configured paths.
     */
    public function discover(): Collection
    {
        $items = collect();

        foreach ($this->scanPatterns as $pattern) {
            $files = glob(base_path($pattern));

            foreach ($files as $file) {
                $this->processFile($file, $items);
            }
        }

        return $items;
    }

    /**
     * Process a single menu file.
     *
     * @param  string  $path  Full path to menu.php file
     * @param  Collection  $items  Collection to add discovered items to
     */
    protected function processFile(string $path, Collection $items): void
    {
        try {
            $config = require $path;

            if (! isset($config['items']) || ! is_array($config['items'])) {
                Log::warning('Menu file missing items array', ['file' => $path]);

                return;
            }

            $metadata = $this->extractMetadata($path);

            foreach ($config['items'] as $item) {
                if (! isset($item['id']) || ! isset($item['label'])) {
                    Log::warning('Menu item missing id or label', [
                        'file' => $path,
                        'item' => $item,
                    ]);

                    continue;
                }

                $items->push(array_merge($item, ['_source' => $metadata]));
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to load menu file', [
                'file' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract metadata from file path.
     *
     * @param  string  $path  Full path to menu.php file
     */
    protected function extractMetadata(string $path): array
    {
        $relativePath = str_replace(base_path().'/', '', $path);

        return [
            'file' => $relativePath,
            'module_name' => $this->extractModuleName($relativePath),
            'module_path' => $this->extractModulePath($relativePath),
        ];
    }

    /**
     * Extract module name from file path.
     *
     * @param  string  $relativePath  Relative path from base_path()
     */
    protected function extractModuleName(string $relativePath): ?string
    {
        // Extract from patterns:
        // app/Base/Menu/Config/menu.php             -> Menu
        // app/Modules/Commerce/Config/menu.php      -> Commerce        (domain anchor)
        // app/Modules/Core/Geonames/Config/menu.php -> Geonames        (leaf)
        // extensions/ham/Config/menu.php            -> ham             (vendor anchor)
        // extensions/ham/auto-parts/Config/menu.php -> auto-parts      (package leaf)

        if (preg_match('#app/Base/([^/]+)/Config/menu\.php#', $relativePath, $matches)) {
            return $matches[1];
        }

        if (preg_match('#app/Modules/[^/]+/([^/]+)/Config/menu\.php#', $relativePath, $matches)) {
            return $matches[1];
        }

        if (preg_match('#app/Modules/([^/]+)/Config/menu\.php#', $relativePath, $matches)) {
            return $matches[1];
        }

        if (preg_match('#extensions/[^/]+/([^/]+)/Config/menu\.php#', $relativePath, $matches)) {
            return $matches[1];
        }

        if (preg_match('#extensions/([^/]+)/Config/menu\.php#', $relativePath, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract module path from file path.
     *
     * @param  string  $relativePath  Relative path from base_path()
     */
    protected function extractModulePath(string $relativePath): ?string
    {
        // Extract directory containing the menu.php
        // app/Modules/Core/Geonames/Config/menu.php -> app/Modules/Core/Geonames

        $parts = explode('/Config/menu.php', $relativePath);

        return $parts[0] ?? null;
    }
}
