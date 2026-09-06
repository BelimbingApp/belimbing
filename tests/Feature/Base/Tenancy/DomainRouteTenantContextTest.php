<?php

use App\Base\Routing\DomainRouteInventory;
use App\Base\Routing\RouteDiscoveryService;
use App\Base\Tenancy\Middleware\RequireTenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\File;

const TENANT_REQUIRED_DOMAIN_FIXTURE = 'app/Domains/ZzTenantRequired/Fixture';
const TENANT_REQUIRED_ROUTE_NAME = 'zz-tenant-required.probe';
const TENANT_REQUIRED_ROUTE_PROBE = 'zz_tenant_required_route_reached';

function registerTenantRequiredDomainFixture(): void
{
    $routes = base_path(TENANT_REQUIRED_DOMAIN_FIXTURE.'/Routes');
    File::ensureDirectoryExists($routes);
    File::put($routes.'/web.php', <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('zz-tenant-required/probe', function () {
    $GLOBALS['zz_tenant_required_route_reached'] = true;

    return response()->json(['reached' => true]);
})->name('zz-tenant-required.probe');
PHP);

    config()->set('domain_routes.tenant_context.required_domains', ['ZzTenantRequired']);
    app(RouteDiscoveryService::class)->registerRoutes(['web' => [$routes.'/web.php']]);
}

afterEach(function (): void {
    File::deleteDirectory(base_path('app/Domains/ZzTenantRequired'));
    unset($GLOBALS[TENANT_REQUIRED_ROUTE_PROBE]);
});

it('refuses a tenant-required domain route before its handler runs when context is unresolved', function (): void {
    registerTenantRequiredDomainFixture();
    $GLOBALS[TENANT_REQUIRED_ROUTE_PROBE] = false;

    $this->getJson('/zz-tenant-required/probe')
        ->assertNotFound()
        ->assertJson(['reason_code' => 'tenant_context_missing']);

    expect($GLOBALS[TENANT_REQUIRED_ROUTE_PROBE])->toBeFalse();
});

it('allows a tenant-required domain route after web middleware resolves the tenant', function (): void {
    registerTenantRequiredDomainFixture();
    $user = User::factory()->create(['company_id' => Company::factory()->create()->id]);

    $this->actingAs($user)
        ->getJson('/zz-tenant-required/probe')
        ->assertOk()
        ->assertExactJson(['reached' => true]);
});

it('allows only named exclusions carrying a non-empty reason', function (): void {
    config()->set('domain_routes.tenant_context.exclusions', [
        TENANT_REQUIRED_ROUTE_NAME => 'External signed callback resolves its tenant from the payload.',
    ]);
    registerTenantRequiredDomainFixture();

    $this->getJson('/zz-tenant-required/probe')
        ->assertOk()
        ->assertExactJson(['reached' => true]);

    config()->set('domain_routes.tenant_context.exclusions', [TENANT_REQUIRED_ROUTE_NAME => '  ']);
    $GLOBALS[TENANT_REQUIRED_ROUTE_PROBE] = false;

    $this->getJson('/zz-tenant-required/probe')
        ->assertNotFound()
        ->assertJson(['reason_code' => 'tenant_context_missing']);

    expect($GLOBALS[TENANT_REQUIRED_ROUTE_PROBE])->toBeFalse();
});

it('declares the guarded domains, an empty exclusion list, and visible middleware provenance', function (): void {
    expect(config('domain_routes.tenant_context.required_domains'))->toBe(['People', 'PeopleConnector'])
        ->and(config('domain_routes.tenant_context.exclusions'))->toBe([]);

    registerTenantRequiredDomainFixture();
    $row = collect(app(DomainRouteInventory::class)->all())
        ->firstWhere('name', TENANT_REQUIRED_ROUTE_NAME);

    expect($row)->not->toBeNull()
        ->and($row['middleware'])->toContain('web', RequireTenantContext::class);
});
