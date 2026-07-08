<?php

use App\Base\Scheduling\Contracts\SchedulingContributor;
use App\Base\Scheduling\DTO\RecordedRun;
use App\Base\Scheduling\DTO\UpcomingRun;
use App\Base\Scheduling\Models\ScheduleRun;
use App\Base\Scheduling\Services\ScheduleRunRecorder;
use App\Base\Scheduling\Services\SchedulingBoard;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
            return [new UpcomingRun('ai-agent', 'weekly digest', '0 9 * * 1', now()->addMinute(), 'succeeded')];
        }

        public function recentRuns(int $limit): array
        {
            return [new RecordedRun('ai-agent', 'weekly digest', 'succeeded', now()->subHour(), now()->subHour()->addMinutes(2), 'ok')];
        }
    };
    app()->instance('scheduling-test-contributor', $contributor);
    app()->tag(['scheduling-test-contributor'], 'scheduling.contributors');

    schedulingTestEvent();

    $board = app(SchedulingBoard::class);
    $upcoming = $board->upcoming();

    $times = collect($upcoming)->map(fn (UpcomingRun $run): int => $run->nextRunAt?->timestamp ?? PHP_INT_MAX);

    expect(collect($upcoming)->pluck('name'))->toContain('weekly digest')
        ->and($times->values()->all())->toBe($times->sort()->values()->all()); // soonest first

    expect(collect($board->recentRuns())->pluck('name'))->toContain('weekly digest');
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
