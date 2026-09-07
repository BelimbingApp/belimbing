<?php

use App\Base\Audit\DTO\RequestContext;
use App\Base\Audit\Models\AuditMutation;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\FeatureFlags\Exceptions\FeatureFlagStillDeclaredException;
use App\Base\FeatureFlags\Exceptions\UndeclaredFeatureFlagException;
use App\Base\FeatureFlags\Livewire\Index;
use App\Base\FeatureFlags\Models\FeatureFlagOverride;
use App\Base\FeatureFlags\Services\FeatureFlagDeclarationInventory;
use App\Base\FeatureFlags\Services\FeatureFlagDefinition;
use App\Base\FeatureFlags\Services\FeatureFlagRegistry;
use App\Base\FeatureFlags\Services\FeatureFlags;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setupAuthzRoles();
});

function orphanFlagsBindActor(User $user, ?int $tenantId = null): void
{
    app()->forgetInstance(RequestContext::class);
    app()->instance(RequestContext::class, new RequestContext(
        traceId: 'orphan-flags-'.bin2hex(random_bytes(4)),
        actorType: PrincipalType::USER->value,
        actorId: (int) $user->id,
        companyId: (int) $user->company_id,
        tenantId: $tenantId ?? app(TenantContext::class)->currentTenantId(),
    ));
}

function orphanFlagsFlushAudit(): void
{
    $buffer = app(AuditBuffer::class);
    $method = (new ReflectionClass($buffer))->getMethod('flush');
    $method->invoke($buffer);
}

function orphanFlagsViewer(): User
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    PrincipalCapability::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'capability_key' => 'admin.system.feature-flags.view',
        'is_allowed' => true,
    ]);

    app(TenantContext::class)->set((int) $company->tenant_id);

    return $user;
}

/**
 * @param  array<string, array{default: bool, description?: string}>  $flags
 */
function orphanFlagsWriteManifest(string $root, string $directory, string $module, array $flags): void
{
    $path = $root.'/app/Base/'.$directory;
    File::ensureDirectoryExists($path);
    File::put($path.'/composer.json', json_encode([
        'name' => 'blb/'.$directory,
        'extra' => [
            'blb' => [
                'module' => $module,
                'feature-flags' => $flags,
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

it('lists and renders an orphaned override for the ambient tenant', function (): void {
    $admin = createAdminUser();
    $tenantId = app(TenantContext::class)->requireTenantId();
    FeatureFlagOverride::query()->create([
        'tenant_id' => $tenantId,
        'flag' => 'demo.gone',
        'enabled' => true,
    ]);
    app(FeatureFlagRegistry::class)->replace([
        new FeatureFlagDefinition('demo.declared', default: false, module: 'base/demo'),
    ]);

    $orphans = app(FeatureFlagDeclarationInventory::class)->orphanedOverridesForCurrentTenant();
    expect($orphans)->toHaveCount(1)
        ->and($orphans[0]['flag'])->toBe('demo.gone')
        ->and($orphans[0]['enabled'])->toBeTrue();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertSee('Orphaned overrides')
        ->assertSee('demo.gone')
        ->assertSee('Purge');
});

it('purges an orphan in one tenant without touching another', function (): void {
    $alpha = createTenant(['name' => 'Alpha orphan']);
    $beta = createTenant(['name' => 'Beta orphan']);
    $tenants = app(TenantContext::class);
    app(FeatureFlagRegistry::class)->replace([]);

    FeatureFlagOverride::query()->create(['tenant_id' => $alpha->id, 'flag' => 'demo.gone', 'enabled' => true]);
    FeatureFlagOverride::query()->create(['tenant_id' => $beta->id, 'flag' => 'demo.gone', 'enabled' => false]);

    $tenants->set((int) $alpha->id);
    app(FeatureFlags::class)->purgeOrphanedOverride('demo.gone');

    expect(FeatureFlagOverride::query()->where('tenant_id', $alpha->id)->where('flag', 'demo.gone')->exists())->toBeFalse()
        ->and(FeatureFlagOverride::query()->where('tenant_id', $beta->id)->where('flag', 'demo.gone')->exists())->toBeTrue();
});

it('refuses purging a declared flag and still refuses clearing an orphan via clearOverride', function (): void {
    createAdminUser();
    $tenantId = app(TenantContext::class)->requireTenantId();
    app(FeatureFlagRegistry::class)->replace([
        new FeatureFlagDefinition('demo.declared', default: false, module: 'base/demo'),
    ]);
    FeatureFlagOverride::query()->create([
        'tenant_id' => $tenantId,
        'flag' => 'demo.gone',
        'enabled' => true,
    ]);

    expect(fn () => app(FeatureFlags::class)->purgeOrphanedOverride('demo.declared'))
        ->toThrow(FeatureFlagStillDeclaredException::class);
    expect(fn () => app(FeatureFlags::class)->clearOverride('demo.gone'))
        ->toThrow(UndeclaredFeatureFlagException::class);
});

it('shows orphans to viewers without a Purge control and refuses the Livewire purge action', function (): void {
    $viewer = orphanFlagsViewer();
    $tenantId = app(TenantContext::class)->requireTenantId();
    FeatureFlagOverride::query()->create([
        'tenant_id' => $tenantId,
        'flag' => 'demo.gone',
        'enabled' => true,
    ]);
    app(FeatureFlagRegistry::class)->replace([]);

    Livewire::actingAs($viewer)
        ->test(Index::class)
        ->assertViewHas('canManage', false)
        ->assertSee('demo.gone')
        ->assertSee('View only')
        ->assertDontSeeHtml('wire:click="purge(')
        ->call('purge', 'demo.gone');

    expect(FeatureFlagOverride::query()->where('flag', 'demo.gone')->exists())->toBeTrue();
});

it('emits orphans in blb:feature-flags --json with module null and orphaned true', function (): void {
    createAdminUser();
    $tenantId = app(TenantContext::class)->requireTenantId();
    app(FeatureFlagRegistry::class)->replace([
        new FeatureFlagDefinition('demo.declared', default: true, module: 'base/demo', description: 'Still here'),
    ]);
    FeatureFlagOverride::query()->create([
        'tenant_id' => $tenantId,
        'flag' => 'demo.gone',
        'enabled' => false,
    ]);

    Artisan::call('blb:feature-flags', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $byFlag = collect($payload)->keyBy('flag');
    expect($byFlag['demo.declared']['orphaned'])->toBeFalse()
        ->and($byFlag['demo.declared']['module'])->toBe('base/demo')
        ->and($byFlag['demo.gone']['orphaned'])->toBeTrue()
        ->and($byFlag['demo.gone']['module'])->toBeNull();
});

it('records a mutation audit row for purge in the ambient tenant only', function (): void {
    $alpha = createTenant(['name' => 'Alpha purge audit']);
    $beta = createTenant(['name' => 'Beta purge audit']);
    $tenants = app(TenantContext::class);
    app(FeatureFlagRegistry::class)->replace([]);

    FeatureFlagOverride::query()->create(['tenant_id' => $alpha->id, 'flag' => 'demo.gone', 'enabled' => true]);
    FeatureFlagOverride::query()->create(['tenant_id' => $beta->id, 'flag' => 'demo.gone', 'enabled' => true]);

    $admin = createAdminUser();
    $tenants->set((int) $alpha->id);
    orphanFlagsBindActor($admin, (int) $alpha->id);

    app(FeatureFlags::class)->purgeOrphanedOverride('demo.gone');
    orphanFlagsFlushAudit();

    expect(AuditMutation::query()
        ->where('tenant_id', $alpha->id)
        ->where('subject_name', 'feature-flag')
        ->where('subject_id', 'demo.gone')
        ->where('event', 'deleted')
        ->count())->toBe(1)
        ->and(AuditMutation::query()
            ->where('tenant_id', $beta->id)
            ->where('subject_name', 'feature-flag')
            ->where('subject_id', 'demo.gone')
            ->where('event', 'deleted')
            ->count())->toBe(0);
});
