<?php

namespace App\Base\Routing;

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\Services\DomainState;

class RouteDiscoveryService
{
    /**
     * Glob patterns for route directory discovery.
     *
     * Supports Base components and modules in Core, Domains, and Extensions.
     */
    protected function scanPatterns(): array
    {
        return ApplicationTopology::contributionPatterns('Routes');
    }

    /**
     * Discover all route files organized by type (web, api).
     *
     * @return array<string, list<string>> Keyed by route type, values are absolute file paths
     */
    public function discover(): array
    {
        $routes = [];

        foreach ($this->scanPatterns() as $pattern) {
            $directories = DomainState::filterPaths(glob($pattern, GLOB_ONLYDIR) ?: []);

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
