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

it('provisions exactly one platform-operator tenant without semantic id 1', function (): void {
    $tenant = Tenant::requirePlatformOperator();

    expect($tenant->id)->not->toBe(1)
        ->and($tenant->isPlatformOperator())->toBeTrue()
        ->and($tenant->status)->toBe('active')
        ->and(Tenant::query()->where('is_platform_operator', true)->count())->toBe(1);
});

it('marks retained tenant id 1 when upgrading a legacy installation', function (): void {
    $currentOperator = Tenant::requirePlatformOperator();
    DB::table('tenants')->where('id', $currentOperator->id)->update(['is_platform_operator' => false]);
    $migration = require app_path('Base/Tenancy/Database/Migrations/0100_01_25_000001_mark_platform_operator_tenant.php');
    $migration->down();
    DB::table('tenants')->insert([
        'id' => 1,
        'name' => 'Retained Legacy Operator',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(Tenant::requirePlatformOperator()->id)->toBe(1)
        ->and(Tenant::query()->findOrFail($currentOperator->id)->isPlatformOperator())->toBeFalse();
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
