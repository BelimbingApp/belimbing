<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Authz\Services\AuthorizationEngine;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Exceptions\PlatformOperatorTenantDeletionException;
use App\Base\Tenancy\Models\Tenant;
use App\Core\Address\Models\Address;
use App\Core\Company\Models\Company;
use App\Core\Company\Services\FrameworkPrimitivesProvisioner;
use App\Core\Company\Services\PrimaryCompanyManager;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    setupAuthzRoles();
});

/**
 * Grant the actor the core_admin role (grant_all) within the given company.
 */
function grantCoreAdmin(int $userId, int $companyId): void
{
    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();

    PrincipalRole::query()->create([
        'company_id' => $companyId,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $userId,
        'role_id' => $role->id,
    ]);
}

/**
 * Demote the provisioned operator and reverse the operator-marking migration,
 * returning it ready to re-run against a replanted legacy ID-1 row.
 */
function reversedPlatformOperatorMigration(Tenant $currentOperator): object
{
    DB::table('tenants')->where('id', $currentOperator->id)->update(['is_platform_operator' => false]);
    $migration = require app_path('Base/Tenancy/Database/Migrations/0100_01_25_000001_mark_platform_operator_tenant.php');
    $migration->down();

    return $migration;
}

/**
 * Plant the deterministic ID-1 tenant row that legacy installations retained.
 */
function plantLegacyTenantOne(string $name): void
{
    DB::table('tenants')->insert([
        'id' => 1,
        'name' => $name,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Trim the migration ledger back to the tenants table migration, so it is the
 * most recently recorded row while the operator-marking migration runs.
 */
function trimLedgerToTenantsMigration(): object
{
    $tenantsLedgerRow = DB::table('migrations')
        ->where('migration', '0100_01_25_000000_create_tenants_table')
        ->first();
    DB::table('migrations')->where('id', '>', $tenantsLedgerRow->id)->delete();

    return $tenantsLedgerRow;
}

it('provisions exactly one platform-operator tenant without semantic id 1', function (): void {
    $tenant = Tenant::requirePlatformOperator();

    expect($tenant->id)->not->toBe(1)
        ->and($tenant->isPlatformOperator())->toBeTrue()
        ->and($tenant->status)->toBe('active')
        ->and(Tenant::query()->where('is_platform_operator', true)->count())->toBe(1);
});

it('marks retained tenant id 1 when upgrading a legacy installation', function (): void {
    $currentOperator = Tenant::requirePlatformOperator();
    $migration = reversedPlatformOperatorMigration($currentOperator);
    plantLegacyTenantOne('Retained Legacy Operator');

    $migration->up();

    expect(Tenant::requirePlatformOperator()->id)->toBe(1)
        ->and(Tenant::query()->findOrFail($currentOperator->id)->isPlatformOperator())->toBeFalse();
});

it('retains bootstrap tenant id 1 when one migrate run catches up across releases', function (): void {
    $currentOperator = Tenant::requirePlatformOperator();
    $migration = reversedPlatformOperatorMigration($currentOperator);
    plantLegacyTenantOne('Retained Legacy Operator');
    $primaryAssignments = DB::table('tenant_primary_companies')
        ->where('tenant_id', $currentOperator->id)
        ->get();
    DB::table('tenant_primary_companies')->where('tenant_id', $currentOperator->id)->delete();
    DB::table('companies')->where('tenant_id', $currentOperator->id)->update(['tenant_id' => 1]);
    DB::table('addresses')->where('tenant_id', $currentOperator->id)->update(['tenant_id' => 1]);
    foreach ($primaryAssignments as $assignment) {
        DB::table('tenant_primary_companies')->insert([
            ...(array) $assignment,
            'tenant_id' => 1,
        ]);
    }
    DB::table('tenants')->where('id', $currentOperator->id)->delete();

    // Catch-up signature: the tenants migration is the most recently recorded
    // row while a row from an earlier batch proves a previous migrate run, so
    // the sole ID-1 row is retained legacy data, not a bootstrap artifact.
    $tenantsLedgerRow = trimLedgerToTenantsMigration();
    DB::table('migrations')
        ->where('id', $tenantsLedgerRow->id)
        ->update(['batch' => $tenantsLedgerRow->batch + 1]);

    $migration->up();

    expect(DB::table('tenants')->where('id', 1)->exists())->toBeTrue()
        ->and(Tenant::requirePlatformOperator()->id)->toBe(1);
});

it('still removes the bootstrap artifact on a fresh replay', function (): void {
    $currentOperator = Tenant::requirePlatformOperator();
    $migration = reversedPlatformOperatorMigration($currentOperator);
    DB::table('tenant_primary_companies')->where('tenant_id', $currentOperator->id)->delete();
    DB::table('companies')->where('tenant_id', $currentOperator->id)->delete();
    DB::table('tenants')->where('id', $currentOperator->id)->delete();
    plantLegacyTenantOne('Bootstrap Artifact');

    // Fresh-replay signature: the tenants migration is the most recently
    // recorded row and the whole ledger belongs to one batch.
    trimLedgerToTenantsMigration();

    $migration->up();

    expect(DB::table('tenants')->where('id', 1)->exists())->toBeFalse();
});

it('requires factories to resolve an explicit tenant assignment', function (): void {
    $company = Company::factory()->create(['tenant_id' => platformOperatorTenant()->id]);

    expect($company->refresh()->tenant_id)->toBe(platformOperatorTenant()->id);
    expect($company->tenant->isPlatformOperator())->toBeTrue();
});

it('provisions the operator primary company into the explicitly marked tenant', function (): void {
    $company = provisionPlatformOperatorCompany('Acme Holdings');

    expect($company->tenant_id)->toBe(platformOperatorTenant()->id);
    expect(app(PrimaryCompanyManager::class)->platformOperatorCompany()->is($company))->toBeTrue();
});

it('provisions and renames the platform-operator tenant idempotently', function (): void {
    $provisioner = app(FrameworkPrimitivesProvisioner::class);

    expect($provisioner->provisionPlatformOperatorTenant('Acme Holdings'))->toBeFalse();
    expect(platformOperatorTenant()->fresh()->name)->toBe('Acme Holdings');

    $provisioner->provisionPlatformOperatorTenant(null);
    expect(platformOperatorTenant()->fresh()->name)->toBe('Acme Holdings');
});

it('forbids deleting the explicitly marked platform-operator tenant', function (): void {
    platformOperatorTenant()->delete();
})->throws(PlatformOperatorTenantDeletionException::class);

it('resolves the actor tenant from the user company', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Second Tenant', 'status' => 'active']);
    $company = Company::factory()->create(['tenant_id' => $tenant->id]);
    $user = User::factory()->create(['company_id' => $company->id]);

    $actor = Actor::forUser($user);

    expect($actor->tenantId)->toBe($tenant->id);
    expect($actor->companyId)->toBe($company->id);
});

it('denies cross-tenant resource access with the tenant scope reason', function (): void {
    $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'status' => 'active']);
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create(['tenant_id' => $tenantB->id]);
    $user = User::factory()->create(['company_id' => $companyA->id]);
    grantCoreAdmin($user->id, $companyA->id);

    $decision = app(AuthorizationService::class)->can(
        Actor::forUser($user),
        'admin.user.view',
        new ResourceContext('users', 1, $companyB->id, tenantId: $tenantB->id),
    );

    expect($decision->allowed)->toBeFalse();
    expect($decision->reasonCode)->toBe(AuthorizationReasonCode::DENIED_TENANT_SCOPE);
});

it('fails closed when the actor has no tenant and the resource does', function (): void {
    $decision = app(AuthorizationService::class)->can(
        new Actor(PrincipalType::USER, 42, 10),
        'admin.user.view',
        new ResourceContext('users', 1, 10, tenantId: 1),
    );

    expect($decision->allowed)->toBeFalse();
    expect($decision->reasonCode)->toBe(AuthorizationReasonCode::DENIED_TENANT_SCOPE);
});

it('abstains on tenant-less resources so company scope remains the guard', function (): void {
    $user = User::factory()->create(['company_id' => Company::factory()->create()->id]);
    grantCoreAdmin($user->id, $user->company_id);

    // Resource carries a foreign company and no tenant: tenant policy abstains,
    // company scope denies — the pre-tenancy protection level.
    $decision = app(AuthorizationService::class)->can(
        Actor::forUser($user),
        'admin.user.view',
        new ResourceContext('users', 1, 999),
    );

    expect($decision->allowed)->toBeFalse();
    expect($decision->reasonCode)->toBe(AuthorizationReasonCode::DENIED_COMPANY_SCOPE);
});

it('allows same-tenant access when the capability is granted', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    grantCoreAdmin($user->id, $company->id);

    $decision = app(AuthorizationService::class)->can(
        Actor::forUser($user),
        'admin.user.view',
        new ResourceContext('users', 1, $company->id, tenantId: platformOperatorTenant()->id),
    );

    expect($decision->allowed)->toBeTrue();
});

it('enriches company-owned resources with their tenant during filtering', function (): void {
    $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'status' => 'active']);
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create(['tenant_id' => $tenantB->id]);
    $user = User::factory()->create(['company_id' => $companyA->id]);
    grantCoreAdmin($user->id, $companyA->id);

    $own = ['type' => 'users', 'id' => 1, 'company_id' => $companyA->id];
    $foreign = ['type' => 'users', 'id' => 2, 'company_id' => $companyB->id];

    $allowed = app(AuthorizationService::class)->filterAllowed(
        Actor::forUser($user),
        'admin.user.view',
        [$own, $foreign],
    );

    expect($allowed->all())->toHaveCount(1);
    expect($allowed->first()['company_id'])->toBe($companyA->id);
});

it('enriches Eloquent models carrying company_id through the tenant directory', function (): void {
    $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'status' => 'active']);
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create(['tenant_id' => $tenantB->id]);
    $user = User::factory()->create(['company_id' => $companyA->id]);
    grantCoreAdmin($user->id, $companyA->id);

    // Real models, not hand-built contexts: the engine must read company_id
    // off the model and resolve its tenant through the TenantDirectory.
    $own = User::factory()->create(['company_id' => $companyA->id]);
    $foreign = User::factory()->create(['company_id' => $companyB->id]);

    $engine = app(AuthorizationEngine::class);

    expect($engine->resourceContext($own)->tenantId)->toBe(platformOperatorTenant()->id);
    expect($engine->resourceContext($foreign)->tenantId)->toBe($tenantB->id);

    $allowed = app(AuthorizationService::class)->filterAllowed(
        Actor::forUser($user),
        'admin.user.view',
        [$own, $foreign],
    );

    expect($allowed->all())->toHaveCount(1);
    expect($allowed->first()->id)->toBe($own->id);
});

it('resolves tenant context for authenticated web requests', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    Route::middleware('web')->get('/_test/tenant-context', fn () => response()->json([
        'tenant_id' => app(TenantContext::class)->currentTenantId(),
    ]));

    $this->actingAs($user)->getJson('/_test/tenant-context')
        ->assertOk()
        ->assertJson(['tenant_id' => platformOperatorTenant()->id]);
});

it('resolves no tenant context for guests', function (): void {
    Route::middleware('web')->get('/_test/tenant-context-guest', fn () => response()->json([
        'tenant_id' => app(TenantContext::class)->currentTenantId(),
    ]));

    $this->getJson('/_test/tenant-context-guest')
        ->assertOk()
        ->assertJson(['tenant_id' => null]);
});

it('resolves tenant-aware implicit bindings before substitution with no ambient context', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $address = Address::query()->create([
        'tenant_id' => $company->tenant_id,
        'label' => 'Same Tenant Address',
        'line1' => '1 Same Tenant Road',
        'locality' => 'Same Tenant City',
        'country_iso' => null,
        'verificationStatus' => 'unverified',
    ]);
    [, $foreignCompany] = createTenantWithCompany(['name' => 'Binding Foreign Tenant']);
    $foreignAddress = Address::query()->create([
        'tenant_id' => $foreignCompany->tenant_id,
        'label' => 'Foreign Tenant Address',
        'line1' => '1 Foreign Tenant Road',
        'locality' => 'Foreign Tenant City',
        'country_iso' => null,
        'verificationStatus' => 'unverified',
    ]);

    $this->withoutVite()->actingAs($user);

    // The request must establish this context itself; the factory helper set
    // it only to support fixture creation and is deliberately cleared here.
    app(TenantContext::class)->clear();
    $this->get(route('admin.companies.show', $company))->assertOk();

    app(TenantContext::class)->clear();
    $this->get(route('admin.addresses.show', $address))->assertOk();

    app(TenantContext::class)->clear();
    $this->get(route('admin.companies.show', $foreignCompany))->assertNotFound();

    app(TenantContext::class)->clear();
    $this->get(route('admin.addresses.show', $foreignAddress))->assertNotFound();
});

it('refreshes the request-scoped tenant context across sequential worker requests', function (): void {
    $companyA = Company::factory()->create();
    $userA = User::factory()->create(['company_id' => $companyA->id]);
    [$tenantB, $companyB] = createTenantWithCompany(['name' => 'Sequential Worker Tenant']);
    $userB = User::factory()->create(['company_id' => $companyB->id]);

    Route::middleware('web')->get('/_test/tenant-context-sequence', fn () => response()->json([
        'tenant_id' => app(TenantContext::class)->currentTenantId(),
    ]));

    // Reuse this application instance as a long-lived worker would: a stale
    // value must be replaced for the next request, including a guest request.
    app(TenantContext::class)->set((int) $tenantB->id);

    $this->actingAs($userA)->getJson('/_test/tenant-context-sequence')
        ->assertOk()
        ->assertJson(['tenant_id' => $companyA->tenant_id]);

    $this->actingAs($userB)->getJson('/_test/tenant-context-sequence')
        ->assertOk()
        ->assertJson(['tenant_id' => $tenantB->id]);

    auth()->logout();

    $this->getJson('/_test/tenant-context-sequence')
        ->assertOk()
        ->assertJson(['tenant_id' => null]);
});
