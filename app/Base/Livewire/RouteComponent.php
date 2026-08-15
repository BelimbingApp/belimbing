<?php

namespace App\Base\Livewire;

use Illuminate\Routing\Route;
use Livewire\Component;
use Livewire\Mechanisms\HandleRouting\LivewirePageController;

/**
 * The Livewire component class behind a route, if there is one.
 *
 * Livewire registers page components two different ways, and only one of them
 * leaves a `livewire_component` action on the route:
 *
 * - `Route::livewire($uri, $name)` points the route at LivewirePageController
 *   and stashes the component under `livewire_component`.
 * - `Route::get($uri, Component::class)` — what BLB uses everywhere — registers
 *   the component as an ordinary invokable controller, so the class is in
 *   `uses` as `Class@__invoke` and `livewire_component` is never set.
 *
 * Code that reads only `livewire_component` therefore finds nothing on any
 * BLB route and silently takes its fallback path forever. Livewire's own
 * SupportPageComponents handles both cases, but keeps that knowledge private —
 * so it lives here once instead of being half-remembered at each call site.
 */
final class RouteComponent
{
    /**
     * @return class-string<Component>|null
     */
    public static function classFor(Route $route): ?string
    {
        $uses = $route->getAction('uses');

        if (is_string($uses) && str_ends_with($uses, '@__invoke')) {
            $class = substr($uses, 0, -strlen('@__invoke'));

            if (is_subclass_of($class, Component::class)) {
                return $class;
            }
        }

        if (is_string($uses) && str_contains($uses, LivewirePageController::class)) {
            $component = $route->getAction('livewire_component');

            if (is_string($component) && $component !== '') {
                $class = app('livewire.factory')->resolveComponentClass($component);

                return is_subclass_of($class, Component::class) ? $class : null;
            }
        }

        return null;
    }
}
