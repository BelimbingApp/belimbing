<?php

use App\Base\Routing\Livewire\TenantAudit\Index;
use App\Base\Routing\RouteDiscoveryService;
use App\Base\Routing\Services\TenantAuditPageData;
use App\Base\Tenancy\Services\TenantContextMissRecorder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

const TENANT_MISS_FIXTURE = 'app/Domains/ZzTenantMiss/Fixture';
const TENANT_MISS_ROUTE = 'zz-tenant-miss.probe';
const TENANT_MISS_PROBE = 'zz_tenant_miss_route_reached';

function registerTenantMissDomainFixture(): void
{
    $routes = base_path(TENANT_MISS_FIXTURE.'/Routes');
    File::ensureDirectoryExists($routes);
    File::put($routes.'/web.php', <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('zz-tenant-miss/probe', function () {
    $GLOBALS['zz_tenant_miss_route_reached'] = true;

    return response()->json(['reached' => true]);
})->name('zz-tenant-miss.probe');
PHP);

    config()->set('domain_routes.tenant_context.required_domains', ['ZzTenantMiss']);
    config()->set('domain_routes.tenant_context.exclusions', []);
    app(RouteDiscoveryService::class)->registerRoutes(['web' => [$routes.'/web.php']]);
}

beforeEach(function (): void {
    app(TenantContextMissRecorder::class)->clear();
    Cache::forget(TenantContextMissRecorder::CACHE_KEY);
    Cache::forget(TenantContextMissRecorder::METRIC_KEY);
});

afterEach(function (): void {
    File::deleteDirectory(base_path('app/Domains/ZzTenantMiss'));
    unset($GLOBALS[TENANT_MISS_PROBE]);
});

it('records one miss with the route name and resolver when a tenant route has no context', function (): void {
    registerTenantMissDomainFixture();
    $GLOBALS[TENANT_MISS_PROBE] = false;

    $this->getJson('/zz-tenant-miss/probe')
        ->assertNotFound()
        ->assertJson(['reason_code' => 'tenant_context_missing']);

    expect($GLOBALS[TENANT_MISS_PROBE])->toBeFalse();

    $misses = app(TenantContextMissRecorder::class)->recent();
    expect($misses)->toHaveCount(1)
        ->and($misses[0]['route'])->toBe(TENANT_MISS_ROUTE)
        ->and($misses[0]['resolver'])->toBe('session')
        ->and($misses[0]['at'])->not->toBeEmpty();

    Artisan::call('blb:tenant:misses', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    expect($payload)->toHaveCount(1)
        ->and($payload[0]['route'])->toBe(TENANT_MISS_ROUTE)
        ->and($payload[0]['resolver'])->toBe('session');
});

it('keeps the tenant-context 404 body identical when recording is removed', function (): void {
    registerTenantMissDomainFixture();

    $withRecording = $this->getJson('/zz-tenant-miss/probe');
    $bodyWith = $withRecording->getContent();
    $withRecording->assertNotFound()->assertJson(['reason_code' => 'tenant_context_missing']);

    // Acceptance mutation: deleting record() must leave the 404 bytes unchanged.
    $this->app->instance(TenantContextMissRecorder::class, new class extends TenantContextMissRecorder
    {
        public function record(?string $routeName, string $resolver): void
        {
            // intentionally empty — proves recording is side-effect only
        }
    });

    $withoutRecording = $this->getJson('/zz-tenant-miss/probe');
    expect($withoutRecording->getContent())->toBe($bodyWith)
        ->and($withoutRecording->status())->toBe(404);
});

it('caps the miss ring buffer at fifty entries and supports the table CLI', function (): void {
    $recorder = app(TenantContextMissRecorder::class);

    for ($i = 0; $i < 55; $i++) {
        $recorder->record('route-'.$i, 'session');
    }

    $misses = $recorder->recent();
    expect($misses)->toHaveCount(50)
        ->and($misses[0]['route'])->toBe('route-5')
        ->and($misses[49]['route'])->toBe('route-54');

    Artisan::call('blb:tenant:misses');
    $output = Artisan::output();
    expect($output)->toContain('route-54')
        ->and($output)->toContain('session');
});

it('reports an empty miss list on the CLI and ignores corrupt cache payloads', function (): void {
    Artisan::call('blb:tenant:misses', ['--json' => true]);
    expect(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR))->toBe([]);

    Artisan::call('blb:tenant:misses');
    expect(Artisan::output())->toContain('No tenant-context misses recorded');

    Cache::put(TenantContextMissRecorder::CACHE_KEY, 'not-an-array', now()->addHour());
    expect(app(TenantContextMissRecorder::class)->recent())->toBe([]);

    Cache::put(TenantContextMissRecorder::CACHE_KEY, [
        ['resolver' => 'session', 'at' => now()->toIso8601String(), 'route' => 'ok.route'],
        ['resolver' => '', 'at' => now()->toIso8601String()],
        'skip-me',
    ], now()->addHour());

    expect(app(TenantContextMissRecorder::class)->recent())->toHaveCount(1)
        ->and(app(TenantContextMissRecorder::class)->recent()[0]['route'])->toBe('ok.route');
});

it('surfaces recorded misses on the tenant-audit page data feed', function (): void {
    app(TenantContextMissRecorder::class)->record('people-connector.webhook', 'session');

    $rows = app(TenantAuditPageData::class)->missRows();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['route'])->toBe('people-connector.webhook')
        ->and($rows[0]['resolver'])->toBe('session');
});

it('does not record a miss for an excluded tenant-required route', function (): void {
    $routes = base_path(TENANT_MISS_FIXTURE.'/Routes');
    File::ensureDirectoryExists($routes);
    File::put($routes.'/web.php', <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('zz-tenant-miss/excluded', fn () => response()->json(['reached' => true]))
    ->name('zz-tenant-miss.excluded');
PHP);

    config()->set('domain_routes.tenant_context.required_domains', ['ZzTenantMiss']);
    config()->set('domain_routes.tenant_context.exclusions', [
        'zz-tenant-miss.excluded' => 'Fixture exclusion for miss-recording proof.',
    ]);
    app(RouteDiscoveryService::class)->registerRoutes(['web' => [$routes.'/web.php']]);

    $this->getJson('/zz-tenant-miss/excluded')
        ->assertOk()
        ->assertExactJson(['reached' => true]);

    expect(app(TenantContextMissRecorder::class)->recent())->toBe([]);
});

it('records a null route name when the matched route is unnamed', function (): void {
    $routes = base_path(TENANT_MISS_FIXTURE.'/Routes');
    File::ensureDirectoryExists($routes);
    File::put($routes.'/web.php', <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('zz-tenant-miss/unnamed', fn () => response()->json(['reached' => true]));
PHP);

    config()->set('domain_routes.tenant_context.required_domains', ['ZzTenantMiss']);
    config()->set('domain_routes.tenant_context.exclusions', []);
    app(RouteDiscoveryService::class)->registerRoutes(['web' => [$routes.'/web.php']]);

    $this->getJson('/zz-tenant-miss/unnamed')
        ->assertNotFound()
        ->assertJson(['reason_code' => 'tenant_context_missing']);

    $misses = app(TenantContextMissRecorder::class)->recent();
    expect($misses)->toHaveCount(1)
        ->and($misses[0]['route'])->toBeNull()
        ->and($misses[0]['resolver'])->toBe('session');
});

it('renders recent misses on the tenant-audit Livewire page', function (): void {
    setupAuthzRoles();
    app(TenantContextMissRecorder::class)->record('zz-tenant-miss.probe', 'session');

    Livewire::actingAs(createAdminUser())
        ->test(Index::class)
        ->assertSee('zz-tenant-miss.probe')
        ->assertSee('session')
        ->assertSeeHtml('data-tenant-miss="1"');
});
