<?php

use App\Base\Foundation\Services\ModuleCheck;
use App\Base\Routing\DomainRouteMiddlewareAudit;
use App\Base\Routing\Livewire\TenantAudit\Index;
use App\Base\Routing\RouteDiscoveryService;
use App\Base\Routing\Services\TenantAuditPageData;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Middleware\RequireTenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const TENANT_AUDIT_FIXTURE = 'app/Domains/ZzTenantAudit/Fixture';
const TENANT_AUDIT_ROUTE = 'zz-tenant-audit.probe';

function writeTenantAuditRouteFixture(string $routeBody): string
{
    $root = base_path(TENANT_AUDIT_FIXTURE.'/Routes');
    File::ensureDirectoryExists($root);
    $web = $root.'/web.php';
    File::put($web, $routeBody);

    return $web;
}

function registerTenantAuditAuthzGapFixture(): void
{
    $web = writeTenantAuditRouteFixture(<<<'PHP'
<?php

use App\Base\Tenancy\Middleware\RequireTenantContext;
use Illuminate\Support\Facades\Route;

Route::middleware(RequireTenantContext::class)
    ->get('zz-tenant-audit/probe', fn () => 'ok')
    ->name('zz-tenant-audit.probe');
PHP);

    config()->set('domain_routes.tenant_context.required_domains', []);
    config()->set('domain_routes.middleware_audit.allowlist', []);
    app(RouteDiscoveryService::class)->registerRoutes(['web' => [$web]]);
    config()->set('domain_routes.tenant_context.required_domains', ['ZzTenantAudit']);
}

afterEach(function (): void {
    File::deleteDirectory(base_path('app/Domains/ZzTenantAudit'));
});

beforeEach(function (): void {
    setupAuthzRoles();
});

it('denies the tenant-audit page without admin.system.audit.view', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    app(TenantContext::class)->set((int) $company->tenant_id);

    $this->actingAs($user)
        ->get(route('admin.system.tenant-audit.index'))
        ->assertForbidden();
});

it('shows a red row for a fixture Domain route that lacks authz middleware', function (): void {
    registerTenantAuditAuthzGapFixture();

    $admin = createAdminUser();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertSee(TENANT_AUDIT_ROUTE)
        ->assertSee('authorization')
        ->assertSeeHtml('data-audit-severity="danger"');

    $match = collect(app(TenantAuditPageData::class)->routeRows())
        ->firstWhere('name', TENANT_AUDIT_ROUTE);

    expect($match)->not->toBeNull()
        ->and($match['missing'])->toContain('authorization')
        ->and($match['severity'])->toBe('danger')
        ->and($match['middleware'])->toContain(RequireTenantContext::class);
});

it('drops the authorization finding when the page data source check is removed', function (): void {
    registerTenantAuditAuthzGapFixture();

    expect(collect(app(TenantAuditPageData::class)->routeRows())->firstWhere('name', TENANT_AUDIT_ROUTE))
        ->not->toBeNull();

    // Acceptance mutation: delete/disable the page-owned authz check (not DomainRouteMiddlewareAudit).
    $mutated = new class(app(DomainRouteMiddlewareAudit::class), app(ModuleCheck::class)) extends TenantAuditPageData
    {
        public function routeLacksAuthorizationMiddleware(array $middleware): bool
        {
            return false;
        }
    };

    expect(collect($mutated->routeRows())->firstWhere('name', TENANT_AUDIT_ROUTE))->toBeNull();
});
