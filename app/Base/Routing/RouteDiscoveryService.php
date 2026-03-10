<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Routing;

class RouteDiscoveryService
{
    /**
     * Glob patterns for route directory discovery.
     *
     * Supports Base modules, Modules, and extensions.
     */
    protected array $scanPatterns = [
        'app/Base/*/Routes',
        'app/Modules/*/*/Routes',
        'extensions/*/*/Routes',
    ];

    /**
     * Discover all route files organized by type (web, api).
     *
     * @return array<string, list<string>> Keyed by route type, values are absolute file paths
     */
    public function discover(): array
    {
        $routes = [];

        foreach ($this->scanPatterns as $pattern) {
            $directories = glob(base_path($pattern), GLOB_ONLYDIR);

            foreach ($directories as $directory) {
                foreach (['web', 'api'] as $type) {
                    $file = $directory.'/'.$type.'.php';

                    if (file_exists($file)) {
                        $routes[$type][] = $file;
                    }
                }
            }
        }

        return $routes;
    }
}
