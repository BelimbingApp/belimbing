<?php

use App\Base\Foundation\Services\ModuleCheck;
use App\Base\Perf\Services\PerfRuntimeSettings;
use App\Base\Routing\DomainRouteMiddlewareAudit;
use App\Base\Routing\Livewire\TenantAudit\Index;
use App\Base\Routing\RouteDiscoveryService;
use App\Base\Routing\Services\TenantAuditPageData;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Middleware\RequireTenantContext;
use App\Base\Tenancy\Services\TenantContextMissRecorder;
use App\Base\Tenancy\Services\TenantResolutionMix;
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
    $mutated = new class(app(DomainRouteMiddlewareAudit::class), app(ModuleCheck::class), app(TenantContextMissRecorder::class), app(TenantResolutionMix::class), app(TenantContext::class)) extends TenantAuditPageData
    {
        public function routeLacksAuthorizationMiddleware(array $middleware): bool
        {
            return false;
        }
    };

    expect(collect($mutated->routeRows())->firstWhere('name', TENANT_AUDIT_ROUTE))->toBeNull();
});

/*
 * Resolution mix (#781): requests per tenant resolver over the last 24 hours,
 * read from the request performance log, scoped to the current tenant.
 */
function tenantAuditPerfDir(): string
{
    $dir = storage_path('framework/testing/perf-audit-'.uniqid());
    app(SettingsService::class)->set(PerfRuntimeSettings::LOG_PATH_KEY, $dir);
    File::ensureDirectoryExists($dir);

    return $dir;
}

/** @param  array<string, mixed>  $overrides */
function tenantAuditPerfEntry(string $dir, array $overrides = []): void
{
    $entry = [
        'ts' => now()->toIso8601String(),
        'type' => 'http',
        'method' => 'GET',
        'path' => '/probe',
        'route' => 'probe',
        'tenant_resolver' => 'session',
        'tenant_id' => 1,
        'status' => 200,
        'ms' => 1.0,
        ...$overrides,
    ];

    file_put_contents($dir.'/perf-'.now()->format('Y-m-d').'.jsonl', json_encode($entry).PHP_EOL, FILE_APPEND);
}

it('counts requests per resolver for the current tenant only, plus the misses', function (): void {
    $dir = tenantAuditPerfDir();
    $admin = createAdminUser();
    $tenantId = app(TenantContext::class)->requireTenantId();

    tenantAuditPerfEntry($dir, ['tenant_id' => $tenantId, 'tenant_resolver' => 'session']);
    tenantAuditPerfEntry($dir, ['tenant_id' => $tenantId, 'tenant_resolver' => 'session']);
    tenantAuditPerfEntry($dir, ['tenant_id' => $tenantId, 'tenant_resolver' => 'host']);
    tenantAuditPerfEntry($dir, ['tenant_id' => null, 'tenant_resolver' => null, 'status' => 404]);
    // Another tenant's requests, and one older than the window.
    tenantAuditPerfEntry($dir, ['tenant_id' => $tenantId + 1, 'tenant_resolver' => 'session']);
    tenantAuditPerfEntry($dir, ['tenant_id' => $tenantId, 'tenant_resolver' => 'session', 'ts' => now()->subHours(25)->toIso8601String()]);
    // A queued job line is not a request.
    tenantAuditPerfEntry($dir, ['tenant_id' => $tenantId, 'type' => 'job']);

    $mix = app(TenantAuditPageData::class)->resolutionMix();

    expect($mix['resolvers'])->toBe(['session' => 2, 'host' => 1])
        ->and($mix['none'])->toBe(1)
        ->and($mix['hours'])->toBe(24);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertSee('Resolution mix')
        ->assertSeeHtml('data-resolution-mix="session"')
        ->assertSeeHtml('data-resolution-mix="host"')
        ->assertSeeHtml('data-resolution-mix="none"');

    File::deleteDirectory($dir);
});

it('reads an empty mix when the performance log has no request in the window', function (): void {
    $dir = tenantAuditPerfDir();
    createAdminUser();

    $mix = app(TenantAuditPageData::class)->resolutionMix();

    expect($mix['resolvers'])->toBe([])
        ->and($mix['none'])->toBe(0);

    File::deleteDirectory($dir);
});
