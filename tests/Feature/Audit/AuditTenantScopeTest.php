<?php

use App\Base\Audit\Livewire\AuditLog\Actions;
use App\Base\Audit\Livewire\AuditLog\Mutations;
use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Services\AuditSourceHistory;
use App\Base\Audit\Services\AuditTraceTimeline;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function auditTenantScopeInsertAction(array $overrides = []): int
{
    return (int) DB::table('base_audit_actions')->insertGetId(array_replace([
        'company_id' => null,
        'tenant_id' => app(TenantContext::class)->requireTenantId(),
        'actor_type' => PrincipalType::USER->value,
        'actor_id' => 1,
        'actor_role' => 'core_admin',
        'ip_address' => '127.0.0.1',
        'url' => 'https://example.test/admin/audit',
        'user_agent' => 'Pest',
        'event' => 'http.request',
        'payload' => json_encode(['method' => 'GET', 'status' => 200]),
        'trace_id' => 'TENANTSCOPEACT',
        'is_retained' => false,
        'occurred_at' => now()->toDateTimeString(),
    ], $overrides));
}

function auditTenantScopeInsertMutation(array $overrides = []): int
{
    return (int) DB::table('base_audit_mutations')->insertGetId(array_replace([
        'company_id' => null,
        'tenant_id' => app(TenantContext::class)->requireTenantId(),
        'actor_type' => PrincipalType::USER->value,
        'actor_id' => 1,
        'actor_role' => 'core_admin',
        'event' => 'updated',
        'auditable_type' => User::class,
        'auditable_id' => 1,
        'old_values' => json_encode(['name' => 'before']),
        'new_values' => json_encode(['name' => 'after']),
        'source' => 'direct',
        'subject_name' => 'user',
        'subject_id' => '1',
        'subject_identifier' => null,
        'trace_id' => 'TENANTSCOPEMUT',
        'occurred_at' => now()->toDateTimeString(),
    ], $overrides));
}

/** @return array{0: User, 1: int, 2: int} viewer in tenant B, foreign tenant A id, foreign user id */
function auditTenantScopeForeignFixture(): array
{
    [$tenantA, $companyA] = createTenantWithCompany(['name' => 'Audit Scope Tenant A']);
    $userA = User::factory()->create(['company_id' => $companyA->id, 'name' => 'Tenant A Subject']);

    [$tenantB, $companyB] = createTenantWithCompany(['name' => 'Audit Scope Tenant B']);
    app(TenantContext::class)->set((int) $tenantB->id);

    $viewer = User::factory()->create(['company_id' => $companyB->id, 'name' => 'Tenant B Auditor']);
    $role = Role::query()->where('code', 'tenant_owner')->whereNull('company_id')->firstOrFail();
    PrincipalRole::query()->create([
        'company_id' => $companyB->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $viewer->id,
        'role_id' => $role->id,
    ]);

    return [$viewer, (int) $tenantA->id, (int) $userA->id];
}

it('hides foreign-tenant mutations from the mutations page', function (): void {
    [$viewer, $tenantAId] = auditTenantScopeForeignFixture();

    auditTenantScopeInsertMutation([
        'tenant_id' => $tenantAId,
        'auditable_id' => 991,
        'subject_id' => '991',
        'new_values' => json_encode(['name' => 'foreign-mutation-marker-a1']),
        'trace_id' => 'FOREIGNMUTAAAA',
    ]);
    auditTenantScopeInsertMutation([
        'tenant_id' => (int) $viewer->tenant_id,
        'actor_id' => $viewer->id,
        'auditable_id' => $viewer->id,
        'subject_id' => (string) $viewer->id,
        'new_values' => json_encode(['name' => 'home-mutation-marker-b1']),
        'trace_id' => 'HOMEMUTBBBBBBB',
    ]);

    // Guard: AuditTenantScope::apply where on tenant_id. Deleting it fails assertDontSee.
    Livewire::actingAs($viewer)
        ->test(Mutations::class)
        ->assertSee('home-mutation-marker-b1')
        ->assertDontSee('foreign-mutation-marker-a1')
        ->assertDontSee(__('All tenants'));
});

it('hides foreign-tenant actions from the actions page', function (): void {
    [$viewer, $tenantAId] = auditTenantScopeForeignFixture();

    auditTenantScopeInsertAction([
        'tenant_id' => $tenantAId,
        'event' => 'auth.login.failed',
        'url' => 'https://example.test/foreign-tenant-action-row',
        'trace_id' => 'FOREIGNACTAAAA',
    ]);
    auditTenantScopeInsertAction([
        'tenant_id' => (int) $viewer->tenant_id,
        'actor_id' => $viewer->id,
        'event' => 'auth.login.failed',
        'url' => 'https://example.test/home-tenant-action-row',
        'trace_id' => 'HOMEACTBBBBBBB',
    ]);

    Livewire::actingAs($viewer)
        ->test(Actions::class)
        ->assertSee('https://example.test/home-tenant-action-row')
        ->assertDontSee('https://example.test/foreign-tenant-action-row')
        ->assertDontSee(__('All tenants'));
});

it('refuses retain-toggle on a foreign-tenant action id', function (): void {
    [$viewer, $tenantAId] = auditTenantScopeForeignFixture();

    PrincipalCapability::query()->create([
        'company_id' => $viewer->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $viewer->id,
        'capability_key' => 'admin.audit.log.manage',
        'is_allowed' => true,
    ]);

    $foreignId = auditTenantScopeInsertAction([
        'tenant_id' => $tenantAId,
        'is_retained' => false,
        'url' => 'https://example.test/retain-foreign',
        'trace_id' => 'RETAINFOREIGN1',
    ]);

    expect(fn () => Livewire::actingAs($viewer)->test(Actions::class)->call('toggleRetain', $foreignId))
        ->toThrow(ModelNotFoundException::class);

    expect(AuditAction::query()->findOrFail($foreignId)->is_retained)->toBeFalse();
});

it('returns zero source-history rows for a foreign-tenant subject', function (): void {
    [$viewer, $tenantAId, $userAId] = auditTenantScopeForeignFixture();

    auditTenantScopeInsertMutation([
        'tenant_id' => $tenantAId,
        'auditable_type' => User::class,
        'auditable_id' => $userAId,
        'subject_name' => 'user',
        'subject_id' => (string) $userAId,
        'new_values' => json_encode(['name' => 'should-not-leak']),
        'trace_id' => 'HISTORYFOREIGN',
    ]);

    $history = app(AuditSourceHistory::class)->forRecord(
        subjects: [['name' => 'user', 'id' => $userAId]],
        auditableType: User::class,
        auditableId: $userAId,
    );

    expect($history['total'])->toBe(0)
        ->and($history['entries'])->toBe([]);
});

it('returns an empty trace timeline for a foreign-tenant trace id', function (): void {
    [$viewer, $tenantAId] = auditTenantScopeForeignFixture();

    auditTenantScopeInsertAction([
        'tenant_id' => $tenantAId,
        'trace_id' => 'FOREIGNTRACE01',
        'url' => 'https://example.test/foreign-trace-action',
    ]);
    auditTenantScopeInsertMutation([
        'tenant_id' => $tenantAId,
        'trace_id' => 'FOREIGNTRACE01',
        'new_values' => json_encode(['name' => 'foreign-trace-mutation']),
    ]);

    $timeline = app(AuditTraceTimeline::class)->forTrace('FOREIGNTRACE01');

    expect($timeline['entries'])->toBe([])
        ->and($timeline['action_count'])->toBe(0)
        ->and($timeline['mutation_count'])->toBe(0);
});

it('lets the platform operator toggle all-tenants on mutations and actions', function (): void {
    $company = provisionPlatformOperatorCompany();
    $operator = User::factory()->create(['company_id' => $company->id, 'name' => 'Platform Auditor']);
    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $operator->id,
        'role_id' => $role->id,
    ]);
    app(TenantContext::class)->set((int) $company->tenant_id);

    [$foreignTenant] = createTenantWithCompany(['name' => 'Operator View Foreign']);
    auditTenantScopeInsertMutation([
        'tenant_id' => (int) $foreignTenant->id,
        'new_values' => json_encode(['name' => 'operator-sees-when-all']),
        'trace_id' => 'OPALLMUTAAAAAA',
    ]);
    auditTenantScopeInsertMutation([
        'tenant_id' => (int) $company->tenant_id,
        'actor_id' => $operator->id,
        'new_values' => json_encode(['name' => 'operator-home-mutation']),
        'trace_id' => 'OPHOMEMUTBBBBB',
    ]);
    auditTenantScopeInsertAction([
        'tenant_id' => (int) $foreignTenant->id,
        'event' => 'auth.login.failed',
        'url' => 'https://example.test/operator-foreign-action',
        'trace_id' => 'OPALLACTAAAAAA',
    ]);
    auditTenantScopeInsertAction([
        'tenant_id' => (int) $company->tenant_id,
        'actor_id' => $operator->id,
        'event' => 'auth.login.failed',
        'url' => 'https://example.test/operator-home-action',
        'trace_id' => 'OPHOMEACTBBBBB',
    ]);

    Livewire::actingAs($operator)
        ->test(Mutations::class)
        ->assertSee(__('All tenants'))
        ->assertSee('operator-home-mutation')
        ->assertDontSee('operator-sees-when-all')
        ->set('allTenants', true)
        ->assertSee('operator-sees-when-all')
        ->assertSee(__('Data mutation audit log (all tenants)'));

    Livewire::actingAs($operator)
        ->test(Actions::class)
        ->assertSee(__('All tenants'))
        ->assertSee('https://example.test/operator-home-action')
        ->assertDontSee('https://example.test/operator-foreign-action')
        ->set('allTenants', true)
        ->assertSee('https://example.test/operator-foreign-action')
        ->assertSee(__('Audit action log (all tenants)'));
});

it('keeps mutations tenant-scoped when a non-operator forces allTenants over the wire', function (): void {
    [$viewer, $tenantAId] = auditTenantScopeForeignFixture();

    auditTenantScopeInsertMutation([
        'tenant_id' => $tenantAId,
        'new_values' => json_encode(['name' => 'forced-foreign-mutation-x1']),
        'trace_id' => 'FORCEDMUTAAAAA',
    ]);

    // Guard: AuditTenantScope::apply operator check on allTenants. Dropping it
    // lets a non-operator wire:set leak foreign-tenant rows.
    Livewire::actingAs($viewer)
        ->test(Mutations::class)
        ->set('allTenants', true)
        ->assertDontSee('forced-foreign-mutation-x1');
});

it('keeps actions tenant-scoped when a non-operator forces allTenants over the wire', function (): void {
    [$viewer, $tenantAId] = auditTenantScopeForeignFixture();

    auditTenantScopeInsertAction([
        'tenant_id' => $tenantAId,
        'event' => 'auth.login.failed',
        'url' => 'https://example.test/forced-foreign-action-row',
        'trace_id' => 'FORCEDACTAAAAA',
    ]);

    Livewire::actingAs($viewer)
        ->test(Actions::class)
        ->set('allTenants', true)
        ->assertDontSee('https://example.test/forced-foreign-action-row');
});

it('refuses retain-toggle on a foreign-tenant action when a non-operator forces allTenants', function (): void {
    [$viewer, $tenantAId] = auditTenantScopeForeignFixture();

    PrincipalCapability::query()->create([
        'company_id' => $viewer->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $viewer->id,
        'capability_key' => 'admin.audit.log.manage',
        'is_allowed' => true,
    ]);

    $foreignId = auditTenantScopeInsertAction([
        'tenant_id' => $tenantAId,
        'is_retained' => false,
        'url' => 'https://example.test/retain-forced-foreign',
        'trace_id' => 'RETAINFORCED01',
    ]);

    expect(fn () => Livewire::actingAs($viewer)
        ->test(Actions::class)
        ->set('allTenants', true)
        ->call('toggleRetain', $foreignId))
        ->toThrow(ModelNotFoundException::class);

    expect(AuditAction::query()->findOrFail($foreignId)->is_retained)->toBeFalse();
});
