<?php

namespace App\Base\Routing;

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\Services\DomainState;
use App\Base\Routing\Exceptions\RouteCollisionException;
use App\Base\Tenancy\Middleware\RequireTenantContext;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Route;

class RouteDiscoveryService
{
    /**
     * Route file that first registered each "METHOD domain/uri" key, across
     * every registerRoutes() call on this instance.
     *
     * @var array<string, string>
     */
    private array $fileByRouteKey = [];

    /**
     * Route file that first registered each route name, across every
     * registerRoutes() call on this instance. Names are the second axis
     * Laravel keeps last-wins on: route('people.skills') would silently
     * resolve to whichever module loaded last (#615).
     *
     * @var array<string, string>
     */
    private array $fileByRouteName = [];

    /**
     * Route file that registered each route object. This provenance survives
     * registration so operator tooling can attribute closures as well as
     * controller routes to their owning module.
     *
     * @var array<int, string>
     */
    private array $fileByRouteObjectId = [];

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

    /**
     * Load discovered route files into the router, one file at a time, and
     * refuse the composed application when a later file registers a method
     * and URI, or a route name, an earlier file already registered.
     *
     * Laravel's RouteCollection keeps the last route for a method and URI and
     * says nothing, so two modules that both ship `GET people/skills` would
     * boot cleanly with one of them unreachable. That is the shape a module
     * relocation leaves behind while the old copy is still installed.
     *
     * @param  array<string, list<string>>|null  $discovered  Route files keyed by type; null discovers them
     */
    public function registerRoutes(?array $discovered = null): void
    {
        $discovered ??= $this->discover();

        foreach ($discovered['web'] ?? [] as $file) {
            $this->registerFile($file, fn () => Route::middleware($this->middlewareFor($file, 'web'))->group($file));
        }

        foreach ($discovered['api'] ?? [] as $file) {
            $this->registerFile($file, fn () => Route::middleware($this->middlewareFor($file, 'api'))->prefix('api')->group($file));
        }
    }

    public function sourceFileFor(RegisteredRoute $route): ?string
    {
        return $this->fileByRouteObjectId[spl_object_id($route)] ?? null;
    }

    /**
     * @return array{domain: string, module: string}|null
     */
    public function domainModuleForSource(string $file): ?array
    {
        $root = rtrim(str_replace('\\', '/', ApplicationTopology::domainsRoot()), '/').'/';
        $source = str_replace('\\', '/', $file);

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
     * @return list<string>
     */
    private function middlewareFor(string $file, string $group): array
    {
        $middleware = [$group];
        $owner = $this->domainModuleForSource($file);
        $requiredDomains = config('domain_routes.tenant_context.required_domains', []);

        if ($owner !== null && is_array($requiredDomains) && in_array($owner['domain'], $requiredDomains, true)) {
            $middleware[] = RequireTenantContext::class;
        }

        return $middleware;
    }

    /**
     * @param  callable(): void  $load
     */
    private function registerFile(string $file, callable $load): void
    {
        $before = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $before[spl_object_id($route)] = true;
        }

        $load();

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (isset($before[spl_object_id($route)])) {
                continue;
            }

            $this->fileByRouteObjectId[spl_object_id($route)] = $file;

            foreach ($this->routeKeys($route) as $key) {
                $owner = $this->fileByRouteKey[$key] ?? null;

                if ($owner !== null && $owner !== $file) {
                    throw new RouteCollisionException(sprintf(
                        'Route %s is registered by more than one module route file: %s and %s. Laravel would keep only the last one; give each module its own URI.',
                        $key,
                        $owner,
                        $file,
                    ));
                }

                $this->fileByRouteKey[$key] = $file;
            }

            $name = $route->getName();
            if ($name === null || $name === '') {
                continue;
            }

            $owner = $this->fileByRouteName[$name] ?? null;

            if ($owner !== null && $owner !== $file) {
                throw new RouteCollisionException(sprintf(
                    'Route name %s is registered by more than one module route file: %s and %s. Laravel would keep only the last one; give each module its own route name.',
                    $name,
                    $owner,
                    $file,
                ));
            }

            $this->fileByRouteName[$name] = $file;
        }
    }

    /**
     * One key per HTTP method the route answers, so `GET` and `POST` on one
     * URI stay distinct routes, exactly as Laravel's collection keys them.
     *
     * @return list<string>
     */
    private function routeKeys(RegisteredRoute $route): array
    {
        return array_map(
            fn (string $method): string => $method.' '.$route->getDomain().'/'.ltrim($route->uri(), '/'),
            $route->methods(),
        );
    }
}
