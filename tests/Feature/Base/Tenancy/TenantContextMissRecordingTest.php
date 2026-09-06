<?php

use App\Base\Routing\RouteDiscoveryService;
use App\Base\Tenancy\Services\TenantContextMissRecorder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

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
