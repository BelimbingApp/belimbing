<?php

use App\Base\Authz\Capability\CapabilityInventory;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\FeatureFlags\Models\FeatureFlagOverride;
use App\Base\FeatureFlags\Services\FeatureFlagDeclarationInventory;
use App\Base\FeatureFlags\Services\FeatureFlagRegistry;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Menu\Services\MenuConditionRegistry;
use App\Base\Routing\RouteDiscoveryService;
use App\Base\Routing\Services\TenantAuditPageData;
use App\Base\System\Livewire\Overview\Index;
use App\Base\System\Services\SystemOverviewPageData;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const OVERVIEW_AUDIT_FIXTURE = 'app/Domains/ZzOverviewProbe/Fixture';

beforeEach(function (): void {
    setupAuthzRoles();
});

afterEach(function (): void {
    File::deleteDirectory(base_path('app/Domains/ZzOverviewProbe'));
});

/**
 * @param  list<string>  $capabilities
 */
function systemOverviewActor(array $capabilities): User
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    foreach ($capabilities as $capability) {
        PrincipalCapability::query()->create([
            'company_id' => $company->id,
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $user->id,
            'capability_key' => $capability,
            'is_allowed' => true,
        ]);
    }

    app(TenantContext::class)->set((int) $company->tenant_id);

    return $user;
}

function registerOverviewAuditAuthzGapFixture(): void
{
    $root = base_path(OVERVIEW_AUDIT_FIXTURE.'/Routes');
    File::ensureDirectoryExists($root);
    $web = $root.'/web.php';
    File::put($web, <<<'PHP'
<?php

use App\Base\Tenancy\Middleware\RequireTenantContext;
use Illuminate\Support\Facades\Route;

Route::middleware(RequireTenantContext::class)
    ->get('zz-overview-probe', fn () => 'ok')
    ->name('zz-overview-probe');
PHP);

    config()->set('domain_routes.tenant_context.required_domains', []);
    config()->set('domain_routes.middleware_audit.allowlist', []);
    app(RouteDiscoveryService::class)->registerRoutes(['web' => [$web]]);
    config()->set('domain_routes.tenant_context.required_domains', ['ZzOverviewProbe']);
}

it('denies the overview page when the actor has none of the operator view capabilities', function (): void {
    $user = systemOverviewActor([]);

    $this->actingAs($user)
        ->get(route('admin.system.overview.index'))
        ->assertForbidden();
});

it('shows the audit card and not the flags cards when the actor has only audit.view', function (): void {
    $user = systemOverviewActor([SystemOverviewPageData::AUDIT_VIEW]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSeeHtml('data-overview-card="tenant-audit"')
        ->assertDontSeeHtml('data-overview-card="feature-flags"')
        ->assertDontSeeHtml('data-overview-card="declared-flags"')
        ->assertDontSeeHtml('data-overview-card="capabilities"');
});

it('hides the flags card behind the per-card capability check (mutant: drop the check and the card appears)', function (): void {
    $user = systemOverviewActor([SystemOverviewPageData::AUDIT_VIEW]);
    $pageData = app(SystemOverviewPageData::class);
    $keys = collect($pageData->cardsFor($user))->pluck('key')->all();

    expect($keys)->toContain('tenant-audit')
        ->and($keys)->not->toContain('feature-flags')
        ->and($keys)->not->toContain('declared-flags');
});

it('hides the audit and capabilities cards when the actor has only feature-flags.view', function (): void {
    $user = systemOverviewActor([SystemOverviewPageData::FEATURE_FLAGS_VIEW]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSeeHtml('data-overview-card="feature-flags"')
        ->assertDontSeeHtml('data-overview-card="tenant-audit"')
        ->assertDontSeeHtml('data-overview-card="capabilities"');
});

it('counts overridden flags and declaration collisions through the declaration inventory for the fixture tenant', function (): void {
    $user = systemOverviewActor([SystemOverviewPageData::FEATURE_FLAGS_VIEW]);
    $tenantId = app(TenantContext::class)->requireTenantId();
    $root = storage_path('framework/testing/system-overview-flags-'.bin2hex(random_bytes(4)));

    // Asymmetric: two non-conflict flags + one collision so conflict→!conflict
    // cannot keep declared-flags count at 1.
    File::ensureDirectoryExists($root.'/app/Base/One');
    File::put($root.'/app/Base/One/composer.json', json_encode([
        'name' => 'base/one',
        'extra' => ['blb' => [
            'module' => 'base/one',
            'version' => '1.0.0',
            'feature-flags' => [
                'overview.solo' => ['default' => false, 'description' => 'Solo'],
                'overview.solo-b' => ['default' => false, 'description' => 'Solo B'],
                'overview.collision' => ['default' => false, 'description' => 'Alpha'],
            ],
        ]],
    ], JSON_THROW_ON_ERROR));
    File::ensureDirectoryExists($root.'/app/Base/Two');
    File::put($root.'/app/Base/Two/composer.json', json_encode([
        'name' => 'base/two',
        'extra' => ['blb' => [
            'module' => 'base/two',
            'version' => '1.0.0',
            'feature-flags' => [
                'overview.collision' => ['default' => true, 'description' => 'Beta'],
            ],
        ]],
    ], JSON_THROW_ON_ERROR));

    try {
        app()->instance(FeatureFlagDeclarationInventory::class, new FeatureFlagDeclarationInventory(
            new FeatureFlagRegistry(new ModuleManifestReader([$root.'/app/Base'])),
            app(TenantContext::class),
        ));
        app()->forgetInstance(SystemOverviewPageData::class);

        FeatureFlagOverride::query()->create([
            'tenant_id' => $tenantId,
            'flag' => 'overview.solo',
            'enabled' => true,
        ]);

        $cards = collect(app(SystemOverviewPageData::class)->cardsFor($user))->keyBy('key');

        expect($cards->get('feature-flags')['count'])->toBe(1)
            ->and($cards->get('declared-flags')['count'])->toBe(1);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSeeHtml('data-overview-card="feature-flags"')
            ->assertSeeHtml('data-overview-card="declared-flags"');
    } finally {
        File::deleteDirectory($root);
    }
});

it('audit card count equals the tenant-audit route rows for a fixture with one authz gap', function (): void {
    $user = systemOverviewActor([SystemOverviewPageData::AUDIT_VIEW]);
    registerOverviewAuditAuthzGapFixture();

    $expected = count(app(TenantAuditPageData::class)->routeRows());
    expect($expected)->toBeGreaterThanOrEqual(1);

    $card = collect(app(SystemOverviewPageData::class)->cardsFor($user))->firstWhere('key', 'tenant-audit');
    expect($card['count'])->toBe($expected);
});

it('capabilities card count equals the conflicted rows of the capability inventory', function (): void {
    $user = systemOverviewActor([SystemOverviewPageData::CAPABILITIES_VIEW]);
    $rows = app(CapabilityInventory::class)->rows();
    $expected = count(array_filter($rows, fn ($r) => $r->conflicted));
    expect(count($rows))->toBeGreaterThan($expected);

    $card = collect(app(SystemOverviewPageData::class)->cardsFor($user))->firstWhere('key', 'capabilities');
    expect($card['count'])->toBe($expected);
});

it('menu condition admin.system.overview.any follows the operator view capabilities', function (): void {
    $registry = app(MenuConditionRegistry::class);

    expect($registry->allows('admin.system.overview.any', systemOverviewActor([])))->toBeFalse()
        ->and($registry->allows('admin.system.overview.any', systemOverviewActor([SystemOverviewPageData::AUDIT_VIEW])))->toBeTrue();
});
