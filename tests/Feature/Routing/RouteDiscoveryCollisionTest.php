<?php

use App\Base\Routing\Exceptions\RouteCollisionException;
use App\Base\Routing\RouteDiscoveryService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

const ROUTE_COLLISION_EXTENSION_ROOT = 'app/Extensions/';

/**
 * Write a throwaway extension module whose Routes/web.php registers one route.
 * Returns the absolute path of the route file.
 */
function routeCollisionFixture(string $owner, string $module, string $method, string $uri, string $name): string
{
    $directory = base_path(ROUTE_COLLISION_EXTENSION_ROOT.$owner.'/'.$module.'/Routes');
    File::ensureDirectoryExists($directory);

    $file = $directory.'/web.php';
    file_put_contents($file, sprintf(
        "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::%s('%s', fn () => 'ok')->name('%s');\n",
        $method,
        $uri,
        $name,
    ));

    return $file;
}

it('refuses two module route files that register the same method and URI', function (): void {
    $owner = 'zz-route-collision-'.bin2hex(random_bytes(4));
    $uri = 'zz-route-collision/'.bin2hex(random_bytes(4));
    $first = routeCollisionFixture($owner, 'first', 'get', $uri, $owner.'.first');
    $second = routeCollisionFixture($owner, 'second', 'get', $uri, $owner.'.second');

    try {
        app(RouteDiscoveryService::class)->registerRoutes(['web' => [$first, $second]]);
    } catch (RouteCollisionException $exception) {
        expect($exception->getMessage())
            ->toContain('GET')
            ->toContain($uri)
            ->toContain($first)
            ->toContain($second);

        return;
    } finally {
        File::deleteDirectory(base_path(ROUTE_COLLISION_EXTENSION_ROOT.$owner));
    }

    expect(false)->toBeTrue('Expected the route collision guard to refuse the second file.');
});

it('accepts distinct URIs across module route files and the same URI under a different method', function (): void {
    $owner = 'zz-route-distinct-'.bin2hex(random_bytes(4));
    $uri = 'zz-route-distinct/'.bin2hex(random_bytes(4));
    $first = routeCollisionFixture($owner, 'first', 'get', $uri, $owner.'.first');
    $second = routeCollisionFixture($owner, 'second', 'post', $uri, $owner.'.second');
    $third = routeCollisionFixture($owner, 'third', 'get', $uri.'/other', $owner.'.third');

    try {
        app(RouteDiscoveryService::class)->registerRoutes(['web' => [$first, $second, $third]]);
    } finally {
        File::deleteDirectory(base_path(ROUTE_COLLISION_EXTENSION_ROOT.$owner));
    }

    $registered = collect(Route::getRoutes()->getRoutes());
    $methodsFor = fn (string $registeredUri): array => $registered
        ->filter(fn ($route): bool => $route->uri() === $registeredUri)
        ->flatMap(fn ($route): array => $route->methods())
        ->unique()
        ->values()
        ->all();

    expect($methodsFor($uri))->toContain('GET', 'POST')
        ->and($methodsFor($uri.'/other'))->toContain('GET');
});
