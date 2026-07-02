<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Menu\Contracts\MenuAccessChecker;
use App\Base\Menu\MenuItem;
use App\Base\Menu\MenuRegistry;
use App\Base\Menu\Services\MenuDiscoveryService;
use App\Base\Menu\Services\MenuLinkResolver;
use App\Base\Menu\Services\MenuRegistryLoader;
use App\Base\Menu\Services\MenuStatusDiagnosticProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;

it('reports unresolved visible menu links for authorized menu inspectors', function (): void {
    Route::get('/_test/status-menu-ok', fn (): string => 'ok')->name('test.status-menu.ok');
    Route::get('/_test/status-menu-param/{slug}', fn (string $slug): string => $slug)->name('test.status-menu.param');
    Route::getRoutes()->refreshNameLookups();

    $registry = new MenuRegistry;
    $registry->registerFromDiscovery(collect([
        [
            'id' => 'ok',
            'label' => 'Ok',
            'route' => 'test.status-menu.ok',
            '_source' => ['file' => 'tests/menu.php', 'module_name' => 'Tests'],
        ],
        [
            'id' => 'missing',
            'label' => 'Missing',
            'route' => 'test.status-menu.missing',
            '_source' => ['file' => 'tests/menu.php', 'module_name' => 'Tests'],
        ],
        [
            'id' => 'needs-param',
            'label' => 'Needs Param',
            'route' => 'test.status-menu.param',
            '_source' => ['file' => 'tests/menu.php', 'module_name' => 'Tests'],
        ],
        [
            'id' => 'hidden',
            'label' => 'Hidden',
            'route' => 'test.status-menu.missing-hidden',
            'permission' => 'hidden.permission',
            '_source' => ['file' => 'tests/menu.php', 'module_name' => 'Tests'],
        ],
        [
            'id' => 'literal',
            'label' => 'Literal',
            'url' => '/literal',
            '_source' => ['file' => 'tests/menu.php', 'module_name' => 'Tests'],
        ],
    ]));

    $provider = new MenuStatusDiagnosticProvider(
        $registry,
        new class implements MenuAccessChecker
        {
            public function canView(MenuItem $item, Authenticatable $user): bool
            {
                return $item->id !== 'hidden';
            }
        },
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
        [
            'id' => 'missing',
            'label' => 'Missing',
            'route' => 'test.status-menu.missing',
            '_source' => ['file' => 'tests/menu.php', 'module_name' => 'Tests'],
        ],
    ]));

    $provider = new MenuStatusDiagnosticProvider(
        $registry,
        new class implements MenuAccessChecker
        {
            public function canView(MenuItem $item, Authenticatable $user): bool
            {
                return true;
            }
        },
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
