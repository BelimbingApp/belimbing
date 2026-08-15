<?php

use App\Base\Livewire\ComponentDiscoveryService;
use App\Base\Livewire\RouteComponent;

/**
 * A routed page component must resolve back to its own class.
 *
 * The failure this guards: Route::get($uri, Component::class) registers the
 * component as an invokable controller, and its __invoke mounts it *by name* —
 * getName() is null on a container-built instance, so Livewire kebab-cases the
 * FQCN and mounts whatever that name resolves to. A component discovery could
 * not name (it inherits render() from a family base, so it has no view() call
 * of its own) had no answer to that name, and the route 500'd on a page that
 * existed and routed correctly. Ten shipped that way before anyone noticed.
 */
it('resolves every routed page component back to its own class', function (): void {
    $routed = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): ?string => RouteComponent::classFor($route))
        ->filter()
        ->unique()
        ->values();

    expect($routed)->not->toBeEmpty();

    foreach ($routed as $class) {
        expect(app('livewire')->new($class))->toBeInstanceOf($class);
    }
});

it('registers no abstract component', function (): void {
    // An abstract family base carries the view() call its children render, so
    // discovery once named it and registered it. Anything that mounted the
    // name fataled on "cannot instantiate abstract class".
    $abstract = array_filter(
        app(ComponentDiscoveryService::class)->discover(),
        fn (string $class): bool => (new ReflectionClass($class))->isAbstract(),
    );

    expect($abstract)->toBe([]);
});

it('gives every registered component a name of its own', function (): void {
    // Two components under one name means the second silently overwrites the
    // first in the registry and the first becomes unreachable.
    $discovered = app(ComponentDiscoveryService::class)->discover();

    expect(array_diff_assoc($discovered, array_unique($discovered)))->toBe([]);
});

it('mounts every registered component name without fataling', function (): void {
    // The fallback names an unnamed component after its class, so anything
    // discovery lets through is now reachable by name — including, before the
    // abstract guard above, a family base that fatals on instantiation.
    foreach (app(ComponentDiscoveryService::class)->discover() as $name => $class) {
        expect(app('livewire')->new($name))->toBeInstanceOf($class);
    }
});
