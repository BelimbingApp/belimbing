<?php

namespace App\Base\Routing;

use App\Base\Foundation\ApplicationTopology;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Routing\Router;

final class DomainRouteInventory
{
    public function __construct(
        private readonly Router $router,
        private readonly RouteDiscoveryService $discovery,
    ) {}

    /**
     * @return list<array{
     *     domain: string,
     *     module: string,
     *     uri: string,
     *     name: string|null,
     *     methods: list<string>,
     *     middleware: list<string>
     * }>
     */
    public function all(): array
    {
        $rows = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $owner = $this->ownerOf($route);

            if ($owner === null) {
                continue;
            }

            $rows[] = [
                ...$owner,
                'uri' => $route->uri(),
                'name' => $route->getName(),
                'methods' => array_values($route->methods()),
                'middleware' => array_values($route->gatherMiddleware()),
            ];
        }

        usort($rows, fn (array $left, array $right): int => $this->sortKey($left) <=> $this->sortKey($right));

        return $rows;
    }

    /**
     * @return array{domain: string, module: string}|null
     */
    private function ownerOf(RegisteredRoute $route): ?array
    {
        $source = $this->discovery->sourceFileFor($route);

        if ($source === null) {
            return null;
        }

        $root = rtrim(str_replace('\\', '/', ApplicationTopology::domainsRoot()), '/').'/';
        $source = str_replace('\\', '/', $source);

        if (! str_starts_with($source, $root)) {
            return null;
        }

        $segments = explode('/', substr($source, strlen($root)));

        if (count($segments) !== 4 || $segments[2] !== 'Routes') {
            return null;
        }

        return ['domain' => $segments[0], 'module' => $segments[1]];
    }

    /**
     * @param  array{domain: string, module: string, uri: string, name: string|null, methods: list<string>}  $row
     * @return list<string>
     */
    private function sortKey(array $row): array
    {
        return [$row['domain'], $row['module'], $row['uri'], $row['name'] ?? '', implode('|', $row['methods'])];
    }
}
