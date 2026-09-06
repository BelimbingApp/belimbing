<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Exceptions\TenantJobMismatchException;
use Tests\Support\TenantDispatchProbeCommand;
use Tests\Support\TenantDispatchProbeJob;

beforeEach(function (): void {
    TenantDispatchProbeJob::$observedTenantId = null;
});

it('dispatches a job carrying the tenant bound to the command execution', function (): void {
    app(TenantContext::class)->set(71);

    $command = new TenantDispatchProbeCommand;
    $command->jobTenantId = 71;

    expect($command->handle())->toBe(0)
        ->and($command->dispatchedJob)->not->toBeNull();

    assertJobCarriesTenant($command->dispatchedJob, 71);
    expect(TenantDispatchProbeJob::$observedTenantId)->toBe(71);
});

it('refuses a job carrying a different tenant before queue dispatch', function (): void {
    app(TenantContext::class)->set(71);

    $command = new TenantDispatchProbeCommand;
    $command->jobTenantId = 72;

    expect(fn () => $command->handle())
        ->toThrow(TenantJobMismatchException::class, 'Job tenant [72] does not match command tenant [71].')
        ->and(TenantDispatchProbeJob::$observedTenantId)->toBeNull();
});
