<?php

use App\Base\Routing\DomainRouteInventory;
use App\Base\Routing\RouteDiscoveryService;
use App\Base\Tenancy\Middleware\RequireTenantContext;
use Illuminate\Support\Facades\File;

const DOMAIN_ROUTE_AUDIT_FIXTURE = 'app/Domains/ZzRouteAudit/Fixture';
const DOMAIN_ROUTE_AUDIT_NAME = 'zz-route-audit.probe';

function writeDomainRouteAuditFixture(string $routeBody): string
{
    $root = base_path(DOMAIN_ROUTE_AUDIT_FIXTURE.'/Routes');
    File::ensureDirectoryExists($root);
    $web = $root.'/web.php';
    File::put($web, $routeBody);

    return $web;
}

afterEach(function (): void {
    File::deleteDirectory(base_path('app/Domains/ZzRouteAudit'));
});

it('fails the audit when a required-domain fixture lacks authorization middleware', function (): void {
    $web = writeDomainRouteAuditFixture(<<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('zz-route-audit/probe', fn () => 'ok')
    ->name('zz-route-audit.probe');
PHP);

    // Register without auto-applying tenant middleware, then audit as required.
    config()->set('domain_routes.tenant_context.required_domains', []);
    config()->set('domain_routes.middleware_audit.allowlist', []);
    app(RouteDiscoveryService::class)->registerRoutes(['web' => [$web]]);
    config()->set('domain_routes.tenant_context.required_domains', ['ZzRouteAudit']);

    $this->artisan('blb:domain-routes', ['--audit' => true])
        ->expectsOutputToContain('missing tenant assertion and/or authorization middleware')
        ->expectsTable(
            ['Domain', 'Module', 'Methods', 'URI', 'Name', 'Missing', 'Middleware'],
            [
                [
                    'ZzRouteAudit',
                    'Fixture',
                    'GET|HEAD',
                    'zz-route-audit/probe',
                    DOMAIN_ROUTE_AUDIT_NAME,
                    'tenant, authorization',
                    'web',
                ],
            ],
        )
        ->assertFailed();
});

it('passes the audit when the fixture carries tenant assertion and authz middleware', function (): void {
    $web = writeDomainRouteAuditFixture(<<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::middleware('authz:zz.route.audit.view')
    ->get('zz-route-audit/probe', fn () => 'ok')
    ->name('zz-route-audit.probe');
PHP);

    config()->set('domain_routes.tenant_context.required_domains', ['ZzRouteAudit']);
    config()->set('domain_routes.middleware_audit.allowlist', []);
    app(RouteDiscoveryService::class)->registerRoutes(['web' => [$web]]);

    $this->artisan('blb:domain-routes', ['--audit' => true])
        ->expectsOutputToContain('Domain route middleware audit passed.')
        ->assertSuccessful();

    $this->artisan('blb:domain-routes', ['--audit' => true, '--json' => true])
        ->expectsOutput('[]')
        ->assertSuccessful();
});

it('honours a non-empty middleware audit allowlist reason', function (): void {
    $web = writeDomainRouteAuditFixture(<<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('zz-route-audit/probe', fn () => 'ok')
    ->name('zz-route-audit.probe');
PHP);

    config()->set('domain_routes.tenant_context.required_domains', []);
    config()->set('domain_routes.middleware_audit.allowlist', [
        DOMAIN_ROUTE_AUDIT_NAME => 'Fixture exempted for audit coverage.',
    ]);
    app(RouteDiscoveryService::class)->registerRoutes(['web' => [$web]]);
    config()->set('domain_routes.tenant_context.required_domains', ['ZzRouteAudit']);

    $this->artisan('blb:domain-routes', ['--audit' => true])->assertSuccessful();

    config()->set('domain_routes.middleware_audit.allowlist', [
        DOMAIN_ROUTE_AUDIT_NAME => '   ',
    ]);

    $this->artisan('blb:domain-routes', ['--audit' => true])->assertFailed();
});

it('declares a justified allowlist for the signed connector webhook', function (): void {
    expect(config('domain_routes.middleware_audit.allowlist'))->toBe([
        'people-connector.webhook' => 'Signed provider webhook authenticates via connection signature rather than session authz.',
    ]);

    expect(config('domain_routes.tenant_context.required_domains'))
        ->toBe(['People', 'PeopleConnector'])
        ->and(config('domain_routes.tenant_context.exclusions'))
        ->toHaveKey('people-connector.webhook');
});

it('treats a tenant exclusion reason as satisfying the tenant assertion check only', function (): void {
    $web = writeDomainRouteAuditFixture(<<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('zz-route-audit/probe', fn () => 'ok')
    ->name('zz-route-audit.probe');
PHP);

    config()->set('domain_routes.tenant_context.required_domains', []);
    config()->set('domain_routes.tenant_context.exclusions', [
        DOMAIN_ROUTE_AUDIT_NAME => 'External boundary resolves tenant.',
    ]);
    config()->set('domain_routes.middleware_audit.allowlist', []);
    app(RouteDiscoveryService::class)->registerRoutes(['web' => [$web]]);
    config()->set('domain_routes.tenant_context.required_domains', ['ZzRouteAudit']);

    $this->artisan('blb:domain-routes', ['--audit' => true, '--json' => true])
        ->expectsOutput(json_encode([
            [
                'domain' => 'ZzRouteAudit',
                'module' => 'Fixture',
                'uri' => 'zz-route-audit/probe',
                'name' => DOMAIN_ROUTE_AUDIT_NAME,
                'methods' => ['GET', 'HEAD'],
                'middleware' => ['web'],
                'missing' => ['authorization'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
        ->assertFailed();
});

it('records tenant middleware provenance on audited required-domain routes', function (): void {
    $web = writeDomainRouteAuditFixture(<<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::middleware('authz:zz.route.audit.view')
    ->get('zz-route-audit/probe', fn () => 'ok')
    ->name('zz-route-audit.probe');
PHP);

    config()->set('domain_routes.tenant_context.required_domains', ['ZzRouteAudit']);
    config()->set('domain_routes.middleware_audit.allowlist', []);
    app(RouteDiscoveryService::class)->registerRoutes(['web' => [$web]]);

    $row = collect(app(DomainRouteInventory::class)->all())
        ->firstWhere('name', DOMAIN_ROUTE_AUDIT_NAME);

    expect($row)->not->toBeNull()
        ->and($row['middleware'])->toContain('web', RequireTenantContext::class, 'authz:zz.route.audit.view');
});
