<?php

use App\Base\Audit\Models\AuditAction;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Exceptions\PlatformOperatorTenantInvariantViolationException;
use App\Base\Tenancy\Livewire\Admin\Tenants;
use App\Base\Tenancy\Models\Tenant;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * A user holding only the list capability: enough to open the page, never
 * enough to change a row.
 */
function tenantOperatorUiListOnlyUser(): User
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    PrincipalCapability::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'capability_key' => 'admin.tenancy.tenant.list',
        'is_allowed' => true,
    ]);

    app(TenantContext::class)->set((int) $company->tenant_id);

    return $user;
}

/**
 * Persist whatever the audit buffer is holding.
 *
 * `AuditBuffer` batches its inserts behind `defer()`, which a real request
 * flushes after the response and a test never does — so an unflushed buffer
 * would make every count below read zero, including the ones that assert a
 * row exists. Flushing before each assertion is what makes "no row" mean
 * "nothing was recorded" rather than "nothing was written yet".
 */
function tenantOperatorUiFlushAudit(): void
{
    app(DeferredCallbackCollection::class)->invoke();
}

/**
 * Count the audit action rows this page writes for one tenant.
 *
 * The subject is matched in PHP off the cast payload rather than through a
 * JSON path predicate, so the count means the same thing on SQLite and on
 * the Postgres mirror.
 */
function tenantOperatorUiAuditRows(string $event, ?int $tenantId = null): int
{
    tenantOperatorUiFlushAudit();

    return AuditAction::query()
        ->where('event', $event)
        ->get()
        ->filter(fn (AuditAction $row): bool => $tenantId === null
            || (int) ($row->payload['context']['tenant_id'] ?? 0) === $tenantId)
        ->count();
}

/** How many times the rendered page offers a wire:click for the given call. */
function tenantOperatorUiActionCount(string $html, string $call): int
{
    return substr_count($html, 'wire:click="'.$call.'"');
}

/** Probe route for the end-to-end lockout: reports the resolved tenant. */
function tenantOperatorUiProbeRoute(): void
{
    Route::middleware('web')->get('/zz-tenant-operator-ui', fn () => response()->json([
        'tenant_id' => app(TenantContext::class)->currentTenantId(),
    ]));
}

it('suspends and reactivates a tenant, writing exactly one audit action per change', function (): void {
    $admin = createAdminUser();
    $this->actingAs($admin);

    $tenant = createTenant(['name' => 'Suspendable Tenant']);

    Livewire::test(Tenants::class)
        ->call('suspendTenant', $tenant->id)
        ->assertDispatched('notify');

    expect($tenant->fresh()->status)->toBe('suspended')
        ->and($tenant->fresh()->isActive())->toBeFalse()
        ->and(tenantOperatorUiAuditRows('tenancy.tenant.suspended', $tenant->id))->toBe(1)
        ->and(tenantOperatorUiAuditRows('tenancy.tenant.reactivated', $tenant->id))->toBe(0);

    $suspendRow = AuditAction::query()->where('event', 'tenancy.tenant.suspended')->sole();
    expect((int) $suspendRow->actor_id)->toBe((int) $admin->id)
        ->and($suspendRow->payload['context']['to_status'])->toBe('suspended')
        ->and($suspendRow->payload['context']['from_status'])->toBe('active');

    Livewire::test(Tenants::class)
        ->call('reactivateTenant', $tenant->id)
        ->assertDispatched('notify');

    expect($tenant->fresh()->isActive())->toBeTrue()
        ->and(tenantOperatorUiAuditRows('tenancy.tenant.reactivated', $tenant->id))->toBe(1)
        ->and(tenantOperatorUiAuditRows('tenancy.tenant.suspended', $tenant->id))->toBe(1);
});

it('refuses a suspend from an actor without admin.tenancy.tenant.manage and leaves the row untouched', function (): void {
    $tenant = createTenant(['name' => 'Untouchable Tenant']);
    $this->actingAs(tenantOperatorUiListOnlyUser());

    Livewire::test(Tenants::class)
        ->call('suspendTenant', $tenant->id)
        ->assertForbidden();

    expect($tenant->fresh()->status)->toBe('active')
        ->and(tenantOperatorUiAuditRows('tenancy.tenant.suspended'))->toBe(0);
});

it('refuses a reactivate from an actor without admin.tenancy.tenant.manage', function (): void {
    $tenant = createTenant(['name' => 'Stays Suspended Tenant', 'status' => 'suspended']);
    $this->actingAs(tenantOperatorUiListOnlyUser());

    Livewire::test(Tenants::class)
        ->call('reactivateTenant', $tenant->id)
        ->assertForbidden();

    expect($tenant->fresh()->status)->toBe('suspended')
        ->and(tenantOperatorUiAuditRows('tenancy.tenant.reactivated'))->toBe(0);
});

it('hides the row action from an actor who may only list tenants', function (): void {
    $tenant = createTenant(['name' => 'Listed Tenant']);

    $this->actingAs(createAdminUser());
    $withCapability = Livewire::test(Tenants::class)->html();

    $this->actingAs(tenantOperatorUiListOnlyUser());
    $withoutCapability = Livewire::test(Tenants::class)->html();

    // The same row on the same page: present for one actor, absent for the
    // other. A column that stopped rendering entirely would fail the first.
    expect(tenantOperatorUiActionCount($withCapability, "suspendTenant({$tenant->id})"))->toBe(1)
        ->and(tenantOperatorUiActionCount($withoutCapability, "suspendTenant({$tenant->id})"))->toBe(0)
        ->and($withoutCapability)->toContain('Listed Tenant');
});

it('offers a suspend action on an ordinary row and none on the platform-operator row', function (): void {
    $operator = platformOperatorTenant();
    $ordinary = createTenant(['name' => 'Ordinary Tenant']);

    $this->actingAs(createAdminUser());
    $html = Livewire::test(Tenants::class)->html();

    expect(tenantOperatorUiActionCount($html, "suspendTenant({$ordinary->id})"))->toBe(1)
        ->and(tenantOperatorUiActionCount($html, "suspendTenant({$operator->id})"))->toBe(0);
});

it('refuses a direct suspend of the platform-operator tenant and writes no audit row', function (): void {
    $operator = platformOperatorTenant();
    $this->actingAs(createAdminUser());

    expect(fn () => Livewire::test(Tenants::class)->call('suspendTenant', $operator->id))
        ->toThrow(PlatformOperatorTenantInvariantViolationException::class);

    expect($operator->fresh()->isActive())->toBeTrue()
        ->and(tenantOperatorUiAuditRows('tenancy.tenant.suspended'))->toBe(0);
});

it('reports a missing tenant id rather than silently doing nothing', function (): void {
    $this->actingAs(createAdminUser());

    expect(fn () => Livewire::test(Tenants::class)->call('suspendTenant', 999_999))
        ->toThrow(ModelNotFoundException::class);
});

it('locks the suspended tenant out of the web surface on its user next request', function (): void {
    tenantOperatorUiProbeRoute();

    [$tenant, $company] = createTenantWithCompany(['name' => 'Locked Out Tenant']);
    $tenantUser = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($tenantUser)
        ->get('/zz-tenant-operator-ui')
        ->assertOk()
        ->assertExactJson(['tenant_id' => $tenant->id]);

    $this->actingAs(createAdminUser());
    Livewire::test(Tenants::class)->call('suspendTenant', $tenant->id);

    expect(Tenant::query()->findOrFail($tenant->id)->isActive())->toBeFalse();

    $this->actingAs($tenantUser)
        ->get('/zz-tenant-operator-ui')
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', __('tenancy.suspended'));
});

/*
 * Reviewer findings on #831, both reachable only by a direct Livewire call --
 * the page correctly hides the transition a row already holds, which is
 * exactly why nothing was asserting either of them.
 */

// fable-5.1: with the blade's `@elseif($tenant->isActive())` forced true, a
// suspended row renders Suspend instead of Reactivate, and clicking it
// re-suspends an already-suspended tenant. Half the surface #827 asks for --
// the Reactivate button -- was never rendered by any test.
it('offers a reactivate action, and no suspend action, on a suspended row', function (): void {
    $tenant = createTenant(['name' => 'Parked Tenant', 'status' => 'suspended']);

    $this->actingAs(createAdminUser());
    $html = Livewire::test(Tenants::class)->html();

    expect(tenantOperatorUiActionCount($html, "reactivateTenant({$tenant->id})"))->toBe(1)
        ->and(tenantOperatorUiActionCount($html, "suspendTenant({$tenant->id})"))->toBe(0);
});

// opus-5-extra: changeStatus wrote and audited unconditionally, so a repeated
// suspend recorded a second `tenancy.tenant.suspended` with
// from_status = to_status = suspended.
it('records nothing for a repeated suspend of an already-suspended tenant', function (): void {
    $this->actingAs(createAdminUser());
    $tenant = createTenant(['name' => 'Twice Suspended']);

    Livewire::test(Tenants::class)->call('suspendTenant', $tenant->id);
    Livewire::test(Tenants::class)->call('suspendTenant', $tenant->id);

    tenantOperatorUiFlushAudit();

    expect(tenantOperatorUiAuditRows('tenancy.tenant.suspended', $tenant->id))->toBe(1)
        ->and(Tenant::query()->whereKey($tenant->id)->value('status'))->toBe('suspended');
});
