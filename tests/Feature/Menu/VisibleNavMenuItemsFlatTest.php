<?php

use App\Base\Menu\MenuRegistry;
use App\Base\Menu\Services\MenuDiscoveryService;
use App\Base\Menu\Services\MenuLinkResolver;
use App\Base\Menu\Services\MenuRegistryLoader;
use App\Base\Menu\Services\PinMetadataNormalizer;
use App\Base\Menu\Services\VisibleNavMenuItemsFlat;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Support\MenuTestFixtures;

it('hides unresolvable menu links instead of failing the app shell', function (): void {
    Cache::flush();

    Route::get('/_test/menu-ok', fn (): string => 'ok')->name('test.menu.ok');
    Route::get('/_test/menu-param/{slug}', fn (string $slug): string => $slug)->name('test.menu.param');
    Route::getRoutes()->refreshNameLookups();

    Log::spy();

    $discovery = Mockery::mock(MenuDiscoveryService::class);
    $discovery->shouldReceive('configFingerprint')->once()->andReturn('test-menu-links');
    $discovery->shouldReceive('discover')->once()->andReturn(collect([
        MenuTestFixtures::routeItem('ok', 'Ok', 'test.menu.ok'),
        MenuTestFixtures::routeItem('missing', 'Missing', 'test.menu.missing'),
        MenuTestFixtures::routeItem('needs-param', 'Needs Param', 'test.menu.param'),
        MenuTestFixtures::urlItem('literal', 'Literal', '/literal'),
    ]));

    $registry = new MenuRegistry;

    $snapshot = new VisibleNavMenuItemsFlat(
        $registry,
        MenuTestFixtures::accessChecker(),
        new MenuRegistryLoader($registry, $discovery),
        new MenuLinkResolver,
        new PinMetadataNormalizer,
    );

    $visible = $snapshot->snapshotForUser(User::factory()->create());
    $again = $snapshot->snapshotForUser(User::factory()->create());

    expect($visible['flat'])
        ->toHaveKeys(['ok', 'literal'])
        ->not->toHaveKeys(['missing', 'needs-param'])
        ->and($visible['filtered']->pluck('id')->all())
        ->toBe(['ok', 'literal'])
        ->and($again['filtered']->pluck('id')->all())
        ->toBe(['ok', 'literal']);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Menu item hidden because its link cannot be resolved.'
            && $context['id'] === 'missing'
            && $context['route'] === 'test.menu.missing'
            && $context['reason'] === 'missing_route'
            && $context['source_file'] === 'tests/menu.php')
        ->once();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Menu item hidden because its link cannot be resolved.'
            && $context['id'] === 'needs-param'
            && $context['route'] === 'test.menu.param'
            && $context['reason'] === 'url_generation_failed'
            && str_contains($context['error'], 'Missing required parameter'))
        ->once();
});
