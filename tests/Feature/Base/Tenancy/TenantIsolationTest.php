<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Foundation\Services\FrameworkPrimitivesProvisioner;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Exceptions\LicenseeTenantDeletionException;
use App\Base\Tenancy\Models\Tenant;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
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

it('seeds the licensee tenant during migration', function (): void {
    $tenant = Tenant::query()->find(Tenant::LICENSEE_TENANT_ID);

    expect($tenant)->not->toBeNull();
    expect($tenant->isLicensee())->toBeTrue();
    expect($tenant->status)->toBe('active');
});

it('assigns new companies to the licensee tenant by default', function (): void {
    $company = Company::factory()->create();

    // tenant_id arrives via the database default, so re-read the persisted row.
    expect($company->refresh()->tenant_id)->toBe(Tenant::LICENSEE_TENANT_ID);
    expect($company->tenant->isLicensee())->toBeTrue();
});

it('provisions the licensee company into the licensee tenant', function (): void {
    Company::provisionLicensee('Acme Holdings');

    expect(Company::query()->find(Company::LICENSEE_ID)->tenant_id)
        ->toBe(Tenant::LICENSEE_TENANT_ID);
});

it('provisions and renames the licensee tenant idempotently', function (): void {
    $provisioner = new FrameworkPrimitivesProvisioner;

    $provisioner->provisionLicenseeTenant('Acme Holdings');
    expect(Tenant::query()->find(Tenant::LICENSEE_TENANT_ID)->name)->toBe('Acme Holdings');

    // Without an explicit name, the tenant name tracks the licensee company.
    Company::provisionLicensee('Belimbing Test Licensee');
    $provisioner->provisionLicenseeTenant(null);
    expect(Tenant::query()->find(Tenant::LICENSEE_TENANT_ID)->name)->toBe('Belimbing Test Licensee');
});

it('forbids deleting the licensee tenant', function (): void {
    Tenant::query()->find(Tenant::LICENSEE_TENANT_ID)->delete();
})->throws(LicenseeTenantDeletionException::class);

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
        new ResourceContext('users', 1, $company->id, tenantId: Tenant::LICENSEE_TENANT_ID),
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

it('resolves tenant context for authenticated web requests', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    Route::middleware('web')->get('/_test/tenant-context', fn () => response()->json([
        'tenant_id' => app(TenantContext::class)->currentTenantId(),
    ]));

    $this->actingAs($user)->getJson('/_test/tenant-context')
        ->assertOk()
        ->assertJson(['tenant_id' => Tenant::LICENSEE_TENANT_ID]);
});

it('resolves no tenant context for guests', function (): void {
    Route::middleware('web')->get('/_test/tenant-context-guest', fn () => response()->json([
        'tenant_id' => app(TenantContext::class)->currentTenantId(),
    ]));

    $this->getJson('/_test/tenant-context-guest')
        ->assertOk()
        ->assertJson(['tenant_id' => null]);
});
