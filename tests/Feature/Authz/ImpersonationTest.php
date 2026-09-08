<?php

use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Services\AuditActorResolver;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\ImpersonationRefusedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Authz\Services\ImpersonationManager;
use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;

beforeEach(function (): void {
    setupAuthzRoles();
});

it('allows admin to start impersonation', function (): void {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id]);
    $target = User::factory()->create(['company_id' => $company->id]);

    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $admin->id,
        'role_id' => $role->id,
    ]);

    $response = $this->actingAs($admin)->post(route('admin.impersonate.start', $target));

    $response->assertRedirect(route('dashboard'));
    expect(session('impersonation.original_user_id'))->toBe($admin->id);
    expect(auth()->id())->toBe($target->id);
});

it('denies impersonation without capability', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $target = User::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->post(route('admin.impersonate.start', $target));

    $response->assertStatus(403);
    expect(session('impersonation.original_user_id'))->toBeNull();
});

it('fails closed when an administrator attempts to impersonate another tenant', function (): void {
    $admin = createAdminUser();
    [, $foreignCompany] = createTenantWithCompany(['name' => 'Foreign Tenant']);
    $target = User::factory()->create(['company_id' => $foreignCompany->id]);
    $this->withoutVite();

    $this->actingAs($admin)
        ->post(route('admin.impersonate.start', $target))
        ->assertNotFound();

    expect(auth()->id())->toBe($admin->id)
        ->and(session('impersonation.original_user_id'))->toBeNull();
});

it('stops impersonation and restores original user', function (): void {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id]);
    $target = User::factory()->create(['company_id' => $company->id]);

    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $admin->id,
        'role_id' => $role->id,
    ]);

    $this->actingAs($admin)->post(route('admin.impersonate.start', $target));

    $response = $this->post(route('admin.impersonate.stop'));

    $response->assertRedirect(route('dashboard'));
    expect(session('impersonation.original_user_id'))->toBeNull();
    expect(auth()->id())->toBe($admin->id);
});

it('prevents impersonating yourself', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->set((int) $company->tenant_id);
    $admin = User::factory()->create(['company_id' => $company->id]);

    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $admin->id,
        'role_id' => $role->id,
    ]);

    $this->actingAs($admin);

    $manager = app(ImpersonationManager::class);

    expect(fn () => $manager->start($admin, $admin))
        ->toThrow(InvalidArgumentException::class, 'Cannot impersonate yourself.');
});

it('reports impersonation state correctly', function (): void {
    $manager = app(ImpersonationManager::class);

    expect($manager->isImpersonating())->toBeFalse();
    expect($manager->getImpersonatorId())->toBeNull();
    expect($manager->getImpersonatorName())->toBeNull();

    $company = Company::factory()->create();
    app(TenantContext::class)->set((int) $company->tenant_id);
    $admin = User::factory()->create(['company_id' => $company->id, 'name' => 'Admin User']);
    $target = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($admin);

    $manager->start($admin, $target);

    expect($manager->isImpersonating())->toBeTrue();
    expect($manager->getImpersonatorId())->toBe($admin->id);
    expect($manager->getImpersonatorName())->toBe('Admin User');
});

it('denies impersonation for unauthenticated users', function (): void {
    $target = User::factory()->create();

    $response = $this->post(route('admin.impersonate.start', $target));

    $response->assertRedirect(route('login'));
});

function impersonationFlushAuditBuffer(): void
{
    $buffer = app(AuditBuffer::class);
    $method = (new ReflectionClass($buffer))->getMethod('flush');
    $method->invoke($buffer);
}

it('refuses nested impersonation without rewriting the original admin', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->set((int) $company->tenant_id);
    $admin = User::factory()->create(['company_id' => $company->id]);
    $first = User::factory()->create(['company_id' => $company->id]);
    $second = User::factory()->create(['company_id' => $company->id]);

    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $admin->id,
        'role_id' => $role->id,
    ]);

    $this->actingAs($admin)->post(route('admin.impersonate.start', $first))->assertRedirect(route('dashboard'));

    $this->post(route('admin.impersonate.start', $second))->assertForbidden();

    expect(auth()->id())->toBe($first->id)
        ->and(session('impersonation.original_user_id'))->toBe($admin->id);
});

it('refuses nested impersonation when the impersonated user also holds the capability', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->set((int) $company->tenant_id);
    $admin = User::factory()->create(['company_id' => $company->id]);
    $firstAdmin = User::factory()->create(['company_id' => $company->id]);
    $second = User::factory()->create(['company_id' => $company->id]);

    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();
    foreach ([$admin, $firstAdmin] as $holder) {
        PrincipalRole::query()->create([
            'company_id' => $company->id,
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $holder->id,
            'role_id' => $role->id,
        ]);
    }

    $this->actingAs($admin)->post(route('admin.impersonate.start', $firstAdmin))->assertRedirect(route('dashboard'));
    $this->post(route('admin.impersonate.start', $second))->assertForbidden();

    expect(auth()->id())->toBe($firstAdmin->id)
        ->and(session('impersonation.original_user_id'))->toBe($admin->id);
});

it('refuses cross-tenant starts inside the manager', function (): void {
    $admin = createAdminUser();
    [, $foreignCompany] = createTenantWithCompany(['name' => 'Foreign Impersonation Tenant']);
    $target = User::factory()->create(['company_id' => $foreignCompany->id]);

    $this->actingAs($admin);

    expect(fn () => app(ImpersonationManager::class)->start($admin, $target))
        ->toThrow(ImpersonationRefusedException::class);

    expect(auth()->id())->toBe($admin->id)
        ->and(session('impersonation.original_user_id'))->toBeNull();
});

it('records retained impersonation.started and impersonation.stopped for the real admin', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->set((int) $company->tenant_id);
    $admin = User::factory()->create(['company_id' => $company->id]);
    $target = User::factory()->create(['company_id' => $company->id]);

    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $admin->id,
        'role_id' => $role->id,
    ]);

    $this->actingAs($admin);
    app(ImpersonationManager::class)->start($admin, $target);
    impersonationFlushAuditBuffer();

    $started = AuditAction::query()->where('event', 'impersonation.started')->first();
    expect($started)->not->toBeNull()
        ->and($started->actor_id)->toBe($admin->id)
        ->and($started->tenant_id)->toBe((int) $company->tenant_id)
        ->and($started->is_retained)->toBeTrue()
        ->and(data_get($started->payload, 'context.target_id'))->toBe($target->id);

    $this->post(route('admin.impersonate.stop'))->assertRedirect(route('dashboard'));
    impersonationFlushAuditBuffer();

    $stopped = AuditAction::query()->where('event', 'impersonation.stopped')->first();
    expect($stopped)->not->toBeNull()
        ->and($stopped->actor_id)->toBe($admin->id)
        ->and($stopped->is_retained)->toBeTrue();
});

it('stamps impersonator_id on actor context while impersonating', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->set((int) $company->tenant_id);
    $admin = User::factory()->create(['company_id' => $company->id, 'name' => 'Impersonator Admin']);
    $target = User::factory()->create(['company_id' => $company->id, 'name' => 'Impersonated User']);

    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $admin->id,
        'role_id' => $role->id,
    ]);

    $this->actingAs($admin);
    app(ImpersonationManager::class)->start($admin, $target);

    expect(app(AuditActorResolver::class)->currentActor()['impersonator_id'] ?? null)->toBe($admin->id);

    app(SemanticActionRecorder::class)->record(
        event: 'test.under.impersonation',
        summary: 'Probe while impersonating',
        source: 'Test',
        subject: ['name' => 'user', 'id' => $target->id],
        retain: false,
    );
    impersonationFlushAuditBuffer();

    $probe = AuditAction::query()->where('event', 'test.under.impersonation')->first();
    expect($probe)->not->toBeNull()
        ->and($probe->actor_id)->toBe($target->id)
        ->and(data_get($probe->payload, 'impersonator_id'))->toBe($admin->id);
});

it('hides another tenant impersonation.started row from a tenant-B auditor', function (): void {
    $companyA = Company::factory()->create();
    app(TenantContext::class)->set((int) $companyA->tenant_id);
    $adminA = User::factory()->create(['company_id' => $companyA->id]);
    $targetA = User::factory()->create(['company_id' => $companyA->id]);

    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();
    PrincipalRole::query()->create([
        'company_id' => $companyA->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $adminA->id,
        'role_id' => $role->id,
    ]);

    $this->actingAs($adminA);
    app(ImpersonationManager::class)->start($adminA, $targetA);
    impersonationFlushAuditBuffer();

    [$tenantB, $companyB] = createTenantWithCompany(['name' => 'Auditor Tenant B']);
    app(TenantContext::class)->set((int) $tenantB->id);
    $auditor = User::factory()->create(['company_id' => $companyB->id]);
    $owner = Role::query()->where('code', 'tenant_owner')->whereNull('company_id')->firstOrFail();
    PrincipalRole::query()->create([
        'company_id' => $companyB->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $auditor->id,
        'role_id' => $owner->id,
    ]);

    $seen = AuditAction::query()
        ->where('event', 'impersonation.started')
        ->where('tenant_id', (int) $tenantB->id)
        ->count();

    expect($seen)->toBe(0)
        ->and(AuditAction::query()->where('event', 'impersonation.started')->where('tenant_id', (int) $companyA->tenant_id)->count())->toBe(1);
});
