<?php

use App\Base\Audit\DTO\RequestContext;
use App\Base\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\DomainCommands\ZzTenantScopedProbeCommand;

beforeEach(function (): void {
    ZzTenantScopedProbeCommand::reset();
    Artisan::registerCommand(app(ZzTenantScopedProbeCommand::class));
});

afterEach(function (): void {
    ZzTenantScopedProbeCommand::reset();
    app(TenantContext::class)->clear();
});

it('refuses an unknown tenant before handle runs', function (): void {
    $this->artisan('zz:tenant-scoped-probe', ['--tenant' => 9_999_999])
        ->expectsOutputToContain('unknown or not available')
        ->assertFailed();

    expect(ZzTenantScopedProbeCommand::$handled)->toBeFalse()
        ->and(app(TenantContext::class)->currentTenantId())->toBeNull();
});

it('refuses an inactive tenant before handle runs', function (): void {
    $tenant = createTenant(['name' => 'Suspended', 'status' => 'suspended']);

    $this->artisan('zz:tenant-scoped-probe', ['--tenant' => $tenant->id])
        ->expectsOutputToContain('is not active')
        ->assertFailed();

    expect(ZzTenantScopedProbeCommand::$handled)->toBeFalse();
});

it('binds an active tenant for handle and refreshes audit RequestContext', function (): void {
    $tenant = createTenant(['name' => 'Scoped']);

    // Force an early RequestContext so the command must refresh it.
    app()->instance(RequestContext::class, RequestContext::forConsole(null, 'zz:tenant-scoped-probe'));
    expect(app(RequestContext::class)->tenantId)->toBeNull();

    $this->artisan('zz:tenant-scoped-probe', ['--tenant' => $tenant->id])
        ->assertSuccessful();

    expect(ZzTenantScopedProbeCommand::$handled)->toBeTrue()
        ->and(ZzTenantScopedProbeCommand::$tenantSeenInHandle)->toBe($tenant->id)
        ->and(ZzTenantScopedProbeCommand::$requestContextTenantInHandle)->toBe($tenant->id)
        ->and(app(TenantContext::class)->currentTenantId())->toBeNull();
});

it('requires --tenant before handle runs', function (): void {
    $this->artisan('zz:tenant-scoped-probe')
        ->expectsOutputToContain('--tenant')
        ->assertFailed();

    expect(ZzTenantScopedProbeCommand::$handled)->toBeFalse();
});
