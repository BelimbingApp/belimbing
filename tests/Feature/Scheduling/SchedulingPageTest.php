<?php

use App\Base\Scheduling\Contracts\SchedulingContributor;
use App\Base\Scheduling\DTO\RecordedRun;
use App\Base\Scheduling\DTO\UpcomingRun;
use App\Base\Scheduling\Livewire\Index;
use App\Base\Scheduling\Models\ScheduleRun;
use App\Base\Scheduling\Models\ScheduleSuppression;
use App\Base\Scheduling\Services\ScheduleRunRecorder;
use App\Base\Scheduling\Services\SchedulingBoard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;

uses(RefreshDatabase::class);

const SCHEDULING_DIGEST_NAME = 'weekly digest';

beforeEach(function (): void {
    setupAuthzRoles();
});

function schedulingTestEvent(): Event
{
    $event = app(Schedule::class)->command('inspire')->description('inspire');
    $event->exitCode = 0;

    return $event;
}

test('scheduler events record a run with status and duration', function (): void {
    $recorder = app(ScheduleRunRecorder::class);
    $event = schedulingTestEvent();

    $recorder->taskStarting(new ScheduledTaskStarting($event));

    expect(ScheduleRun::query()->where('status', 'running')->count())->toBe(1);

    $recorder->taskFinished(new ScheduledTaskFinished($event, 1.2));

    $run = ScheduleRun::query()->firstOrFail();

    expect($run->status)->toBe('succeeded')
        ->and($run->name)->toContain('inspire')
        ->and($run->finished_at)->not->toBeNull();
});

test('the board merges scheduler events with tagged contributors, soonest first', function (): void {
    $contributor = new class implements SchedulingContributor
    {
        public function upcoming(): array
        {
            return [new UpcomingRun('ai-agent', SCHEDULING_DIGEST_NAME, '0 9 * * 1', now()->addMinute(), 'succeeded')];
        }

        public function recentRuns(int $limit): array
        {
            return [new RecordedRun('ai-agent', SCHEDULING_DIGEST_NAME, 'succeeded', now()->subHour(), now()->subHour()->addMinutes(2), 'ok')];
        }
    };
    app()->instance('scheduling-test-contributor', $contributor);
    app()->tag(['scheduling-test-contributor'], 'scheduling.contributors');

    schedulingTestEvent();

    $board = app(SchedulingBoard::class);
    $upcoming = $board->upcoming();

    $times = collect($upcoming)->map(fn (UpcomingRun $run): int => $run->nextRunAt?->timestamp ?? PHP_INT_MAX);

    expect(collect($upcoming)->pluck('name'))->toContain(SCHEDULING_DIGEST_NAME)
        ->and($times->values()->all())->toBe($times->sort()->values()->all()); // soonest first

    expect(collect($board->recentRuns())->pluck('name'))->toContain(SCHEDULING_DIGEST_NAME);
});

test('pausing a scheduler entry suppresses it at tick time; resuming clears it', function (): void {
    $this->actingAs(createAdminUser());

    $event = schedulingTestEvent();
    $name = app(ScheduleRunRecorder::class)->name($event);

    Livewire\Livewire::test(Index::class)
        ->call('pause', $name);

    expect(ScheduleSuppression::query()->where('name', $name)->exists())->toBeTrue();

    // The CommandStarting hook attaches a skip filter to the suppressed entry.
    event(new CommandStarting(
        'schedule:run',
        new ArgvInput([]),
        new NullOutput,
    ));

    expect($event->filtersPass(app()))->toBeFalse();

    // The board flags it paused; resume clears the suppression.
    $paused = collect(app(SchedulingBoard::class)->upcoming())->firstWhere('name', $name);

    expect($paused->paused)->toBeTrue();

    Livewire\Livewire::test(Index::class)
        ->call('resume', $name);

    expect(ScheduleSuppression::query()->where('name', $name)->exists())->toBeFalse();
});

test('admin sees the scheduling page; others are denied', function (): void {
    ScheduleRun::query()->create([
        'source' => 'scheduler',
        'name' => 'investment:radar-scan',
        'status' => 'succeeded',
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour()->addSeconds(42),
        'output_excerpt' => 'Universe 416 | passed filters 180',
    ]);

    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.scheduling.index'))
        ->assertOk()
        ->assertSee('Scheduling')
        ->assertSee('Upcoming')
        ->assertSee('Run history')
        ->assertSee('investment:radar-scan');

    auth()->logout();

    $this->get(route('admin.system.scheduling.index'))->assertRedirect();
});
