<?php

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\Contracts\ProvidesLaraPageContext;
use App\Modules\Core\AI\Contracts\ProvidesLaraPageSnapshot;
use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\DTO\PageSnapshot;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Resolves page context from the user's active page URL.
 *
 * The Chat component runs inside a Livewire update request whose route is
 * `/livewire/update` — not the user's page. To discover the real page, the
 * client passes `window.location.href` and this resolver matches it against
 * Laravel's router to find the page route and its Livewire component.
 */
class PageContextResolver
{
    /** Route prefix → module name mapping. */
    private const PREFIX_MODULE_MAP = [
        'admin.employees' => 'Employee',
        'admin.companies' => 'Company',
        'admin.roles' => 'Role',
        'admin.users' => 'User',
        'admin.ai' => 'AI',
        'admin.setup' => 'Setup',
        'admin.addresses' => 'Address',
        'admin.departments' => 'Department',
        'admin.system' => 'System',
        'admin.workflows' => 'Workflow',
        'admin.audit' => 'Audit',
    ];

    public function __construct(
        private readonly Router $router,
    ) {}

    /**
     * Resolve page context from a URL sent by the client.
     *
     * Matches the URL against the application's routes to find the page
     * component and its ProvidesLaraPageContext contract.
     */
    public function resolveFromUrl(string $url): ?PageContext
    {
        $route = $this->matchRoute($url);

        if ($route === null) {
            return null;
        }

        $routeName = $route->getName();

        if (! is_string($routeName) || $routeName === '') {
            return null;
        }

        $componentClass = $route->getAction('livewire_component');

        if (is_string($componentClass)
            && class_exists($componentClass)
            && is_subclass_of($componentClass, ProvidesLaraPageContext::class)
        ) {
            return $this->resolveFromComponent($componentClass, $route, $url);
        }

        return $this->resolveFromRoute($route, $routeName, $url);
    }

    /**
     * Resolve snapshot from the page component behind a URL.
     */
    public function resolveSnapshotFromUrl(string $url): ?PageSnapshot
    {
        $route = $this->matchRoute($url);

        if ($route === null) {
            return null;
        }

        $componentClass = $route->getAction('livewire_component');

        if (! is_string($componentClass)
            || ! class_exists($componentClass)
            || ! is_subclass_of($componentClass, ProvidesLaraPageSnapshot::class)
        ) {
            return null;
        }

        try {
            $component = app($componentClass);

            if ($component instanceof ProvidesLaraPageSnapshot) {
                return $component->pageSnapshot();
            }
        } catch (\Throwable) {
            // Component requires constructor args we can't provide
        }

        return null;
    }

    /**
     * Match a full URL to a Laravel route via the router.
     *
     * Creates a synthetic GET request from the URL path and matches it
     * against the registered routes. Returns null on mismatch.
     */
    private function matchRoute(string $url): ?Route
    {
        if ($url === '') {
            return null;
        }

        try {
            $path = parse_url($url, PHP_URL_PATH);

            if (! is_string($path) || $path === '') {
                return null;
            }

            $fakeRequest = Request::create($path, 'GET');
            $route = $this->router->getRoutes()->match($fakeRequest);

            // Bind route parameters so model IDs are accessible
            $route->bind($fakeRequest);

            return $route;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve context by calling the component's pageContext() method.
     *
     * @param  class-string<ProvidesLaraPageContext>  $componentClass
     */
    private function resolveFromComponent(string $componentClass, Route $route, string $url): ?PageContext
    {
        try {
            $component = app($componentClass);

            if ($component instanceof ProvidesLaraPageContext) {
                $this->hydrateRouteParameters($component, $route);

                return $this->enrichFromUrl($component->pageContext($url), $url, $route);
            }
        } catch (\Throwable) {
            // Fall through to route-derived context
        }

        $routeName = $route->getName();

        return is_string($routeName) ? $this->resolveFromRoute($route, $routeName, $url) : null;
    }

    /**
     * Copy matched route parameters onto public Livewire component properties.
     *
     * matchRoute() binds a synthetic request without SubstituteBindings, so
     * values are always raw URL segments. A property whose declared type
     * rejects the segment (e.g. a typed model) is skipped instead of
     * aborting hydration for the whole component.
     */
    private function hydrateRouteParameters(object $component, Route $route): void
    {
        foreach ($route->parameters() as $name => $value) {
            if (! property_exists($component, $name) || (! is_scalar($value) && $value !== null)) {
                continue;
            }

            try {
                $component->{$name} = $value;
            } catch (\TypeError) {
                // Property type does not accept the raw segment; leave it unset.
            }
        }
    }

    /**
     * Ensure client URL (and hash tab) win when the component omitted them.
     */
    private function enrichFromUrl(PageContext $context, string $url, Route $route): PageContext
    {
        $overrides = [];

        if ($context->url === '' || ! str_contains($context->url, '://')) {
            $overrides['url'] = $url;
        }

        $hashTab = $this->hashFragment($url);

        if ($context->activeTab === null && $hashTab !== null) {
            $overrides['active_tab'] = $hashTab;
        }

        if ($context->resourceId === null) {
            $resourceId = $this->resourceIdFromRoute($route);

            if ($resourceId !== null) {
                $overrides['resource_id'] = $resourceId;
            }
        }

        return $overrides === [] ? $context : $context->with($overrides);
    }

    private function hashFragment(string $url): ?string
    {
        $fragment = parse_url($url, PHP_URL_FRAGMENT);

        if (! is_string($fragment) || trim($fragment) === '') {
            return null;
        }

        return trim($fragment);
    }

    /**
     * Build minimal context from the route alone.
     */
    private function resolveFromRoute(Route $route, string $routeName, string $url): PageContext
    {
        $module = $this->moduleFromRoute($routeName);
        $resourceType = $this->resourceTypeFromRoute($routeName);
        $resourceId = $this->resourceIdFromRoute($route);

        return new PageContext(
            route: $routeName,
            url: $url,
            module: $module,
            resourceType: $resourceType,
            resourceId: $resourceId,
        );
    }

    /**
     * Infer module name from route prefix.
     */
    private function moduleFromRoute(string $routeName): ?string
    {
        foreach (self::PREFIX_MODULE_MAP as $prefix => $module) {
            if (str_starts_with($routeName, $prefix)) {
                return $module;
            }
        }

        return null;
    }

    /**
     * Infer resource type from route name segments.
     *
     * 'admin.employees.show' → 'employee'
     * 'admin.companies.index' → 'company'
     */
    private function resourceTypeFromRoute(string $routeName): ?string
    {
        $segments = explode('.', $routeName);

        if (count($segments) < 3) {
            return null;
        }

        $resource = $segments[count($segments) - 2];

        if (str_ends_with($resource, 'ies')) {
            return substr($resource, 0, -3).'y';
        }

        if (str_ends_with($resource, 'ses') || str_ends_with($resource, 'xes')) {
            return substr($resource, 0, -2);
        }

        if (str_ends_with($resource, 's') && ! str_ends_with($resource, 'ss')) {
            return substr($resource, 0, -1);
        }

        return $resource;
    }

    /**
     * Extract resource ID from route parameters.
     */
    private function resourceIdFromRoute(Route $route): int|string|null
    {
        $params = $route->parameters();

        if ($params === []) {
            return null;
        }

        foreach ($params as $value) {
            if (is_object($value) && method_exists($value, 'getKey')) {
                return $value->getKey();
            }

            if (is_int($value) || is_string($value)) {
                return $value;
            }
        }

        return null;
    }
}
