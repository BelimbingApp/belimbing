<?php

use App\Base\Audit\Livewire\AuditLog\OperatorActivity;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\View\ViewException;
use Livewire\Livewire;

function operatorActivityInsert(array $overrides = []): int
{
    $payload = $overrides['payload'] ?? [
        'method' => 'GET',
        'route' => 'admin.secret.show',
        'status' => 200,
        'secret' => 'must-not-render-PAYLOAD-TOKEN-9f3a',
    ];
    unset($overrides['payload']);

    return (int) DB::table('base_audit_actions')->insertGetId(array_replace([
        'company_id' => null,
        'tenant_id' => app(TenantContext::class)->requireTenantId(),
        'actor_type' => PrincipalType::USER->value,
        'actor_id' => 1,
        'actor_role' => 'core_admin',
        'ip_address' => '127.0.0.1',
        'url' => 'https://example.test/admin/activity',
        'user_agent' => 'Pest',
        'event' => 'http.request',
        'payload' => json_encode($payload),
        'trace_id' => 'OPACT1234567',
        'is_retained' => false,
        'occurred_at' => now()->toDateTimeString(),
    ], $overrides));
}

it('lists tenant activity for an operator without rendering payload contents', function (): void {
    $user = createAdminUser();
    $token = 'must-not-render-PAYLOAD-TOKEN-9f3a';

    operatorActivityInsert([
        'actor_id' => $user->id,
        'payload' => [
            'method' => 'POST',
            'route' => 'admin.secret.show',
            'status' => 200,
            'secret' => $token,
        ],
        'event' => 'http.request',
        'url' => 'https://example.test/admin/secret',
    ]);

    $html = Livewire::actingAs($user)
        ->test(OperatorActivity::class)
        ->assertSee('http.request')
        ->assertSee('https://example.test/admin/secret')
        ->assertDontSee($token)
        ->assertDontSee('admin.secret.show')
        ->assertDontSee(__('Export'))
        ->html();

    expect($html)->not->toContain($token)
        ->and($html)->not->toContain('"secret"')
        ->and($html)->not->toContain('PAYLOAD-TOKEN');
});

it('denies a viewer without the audit list capability', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'No Audit Cap Tenant']);
    app(TenantContext::class)->set((int) $tenant->id);
    $viewer = User::factory()->create(['company_id' => $company->id]);

    operatorActivityInsert([
        'tenant_id' => (int) $tenant->id,
        'actor_id' => $viewer->id,
        'event' => 'http.request',
    ]);

    // Guard under test: mount authorize(admin.audit.log.list). Deleting that
    // authorize() call makes this expectation fail.
    try {
        Livewire::actingAs($viewer)->test(OperatorActivity::class);
        expect(false)->toBeTrue('expected an authorization denial');
    } catch (ViewException $e) {
        expect($e->getPrevious())->toBeInstanceOf(AuthorizationDeniedException::class);
    } catch (AuthorizationDeniedException $e) {
        expect($e)->toBeInstanceOf(AuthorizationDeniedException::class);
    }
});

it('hides other tenants rows under the tenant where guard', function (): void {
    $user = createAdminUser();
    $homeTenant = app(TenantContext::class)->requireTenantId();
    [$foreignTenant] = createTenantWithCompany(['name' => 'Foreign Audit Tenant']);

    operatorActivityInsert([
        'tenant_id' => $homeTenant,
        'actor_id' => $user->id,
        'event' => 'http.request',
        'url' => 'https://example.test/home-tenant-row',
    ]);
    operatorActivityInsert([
        'tenant_id' => (int) $foreignTenant->id,
        'actor_id' => $user->id,
        'event' => 'http.request',
        'url' => 'https://example.test/foreign-tenant-row',
        'payload' => ['secret' => 'foreign-payload-should-stay-hidden'],
    ]);

    // Guard under test: where(tenant_id, current). Deleting that where clause
    // makes assertDontSee('foreign-tenant-row') fail.
    Livewire::actingAs($user)
        ->test(OperatorActivity::class)
        ->assertSee('https://example.test/home-tenant-row')
        ->assertDontSee('https://example.test/foreign-tenant-row')
        ->assertDontSee('foreign-payload-should-stay-hidden');
});

it('refuses the HTTP route without the audit list capability', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Route Deny Tenant']);
    app(TenantContext::class)->set((int) $tenant->id);
    $viewer = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($viewer)
        ->get(route('admin.audit.activity'))
        ->assertForbidden();
});

it('allows an operator through the HTTP route', function (): void {
    $user = createAdminUser();

    $this->actingAs($user)
        ->get(route('admin.audit.activity'))
        ->assertOk()
        ->assertSee('Audit Activity');
});

it('filters by search, exact operation, actor name, actor id, and date bounds', function (): void {
    $user = createAdminUser();
    $other = User::factory()->create(['company_id' => $user->company_id, 'name' => 'Filter Actor Zeta']);

    operatorActivityInsert([
        'actor_id' => $user->id,
        'event' => 'http.request',
        'url' => 'https://example.test/keep-me',
        'occurred_at' => '2026-03-10 12:00:00',
    ]);
    operatorActivityInsert([
        'actor_id' => $other->id,
        'event' => 'auth.login',
        'url' => 'https://example.test/login-zeta',
        'actor_role' => 'viewer',
        'occurred_at' => '2026-03-15 12:00:00',
    ]);
    operatorActivityInsert([
        'actor_id' => $other->id,
        'event' => 'http.request',
        'url' => 'https://example.test/out-of-range',
        'occurred_at' => '2026-01-01 12:00:00',
    ]);

    Livewire::actingAs($user)
        ->test(OperatorActivity::class)
        ->set('search', 'login-zeta')
        ->assertSee('https://example.test/login-zeta')
        ->assertDontSee('https://example.test/keep-me')
        ->set('search', '')
        ->set('filterOperation', 'auth.login')
        ->assertSee('auth.login')
        ->assertDontSee('https://example.test/keep-me')
        ->set('filterOperation', '')
        ->set('filterActor', 'Zeta')
        ->assertSee('Filter Actor Zeta')
        ->assertDontSee('https://example.test/keep-me')
        ->set('filterActor', (string) $other->id)
        ->assertSee('https://example.test/login-zeta')
        ->assertDontSee('https://example.test/keep-me')
        ->set('filterActor', '')
        ->set('filterFrom', '2026-03-01')
        ->set('filterTo', '2026-03-20')
        ->assertSee('https://example.test/keep-me')
        ->assertSee('https://example.test/login-zeta')
        ->assertDontSee('https://example.test/out-of-range');
});

it('toggles sortable columns and resets pagination when filters change', function (): void {
    $user = createAdminUser();

    foreach (range(1, 30) as $i) {
        operatorActivityInsert([
            'actor_id' => $user->id,
            'event' => 'http.request',
            'url' => 'https://example.test/page-row-'.$i,
            'occurred_at' => now()->subMinutes($i)->toDateTimeString(),
        ]);
    }

    Livewire::actingAs($user)
        ->test(OperatorActivity::class)
        ->assertSet('sortBy', 'occurred_at')
        ->assertSet('sortDir', 'desc')
        ->call('sort', 'occurred_at')
        ->assertSet('sortDir', 'asc')
        ->call('sort', 'event')
        ->assertSet('sortBy', 'event')
        ->call('sort', 'actor_name')
        ->assertSet('sortBy', 'actor_name')
        ->call('gotoPage', 2)
        ->assertSet('paginators.page', 2)
        ->set('filterOperation', 'http.request')
        ->assertSet('paginators.page', 1)
        ->set('filterActor', (string) $user->id)
        ->assertSet('paginators.page', 1)
        ->set('filterFrom', '2020-01-01')
        ->assertSet('paginators.page', 1)
        ->set('filterTo', '2030-01-01')
        ->assertSet('paginators.page', 1)
        ->assertSee('http.request');
});

it('shows an empty state when filters match nothing', function (): void {
    $user = createAdminUser();

    operatorActivityInsert([
        'actor_id' => $user->id,
        'event' => 'http.request',
        'url' => 'https://example.test/only-row',
    ]);

    Livewire::actingAs($user)
        ->test(OperatorActivity::class)
        ->set('filterOperation', 'does.not.exist')
        ->assertSee(__('No audit activity matches the current filters for this tenant.'))
        ->assertDontSee('https://example.test/only-row');
});
