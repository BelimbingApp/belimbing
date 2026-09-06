<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Exceptions\TenantContextMissingException;
use App\Base\Tenancy\Support\PlatformAsyncEntryPointInventory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

it('keeps the declared platform queued-job inventory identical to Base/Core discovery', function (): void {
    PlatformAsyncEntryPointInventory::assertJobsMatchDiscovery();

    expect(PlatformAsyncEntryPointInventory::queuedJobClasses())
        ->toEqualCanonicalizing(PlatformAsyncEntryPointInventory::discoverQueuedJobClasses());
});

it('keeps the declared platform schedule inventory identical to the live Schedule', function (): void {
    app()->make(Kernel::class)->bootstrap();

    $scheduled = [];
    foreach (app(Schedule::class)->events() as $event) {
        $command = (string) ($event->command ?? '');
        if ($command === '' && isset($event->description)) {
            $command = (string) $event->description;
        }

        if (preg_match("/artisan['\"]?\s+(\S+)/", $command, $matches) === 1) {
            $scheduled[] = $matches[1];
        } elseif ($command !== '' && ! str_contains($command, ' ')) {
            $scheduled[] = $command;
        }
    }

    $scheduled = array_values(array_unique($scheduled));
    sort($scheduled);

    $declared = PlatformAsyncEntryPointInventory::scheduledCommandSignatures();
    sort($declared);

    expect($declared)->toEqual($scheduled);
});

it('stamps tenant A on every platform queued job and restores A over ambient tenant B', function (string $class, object $job): void {
    [$tenantA] = createTenantWithCompany(['name' => 'Async Entry A']);
    [$tenantB] = createTenantWithCompany(['name' => 'Async Entry B']);

    $context = app(TenantContext::class);
    $stamped = 'unset';

    Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$stamped): void {
        $stamped = $event->job->payload()['tenantId'] ?? null;
    });

    $context->set($tenantA->id);

    try {
        Bus::dispatch($job);
    } catch (Throwable) {
        // Handles may refuse probe fixtures; the stamp is read from the payload.
    }

    expect($stamped)->toBe($tenantA->id);

    // Worker ambient is not the dispatcher: poison with B, then restore from the
    // stamped payload. Deleting the JobProcessing restore makes this assert B.
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->allows('payload')->andReturns([
        'tenantId' => $tenantA->id,
        'displayName' => $class,
    ]);
    $queueJob->allows('resolveName')->andReturns($class);
    $queueJob->allows('getQueue')->andReturns('sync');
    $queueJob->allows('getConnectionName')->andReturns('sync');

    $context->set($tenantB->id);
    event(new JobProcessing('sync', $queueJob));

    expect($context->currentTenantId())->toBe($tenantA->id)
        ->and($context->currentTenantId())->not->toBe($tenantB->id);

    event(new JobProcessed('sync', $queueJob));
    expect($context->currentTenantId())->toBeNull();
})->with(PlatformAsyncEntryPointInventory::dispatchableQueuedJobs());

it('restores null for every platform queued job dispatched without a tenant, refusing a default', function (string $class, object $job): void {
    [$tenantB] = createTenantWithCompany(['name' => 'Async Default B']);

    $context = app(TenantContext::class);
    $stamped = 'unset';

    Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$stamped): void {
        $stamped = array_key_exists('tenantId', $event->job->payload())
            ? $event->job->payload()['tenantId']
            : null;
    });

    $context->set($tenantB->id);
    $context->clear();

    try {
        Bus::dispatch($job);
    } catch (Throwable) {
    }

    expect($stamped)->toBeNull();

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->allows('payload')->andReturns(['displayName' => $class]);
    $queueJob->allows('resolveName')->andReturns($class);
    $queueJob->allows('getQueue')->andReturns('sync');
    $queueJob->allows('getConnectionName')->andReturns('sync');

    $context->set($tenantB->id);
    event(new JobProcessing('sync', $queueJob));

    expect($context->currentTenantId())->toBeNull();
    expect(fn () => $context->requireTenantId())->toThrow(TenantContextMissingException::class);

    event(new JobProcessed('sync', $queueJob));
})->with(PlatformAsyncEntryPointInventory::dispatchableQueuedJobs());

it('runs every platform scheduled command with no ambient tenant and does not invent one', function (string $signature): void {
    [$tenantB] = createTenantWithCompany(['name' => 'Schedule Default B']);
    $context = app(TenantContext::class);

    $context->set($tenantB->id);
    $context->clear();

    try {
        Artisan::call($signature);
    } catch (Throwable) {
    }

    expect($context->currentTenantId())->toBeNull();
    expect(fn () => $context->requireTenantId())->toThrow(TenantContextMissingException::class);
})->with(PlatformAsyncEntryPointInventory::scheduledCommandSignatures());
