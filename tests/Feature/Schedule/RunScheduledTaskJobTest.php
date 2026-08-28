<?php

use App\Base\Schedule\Exceptions\ScheduledTaskExecutionException;
use App\Base\Schedule\Jobs\RunScheduledTaskJob;
use App\Base\Schedule\Models\ScheduleRun;
use App\Base\Schedule\Models\ScheduleSuppression;
use App\Base\Schedule\Services\ScheduleRunRecorder;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setupAuthzRoles();
});

function runScheduledTaskJobEvent(): Event
{
    $event = app(Schedule::class)->command('inspire')->description('inspire');
    $event->exitCode = 0;

    return $event;
}

function runScheduledTaskJobHandle(string $key, ?int $runId, ScheduleRunRecorder $recorder): void
{
    (new RunScheduledTaskJob($key, $runId))->handle(
        app(Schedule::class),
        $recorder,
        app(Dispatcher::class),
        app(ExceptionHandler::class),
    );
}

test('running the job for real transitions a pre-queued row through running to succeeded', function (): void {
    $event = runScheduledTaskJobEvent();
    $recorder = app(ScheduleRunRecorder::class);
    $key = $recorder->key($event);

    $run = $recorder->queueManualRun($key, 'inspire', (string) $event->expression, 1, 'Ops Operator');

    expect($run->status)->toBe('queued')
        ->and($run->trigger)->toBe('manual')
        ->and($run->triggered_by_name)->toBe('Ops Operator');

    runScheduledTaskJobHandle($key, $run->id, $recorder);

    expect(ScheduleRun::query()->count())->toBe(1);

    $run->refresh();

    expect($run->status)->toBe('succeeded')
        ->and($run->trigger)->toBe('manual')
        ->and($run->triggered_by_name)->toBe('Ops Operator')
        ->and($run->finished_at)->not->toBeNull();
});

test('the job fails an unstarted queued row explicitly when the key is no longer registered', function (): void {
    $recorder = app(ScheduleRunRecorder::class);
    $run = $recorder->queueManualRun('missing:key', 'missing task', null, 1, 'Ops Operator');

    expect(fn () => runScheduledTaskJobHandle('missing:key', $run->id, $recorder))
        ->toThrow(ScheduledTaskExecutionException::class);

    $run->refresh();

    expect($run->status)->toBe('failed')
        ->and($run->output_excerpt)->toContain('no longer registered')
        ->and($run->finished_at)->not->toBeNull();
});

test('the job records an explicit skip reason for a suppressed task', function (): void {
    $event = runScheduledTaskJobEvent();
    $recorder = app(ScheduleRunRecorder::class);
    $key = $recorder->key($event);

    ScheduleSuppression::query()->create(['source' => 'scheduler', 'key' => $key, 'name' => 'inspire']);

    $run = $recorder->queueManualRun($key, 'inspire', null, 1, 'Ops Operator');

    runScheduledTaskJobHandle($key, $run->id, $recorder);

    $run->refresh();

    expect($run->status)->toBe('skipped')
        ->and($run->output_excerpt)->toContain('currently paused');
});

test('the job records an explicit skip reason for overlap protection', function (): void {
    $event = runScheduledTaskJobEvent()->withoutOverlapping();
    $recorder = app(ScheduleRunRecorder::class);
    $key = $recorder->key($event);

    // Simulate a concurrent run already holding the mutex.
    $event->mutex->create($event);

    $run = $recorder->queueManualRun($key, 'inspire', null, 1, 'Ops Operator');

    runScheduledTaskJobHandle($key, $run->id, $recorder);

    $run->refresh();

    expect($run->status)->toBe('skipped')
        ->and($run->output_excerpt)->toContain('overlap protection');
});

test('a worker recovering after its queued row was superseded records a separate run instead of erasing the supersede', function (): void {
    $event = runScheduledTaskJobEvent();
    $recorder = app(ScheduleRunRecorder::class);
    $key = $recorder->key($event);

    $run = $recorder->queueManualRun($key, 'inspire', null, 1, 'Ops Operator');
    $recorder->supersedeActiveRun($key, 'Second Operator');
    $run->refresh();

    expect($run->status)->toBe('failed')
        ->and($run->output_excerpt)->toContain('Superseded by a new manual run requested by Second Operator')
        ->and($run->finished_at)->not->toBeNull();

    // A slow worker finally reaches the ORIGINAL job, still carrying the
    // now-terminal row's id attached to the event (#407 review, fable).
    $recorder->attachRun($event, $run->id);
    $recorder->taskStarting(new ScheduledTaskStarting($event));

    $run->refresh();

    // The superseded row must be untouched — not flipped back to running,
    // not stripped of who superseded it and why.
    expect($run->status)->toBe('failed')
        ->and($run->output_excerpt)->toContain('Superseded by a new manual run requested by Second Operator')
        ->and($run->finished_at)->not->toBeNull();

    // The recovered execution gets its own row instead of vanishing —
    // still honestly attributed to the same manual request.
    expect(ScheduleRun::query()->where('key', $key)->count())->toBe(2);

    $recovered = ScheduleRun::query()->where('key', $key)->where('id', '!=', $run->id)->sole();

    expect($recovered->status)->toBe('running')
        ->and($recovered->trigger)->toBe('manual')
        ->and($recovered->triggered_by_name)->toBe('Ops Operator');
});

test('a worker recovering after its queued row was reconciled as stale records a separate run', function (): void {
    $event = runScheduledTaskJobEvent();
    $recorder = app(ScheduleRunRecorder::class);
    $key = $recorder->key($event);

    $run = $recorder->queueManualRun($key, 'inspire', null, 1, 'Ops Operator');
    $run->forceFill(['started_at' => now()->subMinutes(ScheduleRunRecorder::QUEUE_PICKUP_STALE_AFTER_MINUTES + 1)])->save();

    expect($recorder->hasActiveRun($key))->toBeFalse();

    $run->refresh();
    expect($run->status)->toBe('failed');

    $recorder->attachRun($event, $run->id);
    $recorder->taskStarting(new ScheduledTaskStarting($event));

    $run->refresh();
    expect($run->status)->toBe('failed');

    expect(ScheduleRun::query()->where('key', $key)->where('status', 'running')->count())->toBe(1);
});
