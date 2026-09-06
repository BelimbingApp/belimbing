<?php

use App\Base\Audit\DTO\RequestContext;
use App\Base\Audit\Models\AuditMutation;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\FeatureFlags\Livewire\Index;
use App\Base\FeatureFlags\Models\FeatureFlagOverride;
use App\Base\FeatureFlags\Services\FeatureFlagDefinition;
use App\Base\FeatureFlags\Services\FeatureFlagOverrideHistory;
use App\Base\FeatureFlags\Services\FeatureFlagRegistry;
use App\Base\FeatureFlags\Services\FeatureFlags;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setupAuthzRoles();
});

function featureFlagsUiReplace(FeatureFlagDefinition ...$definitions): FeatureFlags
{
    app(FeatureFlagRegistry::class)->replace($definitions);

    return app(FeatureFlags::class);
}

function featureFlagsUiBindActor(User $user, ?int $tenantId = null): void
{
    app()->forgetInstance(RequestContext::class);
    app()->instance(RequestContext::class, new RequestContext(
        traceId: 'feature-flags-ui-'.bin2hex(random_bytes(4)),
        actorType: PrincipalType::USER->value,
        actorId: (int) $user->id,
        companyId: (int) $user->company_id,
        tenantId: $tenantId ?? app(TenantContext::class)->currentTenantId(),
    ));
}

function featureFlagsUiFlushAudit(): void
{
    $buffer = app(AuditBuffer::class);
    $method = (new ReflectionClass($buffer))->getMethod('flush');
    $method->invoke($buffer);
}

function featureFlagsUiViewer(): User
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

it('denies the feature-flags page to a non-operator', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    app(TenantContext::class)->set((int) $company->tenant_id);

    $this->actingAs($user)
        ->get(route('admin.system.feature-flags.index'))
        ->assertForbidden();
});

it('keeps toggles from one tenant from leaking into another', function (): void {
    $alpha = createTenant(['name' => 'Alpha flags UI']);
    $beta = createTenant(['name' => 'Beta flags UI']);
    $tenants = app(TenantContext::class);

    featureFlagsUiReplace(
        new FeatureFlagDefinition('demo.ui', default: false, module: 'base/demo', description: 'UI demo'),
    );

    $admin = createAdminUser();
    $tenants->set((int) $alpha->id);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('toggle', 'demo.ui', true)
        ->assertHasNoErrors();

    expect(FeatureFlagOverride::query()->where('tenant_id', $alpha->id)->where('flag', 'demo.ui')->exists())->toBeTrue();

    $tenants->set((int) $beta->id);
    expect(app(FeatureFlags::class)->enabled('demo.ui'))->toBeFalse()
        ->and(FeatureFlagOverride::query()->where('tenant_id', $beta->id)->where('flag', 'demo.ui')->exists())->toBeFalse();
});

it('lets a manager toggle a declared flag and writes an audit mutation', function (): void {
    $admin = createAdminUser();
    featureFlagsUiReplace(
        new FeatureFlagDefinition('demo.audit', default: false, module: 'base/demo', description: 'Audit demo'),
    );

    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->assertSee('demo.audit')
        ->assertSee('base/demo')
        ->call('toggle', 'demo.audit', true)
        ->assertHasNoErrors();

    expect(app(FeatureFlags::class)->enabled('demo.audit'))->toBeTrue()
        ->and(FeatureFlagOverride::query()->where('flag', 'demo.audit')->value('enabled'))->toBeTrue();

    featureFlagsUiFlushAudit();

    $mutation = AuditMutation::query()
        ->where('subject_name', 'feature-flag')
        ->where('subject_id', 'demo.audit')
        ->where('event', 'created')
        ->first();

    expect($mutation)->not->toBeNull()
        ->and($mutation->tenant_id)->toBe(app(TenantContext::class)->requireTenantId());
});

it('refuses inventing an undeclared flag from the UI surface', function (): void {
    $admin = createAdminUser();
    featureFlagsUiReplace(
        new FeatureFlagDefinition('demo.declared', default: false, module: 'base/demo'),
    );

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('toggle', 'demo.missing', true)
        ->assertHasNoErrors();

    expect(FeatureFlagOverride::query()->where('flag', 'demo.missing')->exists())->toBeFalse();
});

it('shows view-only state without mutating for a viewer', function (): void {
    $viewer = featureFlagsUiViewer();
    featureFlagsUiReplace(
        new FeatureFlagDefinition('demo.readonly', default: false, module: 'base/demo'),
    );

    Livewire::actingAs($viewer)
        ->test(Index::class)
        ->assertViewHas('canManage', false)
        ->assertSee('View only')
        ->call('toggle', 'demo.readonly', true);

    expect(FeatureFlagOverride::query()->where('flag', 'demo.readonly')->exists())->toBeFalse();
});

it('clears an override so the declared default applies again', function (): void {
    $admin = createAdminUser();
    $flags = featureFlagsUiReplace(
        new FeatureFlagDefinition('demo.clear', default: false, module: 'base/demo'),
    );
    $flags->override('demo.clear', true);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('clearOverride', 'demo.clear')
        ->assertHasNoErrors();

    expect($flags->enabled('demo.clear'))->toBeFalse()
        ->and(FeatureFlagOverride::query()->where('flag', 'demo.clear')->exists())->toBeFalse();
});

it('shows override history for the current tenant and isolates other tenants', function (): void {
    $alpha = createTenant(['name' => 'Alpha history']);
    $beta = createTenant(['name' => 'Beta history']);
    $tenants = app(TenantContext::class);
    featureFlagsUiReplace(
        new FeatureFlagDefinition('demo.history', default: false, module: 'base/demo', description: 'History demo'),
    );

    $admin = createAdminUser();
    $tenants->set((int) $alpha->id);
    featureFlagsUiBindActor($admin, (int) $alpha->id);
    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('toggle', 'demo.history', true)
        ->assertHasNoErrors();
    featureFlagsUiFlushAudit();

    $tenants->set((int) $beta->id);
    featureFlagsUiBindActor($admin, (int) $beta->id);
    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('toggle', 'demo.history', true)
        ->assertHasNoErrors();
    featureFlagsUiFlushAudit();

    $tenants->set((int) $alpha->id);
    $history = app(FeatureFlagOverrideHistory::class)->forCurrentTenant();
    expect($history)->toHaveKey('demo.history')
        ->and($history['demo.history'])->toHaveCount(1)
        ->and($history['demo.history'][0]['actor'])->toBe($admin->name)
        ->and($history['demo.history'][0]['tenant_id'])->toBe((int) $alpha->id)
        ->and($history['demo.history'][0]['old_enabled'])->toBeNull()
        ->and($history['demo.history'][0]['new_enabled'])->toBeTrue();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertSee('Overrides history')
        ->assertSee($admin->name)
        ->assertSeeHtml('>'.e((string) $alpha->id).'<');

    // Isolation proof: drop the tenant_id filter and the beta mutation leaks in.
    $leaky = AuditMutation::query()
        ->where('subject_name', 'feature-flag')
        ->where('subject_id', 'demo.history')
        ->where('source', '!=', 'expanded')
        ->pluck('tenant_id')
        ->map(fn ($id) => (int) $id)
        ->unique()
        ->sort()
        ->values()
        ->all();
    expect($leaky)->toBe([(int) $alpha->id, (int) $beta->id]);
});

it('lets a viewer read override history without toggle controls', function (): void {
    $admin = createAdminUser();
    featureFlagsUiReplace(
        new FeatureFlagDefinition('demo.view-history', default: false, module: 'base/demo'),
    );
    featureFlagsUiBindActor($admin);
    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('toggle', 'demo.view-history', true)
        ->assertHasNoErrors();
    featureFlagsUiFlushAudit();

    $viewer = User::factory()->create(['company_id' => $admin->company_id]);
    PrincipalCapability::query()->create([
        'company_id' => $admin->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $viewer->id,
        'capability_key' => 'admin.system.feature-flags.view',
        'is_allowed' => true,
    ]);

    Livewire::actingAs($viewer)
        ->test(Index::class)
        ->assertViewHas('canManage', false)
        ->assertSee('Overrides history')
        ->assertSee($admin->name)
        ->assertSee('View only')
        ->assertDontSeeHtml('wire:click="toggle')
        ->assertDontSeeHtml('wire:click="clearOverride');
});
