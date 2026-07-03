<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Menu\MenuRegistry;
use App\Base\Menu\Services\MenuDiscoveryService;
use App\Base\Menu\Services\MenuLinkResolver;
use App\Base\Menu\Services\MenuRegistryLoader;
use App\Base\Menu\Services\MenuStatusDiagnosticProvider;
use Illuminate\Support\Facades\Route;
use Tests\Support\MenuTestFixtures;

it('reports unresolved visible menu links for authorized menu inspectors', function (): void {
    Route::get('/_test/status-menu-ok', fn (): string => 'ok')->name('test.status-menu.ok');
    Route::get('/_test/status-menu-param/{slug}', fn (string $slug): string => $slug)->name('test.status-menu.param');
    Route::getRoutes()->refreshNameLookups();

    $registry = new MenuRegistry;
    $registry->registerFromDiscovery(collect([
        MenuTestFixtures::routeItem('ok', 'Ok', 'test.status-menu.ok'),
        MenuTestFixtures::routeItem('missing', 'Missing', 'test.status-menu.missing'),
        MenuTestFixtures::routeItem('needs-param', 'Needs Param', 'test.status-menu.param'),
        MenuTestFixtures::routeItem('hidden', 'Hidden', 'test.status-menu.missing-hidden', 'hidden.permission'),
        MenuTestFixtures::urlItem('literal', 'Literal', '/literal'),
    ]));

    $provider = new MenuStatusDiagnosticProvider(
        $registry,
        MenuTestFixtures::accessChecker('hidden'),
        new MenuRegistryLoader($registry, Mockery::mock(MenuDiscoveryService::class)),
        new MenuLinkResolver,
        authorizedForMenuInspector(),
    );

    $diagnostics = collect($provider->diagnosticsFor(createAdminUser()))->keyBy('id');

    expect($diagnostics->keys()->all())->toBe([
        'menu.unresolvable-link.missing',
        'menu.unresolvable-link.needs-param',
    ]);

    $missing = $diagnostics['menu.unresolvable-link.missing'];
    expect($missing->summary)->toBe('Menu item hidden: Missing');
    expect($missing->metadata)->toMatchArray([
        'id' => 'missing',
        'route' => 'test.status-menu.missing',
        'reason' => 'missing_route',
        'source_file' => 'tests/menu.php',
    ]);

    expect($diagnostics['menu.unresolvable-link.needs-param']->metadata)
        ->toMatchArray([
            'id' => 'needs-param',
            'route' => 'test.status-menu.param',
            'reason' => 'url_generation_failed',
        ]);
});

it('hides menu diagnostics when the user cannot inspect menus', function (): void {
    $registry = new MenuRegistry;
    $registry->registerFromDiscovery(collect([
        MenuTestFixtures::routeItem('missing', 'Missing', 'test.status-menu.missing'),
    ]));

    $provider = new MenuStatusDiagnosticProvider(
        $registry,
        MenuTestFixtures::accessChecker(),
        new MenuRegistryLoader($registry, Mockery::mock(MenuDiscoveryService::class)),
        new MenuLinkResolver,
        deniedForMenuInspector(),
    );

    expect(collect($provider->diagnosticsFor(createAdminUser())))->toBeEmpty();
});

function authorizedForMenuInspector(): AuthorizationService
{
    $service = Mockery::mock(AuthorizationService::class);
    $service->shouldReceive('can')
        ->once()
        ->withArgs(fn (mixed $actor, string $capability): bool => $capability === 'admin.system.menu-inspector.view')
        ->andReturn(AuthorizationDecision::allow());

    return $service;
}

function deniedForMenuInspector(): AuthorizationService
{
    $service = Mockery::mock(AuthorizationService::class);
    $service->shouldReceive('can')
        ->once()
        ->withArgs(fn (mixed $actor, string $capability): bool => $capability === 'admin.system.menu-inspector.view')
        ->andReturn(AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY));

    return $service;
}
