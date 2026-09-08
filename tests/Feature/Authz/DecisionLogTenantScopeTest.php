<?php

use App\Base\Authz\Contracts\DecisionLogger;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Livewire\DecisionLogs\Index;
use App\Base\Authz\Models\DecisionLog;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    setupAuthzRoles();
});

function decisionLogTenantInsert(array $overrides = []): int
{
    return (int) DB::table('base_authz_decision_logs')->insertGetId(array_replace([
        'company_id' => null,
        'tenant_id' => app(TenantContext::class)->requireTenantId(),
        'actor_type' => PrincipalType::USER->value,
        'actor_id' => 1,
        'acting_for_user_id' => null,
        'capability' => 'admin.test.capability',
        'resource_type' => null,
        'resource_id' => null,
        'allowed' => true,
        'reason_code' => AuthorizationReasonCode::ALLOWED->value,
        'applied_policies' => json_encode([]),
        'context' => json_encode([]),
        'trace_id' => 'DECISIONLOG01',
        'occurred_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides));
}

/** @return array{0: User, 1: int} viewer in tenant B, foreign tenant A id */
function decisionLogTenantForeignFixture(): array
{
    [$tenantA] = createTenantWithCompany(['name' => 'Decision Log Tenant A']);

    [$tenantB, $companyB] = createTenantWithCompany(['name' => 'Decision Log Tenant B']);
    app(TenantContext::class)->set((int) $tenantB->id);

    $viewer = User::factory()->create(['company_id' => $companyB->id, 'name' => 'Tenant B Decision Viewer']);
    $role = Role::query()->where('code', 'tenant_owner')->whereNull('company_id')->firstOrFail();
    PrincipalRole::query()->create([
        'company_id' => $companyB->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $viewer->id,
        'role_id' => $role->id,
    ]);

    return [$viewer, (int) $tenantA->id];
}

function decisionLogFlushLogger(): void
{
    app(DeferredCallbackCollection::class)->invoke();
}

it('stamps the ambient tenant on every buffered decision log row', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Decision Log Stamp Tenant']);
    app(TenantContext::class)->set((int) $tenant->id);

    app(DecisionLogger::class)->log(
        new Actor(PrincipalType::USER, 7, (int) $company->id),
        'admin.authz.decision-log.stamp-ambient',
        null,
        AuthorizationDecision::allow(['stamp_test']),
    );
    decisionLogFlushLogger();

    $row = DecisionLog::query()->where('capability', 'admin.authz.decision-log.stamp-ambient')->firstOrFail();

    expect($row->tenant_id)->toBe((int) $tenant->id);
});

it('prefers the ambient tenant over the actor company tenant when they differ', function (): void {
    [$tenantA, $companyA] = createTenantWithCompany(['name' => 'Decision Log Actor Tenant A']);
    [$tenantB] = createTenantWithCompany(['name' => 'Decision Log Ambient Tenant B']);
    app(TenantContext::class)->set((int) $tenantB->id);

    app(DecisionLogger::class)->log(
        new Actor(PrincipalType::USER, 71, (int) $companyA->id),
        'admin.authz.decision-log.stamp-ambient-first',
        null,
        AuthorizationDecision::allow(['stamp_ambient_first']),
    );
    decisionLogFlushLogger();

    $row = DecisionLog::query()->where('capability', 'admin.authz.decision-log.stamp-ambient-first')->firstOrFail();

    expect($row->tenant_id)->toBe((int) $tenantB->id)
        ->and($row->tenant_id)->not->toBe((int) $tenantA->id);
});

it('falls back to the actor company tenant when no ambient tenant is set', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Decision Log Fallback Tenant']);
    app(TenantContext::class)->clear();

    app(DecisionLogger::class)->log(
        new Actor(PrincipalType::USER, 8, (int) $company->id),
        'admin.authz.decision-log.stamp-fallback',
        null,
        AuthorizationDecision::allow(['stamp_fallback']),
    );
    decisionLogFlushLogger();

    $row = DecisionLog::query()->where('capability', 'admin.authz.decision-log.stamp-fallback')->firstOrFail();

    expect($row->tenant_id)->toBe((int) $tenant->id);
});

it('hides decision logs of another tenant from a tenant admin', function (): void {
    [$viewer, $tenantAId] = decisionLogTenantForeignFixture();

    decisionLogTenantInsert([
        'tenant_id' => $tenantAId,
        'capability' => 'foreign.tenant.decision.capability.a1',
        'trace_id' => 'FOREIGNDECAAAA',
    ]);
    decisionLogTenantInsert([
        'tenant_id' => (int) $viewer->tenant_id,
        'actor_id' => $viewer->id,
        'capability' => 'home.tenant.decision.capability.b1',
        'trace_id' => 'HOMEDECBBBBBB',
    ]);

    // Guard: AuditTenantScope::apply on DecisionLogs\Index. Dropping it leaks A.
    Livewire::actingAs($viewer)
        ->test(Index::class)
        ->assertSee('home.tenant.decision.capability.b1')
        ->assertDontSee('foreign.tenant.decision.capability.a1')
        ->assertDontSee(__('All tenants'));
});

it('keeps the list tenant-scoped when a non-operator forces allTenants over the wire', function (): void {
    [$viewer, $tenantAId] = decisionLogTenantForeignFixture();

    decisionLogTenantInsert([
        'tenant_id' => $tenantAId,
        'capability' => 'forced.foreign.decision.capability.x1',
        'trace_id' => 'FORCEDDECAAAA',
    ]);

    Livewire::actingAs($viewer)
        ->test(Index::class)
        ->set('allTenants', true)
        ->assertDontSee('forced.foreign.decision.capability.x1');
});

it('lets the platform operator tenant view all tenants only with the toggle', function (): void {
    $company = provisionPlatformOperatorCompany();
    $operator = User::factory()->create(['company_id' => $company->id, 'name' => 'Platform Decision Viewer']);
    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $operator->id,
        'role_id' => $role->id,
    ]);
    app(TenantContext::class)->set((int) $company->tenant_id);

    [$foreignTenant] = createTenantWithCompany(['name' => 'Operator Decision Foreign']);
    decisionLogTenantInsert([
        'tenant_id' => (int) $foreignTenant->id,
        'capability' => 'operator.sees.when.all.decision',
        'trace_id' => 'OPALLDECAAAAA',
    ]);
    decisionLogTenantInsert([
        'tenant_id' => (int) $company->tenant_id,
        'actor_id' => $operator->id,
        'capability' => 'operator.home.decision.capability',
        'trace_id' => 'OPHOMEDECBBBB',
    ]);

    Livewire::actingAs($operator)
        ->test(Index::class)
        ->assertSee(__('All tenants'))
        ->assertSee('operator.home.decision.capability')
        ->assertDontSee('operator.sees.when.all.decision')
        ->set('allTenants', true)
        ->assertSee('operator.sees.when.all.decision')
        ->assertSee(__('Decision logs (all tenants)'));
});

it('scopes the last-prune retention card to the ambient tenant', function (): void {
    [$viewer, $tenantAId] = decisionLogTenantForeignFixture();

    // Foreign must be newer than home: orderByDesc without the scope would
    // otherwise still pick the home row and leave this test green (#894).
    $homeAt = now()->subDay()->seconds(0);
    $foreignAt = now()->subHour()->seconds(0);

    DB::table('base_audit_actions')->insert([
        [
            'company_id' => null,
            'tenant_id' => $tenantAId,
            'actor_type' => PrincipalType::USER->value,
            'actor_id' => 1,
            'actor_role' => 'core_admin',
            'ip_address' => '127.0.0.1',
            'url' => 'console://blb:authz:decision-logs:prune',
            'user_agent' => 'Pest',
            'event' => 'console.command',
            'payload' => json_encode(['argv' => ['blb:authz:decision-logs:prune']]),
            'trace_id' => 'PRUNEFOREIGN01',
            'is_retained' => false,
            'occurred_at' => $foreignAt->toDateTimeString(),
        ],
        [
            'company_id' => $viewer->company_id,
            'tenant_id' => (int) $viewer->tenant_id,
            'actor_type' => PrincipalType::USER->value,
            'actor_id' => $viewer->id,
            'actor_role' => 'tenant_owner',
            'ip_address' => '127.0.0.1',
            'url' => 'console://blb:authz:decision-logs:prune',
            'user_agent' => 'Pest',
            'event' => 'console.command',
            'payload' => json_encode(['argv' => ['blb:authz:decision-logs:prune']]),
            'trace_id' => 'PRUNEHOMEBBBB1',
            'is_retained' => false,
            'occurred_at' => $homeAt->toDateTimeString(),
        ],
    ]);

    Livewire::actingAs($viewer)
        ->test(Index::class)
        ->assertSee($homeAt->format('Y-m-d H:i'))
        ->assertDontSee($foreignAt->format('Y-m-d H:i'));
});

it('hides a null-tenant decision log from a tenant admin and shows it only with all-tenants', function (): void {
    // Runtime nulls remain possible when resolveTenantId has neither ambient nor
    // company fallback. Historical pre-migration rows are backfilled to the
    // licensee tenant; leftover nulls stay invisible under exact tenant scope.
    [$viewer] = decisionLogTenantForeignFixture();

    decisionLogTenantInsert([
        'tenant_id' => null,
        'capability' => 'null.tenant.decision.capability',
        'trace_id' => 'NULLTENANTDEC1',
    ]);
    decisionLogTenantInsert([
        'tenant_id' => (int) $viewer->tenant_id,
        'actor_id' => $viewer->id,
        'capability' => 'home.tenant.with.null.sibling',
        'trace_id' => 'HOMENULLSIBLNG',
    ]);

    Livewire::actingAs($viewer)
        ->test(Index::class)
        ->assertSee('home.tenant.with.null.sibling')
        ->assertDontSee('null.tenant.decision.capability');

    $company = provisionPlatformOperatorCompany();
    $operator = User::factory()->create(['company_id' => $company->id, 'name' => 'Null Tenant Decision Viewer']);
    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $operator->id,
        'role_id' => $role->id,
    ]);
    app(TenantContext::class)->set((int) $company->tenant_id);

    Livewire::actingAs($operator)
        ->test(Index::class)
        ->assertDontSee('null.tenant.decision.capability')
        ->set('allTenants', true)
        ->assertSee('null.tenant.decision.capability');
});
it('denies the page without admin.authz.decision-log.list', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Decision Log Deny Tenant']);
    app(TenantContext::class)->set((int) $tenant->id);

    $stranger = User::factory()->create(['company_id' => $company->id]);
    PrincipalCapability::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $stranger->id,
        'capability_key' => 'admin.user.view',
        'is_allowed' => true,
    ]);

    $this->actingAs($stranger)
        ->get(route('admin.authz.decision-logs.index'))
        ->assertForbidden();
});
