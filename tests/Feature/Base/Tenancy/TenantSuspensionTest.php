<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Exceptions\PlatformOperatorTenantInvariantViolationException;
use App\Base\Tenancy\Models\Tenant;
use App\Core\User\Models\User;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\TenantSuspensionProbeJob;

/**
 * A sentinel tenant that exists in no fixture. Binding it before a request
 * means "context is null afterwards" can only be true because the middleware
 * cleared it — never because nothing ever bound anything.
 */
const TENANT_SUSPENSION_SENTINEL = 424_242;

/** Register the probe route; it reports the tenant the request resolved to. */
function tenantSuspensionProbeRoute(): void
{
    Route::middleware('web')->get('/zz-tenant-suspension', fn () => response()->json([
        'tenant_id' => app(TenantContext::class)->currentTenantId(),
    ]));
}

function tenantSuspensionUserIn(int $companyId): User
{
    return User::factory()->create(['company_id' => $companyId]);
}

function tenantSuspensionRunNextJob(): void
{
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions);
}

it('logs a suspended tenant out of the web guard and redirects to login', function (): void {
    tenantSuspensionProbeRoute();

    [, $company] = createTenantWithCompany(['name' => 'Suspended Tenant', 'status' => 'suspended']);
    $user = tenantSuspensionUserIn((int) $company->id);

    app(TenantContext::class)->set(TENANT_SUSPENSION_SENTINEL);

    $this->actingAs($user)
        ->get('/zz-tenant-suspension')
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', __('tenancy.suspended'));

    expect(app(TenantContext::class)->currentTenantId())->toBeNull();
    expect(auth()->guard('web')->check())->toBeFalse();
});

it('serves a user whose tenant is active', function (): void {
    tenantSuspensionProbeRoute();

    [$tenant, $company] = createTenantWithCompany(['name' => 'Active Tenant']);
    $user = tenantSuspensionUserIn((int) $company->id);

    $this->actingAs($user)
        ->get('/zz-tenant-suspension')
        ->assertOk()
        ->assertExactJson(['tenant_id' => $tenant->id]);
});

it('leaves an active tenant unaffected when a sibling tenant is suspended', function (): void {
    tenantSuspensionProbeRoute();

    [$active, $activeCompany] = createTenantWithCompany(['name' => 'Tenant A']);
    createTenantWithCompany(['name' => 'Tenant B', 'status' => 'suspended']);
    $user = tenantSuspensionUserIn((int) $activeCompany->id);

    $this->actingAs($user)
        ->get('/zz-tenant-suspension')
        ->assertOk()
        ->assertExactJson(['tenant_id' => $active->id]);
});

it('fails a queued job stamped with a suspended tenant without running or retrying it', function (): void {
    config()->set('queue.default', 'database');
    TenantSuspensionProbeJob::resetProbe();

    $tenant = createTenant(['name' => 'Suspended Queue Tenant', 'status' => 'suspended']);

    app(TenantContext::class)->runForTenant(
        (int) $tenant->id,
        fn () => Bus::dispatch(new TenantSuspensionProbeJob),
    );

    expect(DB::table('jobs')->count())->toBe(1);

    tenantSuspensionRunNextJob();

    // handle() never ran, and the job was failed rather than released: an
    // empty jobs table is the "no retry" half of the assertion.
    expect(TenantSuspensionProbeJob::$runs)->toBe(0);
    expect(DB::table('jobs')->count())->toBe(0);
});

it('runs a queued job stamped with an active tenant', function (): void {
    config()->set('queue.default', 'database');
    TenantSuspensionProbeJob::resetProbe();

    $tenant = createTenant(['name' => 'Active Queue Tenant']);

    app(TenantContext::class)->runForTenant(
        (int) $tenant->id,
        fn () => Bus::dispatch(new TenantSuspensionProbeJob),
    );

    tenantSuspensionRunNextJob();

    expect(TenantSuspensionProbeJob::$runs)->toBe(1);
    expect(TenantSuspensionProbeJob::$observedTenantId)->toBe((int) $tenant->id);
    expect(DB::table('jobs')->count())->toBe(0);
});

it('refuses to mark the platform-operator tenant inactive', function (): void {
    $operator = platformOperatorTenant();

    expect(fn () => $operator->update(['status' => 'suspended']))
        ->toThrow(PlatformOperatorTenantInvariantViolationException::class);

    expect(Tenant::query()->whereKey($operator->id)->value('status'))->toBe('active');

    // Control: the invariant refuses the inactive transition, not every save.
    // The refused status stays on the rejected in-memory instance, so reload
    // before saving again — a caller that retries would do the same.
    $operator->refresh();
    $operator->update(['name' => 'Renamed Operator']);

    expect(Tenant::query()->whereKey($operator->id)->value('name'))->toBe('Renamed Operator');
});

it('reports a soft-deleted tenant as unusable through the shared rule', function (): void {
    $tenant = createTenant(['name' => 'Trashed Tenant']);

    expect($tenant->isActive())->toBeTrue();

    $tenant->delete();

    expect($tenant->isActive())->toBeFalse();
});

/*
 * The two below pin the `withTrashed()` token on the web and queue lookups.
 * Reviewer finding on #826 (fable-5.1): `isActive()` was only exercised on
 * the model, so replacing `Tenant::withTrashed()->find()` with
 * `Tenant::query()->find()` at either entry point survived the whole suite.
 * A trashed row then reads as "no row", which both sites deliberately treat
 * as "leave alone" for the unresolvable-tenant path (#729) — so a
 * soft-deleted tenant's users keep being served and its jobs keep running.
 */
it('refuses a soft-deleted tenant on the web the same way it refuses a suspended one', function (): void {
    tenantSuspensionProbeRoute();

    [$tenant, $company] = createTenantWithCompany(['name' => 'Trashed Web Tenant']);
    $user = tenantSuspensionUserIn((int) $company->id);

    $tenant->delete();

    app(TenantContext::class)->set(TENANT_SUSPENSION_SENTINEL);

    $this->actingAs($user)
        ->get('/zz-tenant-suspension')
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', __('tenancy.suspended'));

    expect(app(TenantContext::class)->currentTenantId())->toBeNull();
    expect(auth()->guard('web')->check())->toBeFalse();
});

it('fails a queued job stamped with a soft-deleted tenant without running it', function (): void {
    config()->set('queue.default', 'database');
    TenantSuspensionProbeJob::resetProbe();

    $tenant = createTenant(['name' => 'Trashed Queue Tenant']);

    app(TenantContext::class)->runForTenant(
        (int) $tenant->id,
        fn () => Bus::dispatch(new TenantSuspensionProbeJob),
    );

    expect(DB::table('jobs')->count())->toBe(1);

    $tenant->delete();

    tenantSuspensionRunNextJob();

    expect(TenantSuspensionProbeJob::$runs)->toBe(0);
    expect(DB::table('jobs')->count())->toBe(0);
});
