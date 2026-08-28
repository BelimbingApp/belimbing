<?php

use App\Base\Schedule\Exceptions\ScheduledTaskExecutionException;
use App\Base\Schedule\Jobs\RunScheduledTaskJob;
use App\Base\Schedule\Models\ScheduleRun;
use App\Base\Schedule\Models\ScheduleSuppression;
use App\Base\Schedule\Services\ScheduleRunRecorder;
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
