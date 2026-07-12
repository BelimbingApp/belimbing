<?php

use App\Base\Schedule\Contracts\ScheduleContributor;
use App\Base\Schedule\DTO\RecordedRun;
use App\Base\Schedule\DTO\ScheduleTask;
use App\Base\Schedule\Jobs\RunScheduledTaskJob;
use App\Base\Schedule\Livewire\Index;
use App\Base\Schedule\Models\ScheduleRun;
use App\Base\Schedule\Models\ScheduleSuppression;
use App\Base\Schedule\Services\ScheduleBoard;
use App\Base\Schedule\Services\ScheduleRunRecorder;
use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\AI\Enums\OperationStatus;
use App\Modules\Core\AI\Enums\OperationType;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\Scheduling\ScheduleDefinitionContributor;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;

uses(RefreshDatabase::class);

const SCHEDULE_DIGEST_NAME = 'weekly digest';
const SCHEDULE_TEST_TASK_ALPHA = 'UI Alpha schedule';
const SCHEDULE_TEST_TASK_BETA = 'UI Beta schedule';
const SCHEDULE_TEST_TASK_PAUSED = 'UI Paused schedule';
const SCHEDULE_TEST_HISTORY_ALPHA = 'UI History Alpha';
const SCHEDULE_TEST_HISTORY_BETA = 'UI History Beta';
const SCHEDULE_TEST_CANCELLED_DETAIL = 'Operator cancelled the run.';

beforeEach(function (): void {
    setupAuthzRoles();
});

test('local runtime launchers keep the Laravel scheduler alive', function (): void {
    $package = json_decode(file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);
    $windowsStart = file_get_contents(base_path('scripts/start-app.ps1'));
    $windowsStop = file_get_contents(base_path('scripts/stop-app.ps1'));

    expect($package['scripts']['dev:all'])->toContain('php artisan schedule:work')
        ->and($package['scripts']['dev:all:watch'])->toContain('php artisan schedule:work')
        ->and($windowsStart)->toContain("-Name 'Scheduler'")
        ->and($windowsStart)->toContain("'schedule:work'")
        ->and($windowsStop)->toContain("-Name 'Scheduler'")
        ->and($windowsStop)->toContain("'schedule:work'");
});

function scheduleTestEvent(): Event
{
    $event = app(Schedule::class)->command('inspire')->description('inspire');
    $event->exitCode = 0;

    return $event;
}

test('scheduler events record a run with status and duration', function (): void {
    $recorder = app(ScheduleRunRecorder::class);
    $event = scheduleTestEvent();
    $outputPath = storage_path('framework/testing/schedule-output.txt');

    if (! is_dir(dirname($outputPath))) {
        mkdir(dirname($outputPath), 0777, true);
    }

    file_put_contents($outputPath, 'scheduler output');
    $event->sendOutputTo($outputPath);

    $recorder->taskStarting(new ScheduledTaskStarting($event));

    expect(ScheduleRun::query()->where('status', 'running')->count())->toBe(1);

    $recorder->taskFinished(new ScheduledTaskFinished($event, 1.2));

    $run = ScheduleRun::query()->firstOrFail();

    expect($run->status)->toBe('succeeded')
        ->and($run->key)->toContain('inspire')
        ->and($run->name)->toContain('inspire')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->runtime_ms)->toBe(1200)
        ->and($run->output_excerpt)->toBe('scheduler output');
});

test('scheduler identity fields stay within storage limits', function (): void {
    $recorder = app(ScheduleRunRecorder::class);
    $event = app(Schedule::class)->command('inspire '.str_repeat('x', 320));

    $recorder->taskStarting(new ScheduledTaskStarting($event));

    $run = ScheduleRun::query()->sole();

    expect(mb_strlen($run->key))->toBeLessThanOrEqual(255)
        ->and(mb_strlen($run->name))->toBeLessThanOrEqual(255)
        ->and($run->key)->toContain(':');
});

test('failed after finished enriches the same scheduler run row', function (): void {
    $recorder = app(ScheduleRunRecorder::class);
    $event = scheduleTestEvent();
    $event->exitCode = 1;

    $recorder->taskStarting(new ScheduledTaskStarting($event));
    $recorder->taskFinished(new ScheduledTaskFinished($event, 0.4));
    $recorder->taskFailed(new ScheduledTaskFailed($event, new RuntimeException('Scheduled command failed with exit code [1].')));

    expect(ScheduleRun::query()->count())->toBe(1);

    $run = ScheduleRun::query()->sole();

    expect($run->status)->toBe('failed')
        ->and($run->exit_code)->toBe(1)
        ->and($run->output_excerpt)->toContain('exit code [1].');
});

test('skipped scheduler events create a skipped run row', function (): void {
    $recorder = app(ScheduleRunRecorder::class);
    $event = scheduleTestEvent();

    $recorder->taskSkipped(new ScheduledTaskSkipped($event));

    $run = ScheduleRun::query()->sole();

    expect($run->status)->toBe('skipped')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->name)->toContain('inspire');
});

test('overlap finished events record skipped instead of succeeded', function (): void {
    $recorder = app(ScheduleRunRecorder::class);
    $event = scheduleTestEvent();
    $event->skippedBecauseOverlapping = true;
    $event->exitCode = null;

    $recorder->taskStarting(new ScheduledTaskStarting($event));
    $recorder->taskFinished(new ScheduledTaskFinished($event, 0.01));

    $run = ScheduleRun::query()->sole();

    expect($run->status)->toBe('skipped')
        ->and($run->exit_code)->toBeNull();
});

test('the board merges scheduler events with tagged contributors, soonest first', function (): void {
    $contributor = new class implements ScheduleContributor
    {
        public function tasks(): array
        {
            return [new ScheduleTask('ai-agent', 'ai-agent:weekly-digest', SCHEDULE_DIGEST_NAME, '0 9 * * 1', now()->addMinute(), 'succeeded')];
        }

        public function recentRuns(int $limit): array
        {
            return [new RecordedRun('ai-agent', SCHEDULE_DIGEST_NAME, 'succeeded', now()->subHour(), now()->subHour()->addMinutes(2), 'ok')];
        }
    };
    app()->instance('schedule-test-contributor', $contributor);
    app()->tag(['schedule-test-contributor'], 'schedule.contributors');

    scheduleTestEvent();

    $board = app(ScheduleBoard::class);
    $tasks = $board->tasks();

    $times = collect($tasks)->map(fn (ScheduleTask $task): int => $task->nextRunAt?->timestamp ?? PHP_INT_MAX);

    expect(collect($tasks)->pluck('name'))->toContain(SCHEDULE_DIGEST_NAME)
        ->and($times->values()->all())->toBe($times->sort()->values()->all()); // soonest first

    expect(collect($board->recentRuns())->pluck('name'))->toContain(SCHEDULE_DIGEST_NAME);
});

test('the board accepts scheduler timezone objects', function (): void {
    $event = scheduleTestEvent();
    $event->timezone(new DateTimeZone('Asia/Kuala_Lumpur'));

    $task = collect(app(ScheduleBoard::class)->tasks())
        ->first(fn (ScheduleTask $task): bool => str_contains($task->name, 'inspire'));

    expect($task)->not->toBeNull()
        ->and($task->nextRunAt)->not->toBeNull();
});

test('pausing a scheduler entry suppresses it at tick time; resuming clears it', function (): void {
    $this->actingAs(createAdminUser());

    $event = scheduleTestEvent();
    $recorder = app(ScheduleRunRecorder::class);
    $key = $recorder->key($event);
    $name = $recorder->name($event);

    Livewire\Livewire::test(Index::class)
        ->call('pause', $key, $name);

    expect(ScheduleSuppression::query()->where('key', $key)->where('name', $name)->exists())->toBeTrue();

    // The CommandStarting hook attaches a skip filter to the suppressed entry.
    event(new CommandStarting(
        'schedule:run',
        new ArgvInput([]),
        new NullOutput,
    ));

    expect($event->filtersPass(app()))->toBeFalse();

    // The board flags it paused; resume clears the suppression.
    $paused = collect(app(ScheduleBoard::class)->tasks())->firstWhere('key', $key);

    expect($paused->paused)->toBeTrue();

    Livewire\Livewire::test(Index::class)
        ->call('resume', $key);

    expect(ScheduleSuppression::query()->where('key', $key)->exists())->toBeFalse();
    expect($event->filtersPass(app()))->toBeTrue();
});

test('admin can queue a scheduler task to run now', function (): void {
    Queue::fake();
    $this->actingAs(createAdminUser());

    $event = scheduleTestEvent();
    $key = app(ScheduleRunRecorder::class)->key($event);

    Livewire\Livewire::test(Index::class)
        ->call('runNow', $key);

    Queue::assertPushed(RunScheduledTaskJob::class, fn (RunScheduledTaskJob $job): bool => $job->key === $key);
});

test('admin can save schedule history retention', function (): void {
    $this->actingAs(createAdminUser());

    $component = Livewire\Livewire::test(Index::class)
        ->assertSee('Schedule history retention')
        ->assertSee('How long completed schedule runs stay');

    expect($component->html())
        ->not->toContain('wire:submit="saveRetention"')
        ->not->toContain('wire:click="saveRetention"');

    $component
        ->set('keepDays', '14')
        ->call('saveRetention');

    expect(app(SettingsService::class)->get('schedule.history.keep_days'))->toBe(14);

    Livewire\Livewire::test(Index::class)
        ->call('saveField', 'keepDays', '21');

    expect(app(SettingsService::class)->get('schedule.history.keep_days'))->toBe(21);
});

test('admin sees the schedule page; others are denied', function (): void {
    ScheduleRun::query()->create([
        'source' => 'scheduler',
        'key' => 'investment:radar-scan',
        'name' => 'investment:radar-scan',
        'status' => 'succeeded',
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour()->addSeconds(42),
        'output_excerpt' => 'Universe 416 | passed filters 180',
    ]);

    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.schedule.index'))
        ->assertOk()
        ->assertSee('Schedule')
        ->assertSee('Tasks')
        ->assertSee('History')
        ->assertSee('Settings')
        ->assertSee('Status')
        ->assertSee('Last run')
        ->assertSee('Result')
        ->assertSee('investment:radar-scan');

    auth()->logout();

    $this->get(route('admin.system.schedule.index'))->assertRedirect();

    $user = User::factory()->create([
        'company_id' => Company::factory()->create()->id,
    ]);

    $this->actingAs($user)
        ->get(route('admin.system.schedule.index'))
        ->assertForbidden();
});

test('schedule tasks can be searched filtered sorted and explained', function (): void {
    $now = now()->startOfMinute();
    $contributor = new class($now) implements ScheduleContributor
    {
        public function __construct(private readonly Carbon $now) {}

        public function tasks(): array
        {
            return [
                new ScheduleTask('test', 'ui-alpha', SCHEDULE_TEST_TASK_ALPHA, '* * * * *', $this->now->copy()->addMinutes(2), 'succeeded'),
                new ScheduleTask('test', 'ui-beta', SCHEDULE_TEST_TASK_BETA, '0 9 * * *', $this->now->copy()->addHour(), 'failed'),
                new ScheduleTask('test', 'ui-paused', SCHEDULE_TEST_TASK_PAUSED, '*/15 * * * *', $this->now->copy()->addMinutes(15), paused: true),
            ];
        }

        public function recentRuns(int $limit): array
        {
            return [];
        }
    };

    app()->instance('schedule-test-ui-contributor', $contributor);
    app()->tag(['schedule-test-ui-contributor'], 'schedule.contributors');

    $this->actingAs(createAdminUser());

    Livewire\Livewire::test(Index::class)
        ->assertSee('Cron is read-only on this board')
        ->assertSee('Every minute')
        ->assertSee('Daily at 09:00')
        ->set('taskSearch', 'UI')
        ->call('sortTasks', 'name')
        ->assertViewHas('tasks', function (array $tasks): bool {
            return collect($tasks)->pluck('name')->values()->all() === [
                SCHEDULE_TEST_TASK_ALPHA,
                SCHEDULE_TEST_TASK_BETA,
                SCHEDULE_TEST_TASK_PAUSED,
            ];
        })
        ->call('sortTasks', 'name')
        ->assertViewHas('tasks', function (array $tasks): bool {
            return collect($tasks)->pluck('name')->values()->all() === [
                SCHEDULE_TEST_TASK_PAUSED,
                SCHEDULE_TEST_TASK_BETA,
                SCHEDULE_TEST_TASK_ALPHA,
            ];
        })
        ->set('taskSearch', 'beta')
        ->assertViewHas('tasks', fn (array $tasks): bool => collect($tasks)->pluck('name')->values()->all() === [SCHEDULE_TEST_TASK_BETA])
        ->set('taskSearch', 'UI')
        ->set('taskStatus', 'paused')
        ->assertViewHas('tasks', fn (array $tasks): bool => collect($tasks)->pluck('name')->values()->all() === [SCHEDULE_TEST_TASK_PAUSED]);
});

test('schedule history can be searched filtered sorted and paginated', function (): void {
    $now = now()->startOfSecond();

    foreach (range(1, 12) as $index) {
        ScheduleRun::query()->create([
            'source' => 'scheduler',
            'key' => 'ui-history-page-'.$index,
            'name' => sprintf('UI History Page %02d', $index),
            'status' => 'succeeded',
            'started_at' => $now->copy()->subMinutes($index),
            'finished_at' => $now->copy()->subMinutes($index)->addSeconds(5),
            'output_excerpt' => 'paged',
        ]);
    }

    ScheduleRun::query()->create([
        'source' => 'scheduler',
        'key' => 'ui-history-alpha',
        'name' => SCHEDULE_TEST_HISTORY_ALPHA,
        'status' => 'succeeded',
        'started_at' => $now->copy()->subHour(),
        'finished_at' => $now->copy()->subHour()->addSeconds(42),
        'output_excerpt' => 'alpha detail',
    ]);

    ScheduleRun::query()->create([
        'source' => 'scheduler',
        'key' => 'ui-history-beta',
        'name' => SCHEDULE_TEST_HISTORY_BETA,
        'status' => 'failed',
        'started_at' => $now->copy()->subDays(2),
        'finished_at' => $now->copy()->subDays(2)->addMinutes(2),
        'output_excerpt' => 'beta detail',
    ]);

    ScheduleRun::query()->create([
        'source' => 'scheduler',
        'key' => 'ui-history-gamma',
        'name' => 'UI History Gamma',
        'status' => 'skipped',
        'started_at' => $now->copy()->subDays(10),
        'finished_at' => $now->copy()->subDays(10)->addSecond(),
        'output_excerpt' => 'gamma detail',
    ]);

    $this->actingAs(createAdminUser());

    Livewire\Livewire::test(Index::class)
        ->assertSee('Started (Duration)')
        ->assertSee('Rows per page')
        ->assertViewHas('runs', fn (LengthAwarePaginator $runs): bool => $runs->total() === 15 && $runs->perPage() === 25)
        ->set('historySearch', 'beta')
        ->assertViewHas('runs', fn (LengthAwarePaginator $runs): bool => collect($runs->items())->pluck('name')->values()->all() === [SCHEDULE_TEST_HISTORY_BETA])
        ->set('historySearch', '')
        ->set('historyStatus', 'failed')
        ->assertViewHas('runs', fn (LengthAwarePaginator $runs): bool => collect($runs->items())->pluck('name')->values()->all() === [SCHEDULE_TEST_HISTORY_BETA])
        ->set('historyStatus', 'all')
        ->set('from', $now->copy()->subDays(3)->toDateString())
        ->assertViewHas('runs', function (LengthAwarePaginator $runs): bool {
            $names = collect($runs->items())->pluck('name');

            return $names->contains(SCHEDULE_TEST_HISTORY_ALPHA)
                && $names->contains(SCHEDULE_TEST_HISTORY_BETA)
                && ! $names->contains('UI History Gamma');
        })
        ->assertSet('period', 'custom')
        ->set('period', 'last_90_days')
        ->set('period', 'custom')
        ->assertSet('periodRangeModalOpen', true)
        ->call('cancelPeriodRangeModal')
        ->assertSet('period', 'last_90_days')
        ->assertSet('periodRangeModalOpen', false)
        ->set('perPage', 10)
        ->assertViewHas('runs', fn (LengthAwarePaginator $runs): bool => $runs->perPage() === 10 && $runs->count() === 10 && $runs->total() === 15)
        ->call('sortHistory', 'name')
        ->assertSet('historySort', 'name')
        ->assertSet('historySortDirection', 'asc')
        ->assertViewHas('runs', fn (LengthAwarePaginator $runs): bool => collect($runs->items())->first()->name === SCHEDULE_TEST_HISTORY_ALPHA);
});

test('schedule page labels cancelled contributor runs honestly', function (): void {
    $startedAt = now()->subMinutes(10)->startOfSecond();
    $finishedAt = now()->subMinutes(6)->startOfSecond();

    OperationDispatch::query()->create([
        'id' => 'op_schedule_cancelled',
        'operation_type' => OperationType::ScheduledTask,
        'task' => 'Prepare agent digest',
        'status' => OperationStatus::Cancelled,
        'error_message' => SCHEDULE_TEST_CANCELLED_DETAIL,
        'meta' => ['schedule_description' => 'Agent digest'],
        'started_at' => $startedAt,
        'finished_at' => $finishedAt,
    ]);

    $run = collect(app(ScheduleDefinitionContributor::class)->recentRuns(50))
        ->firstWhere('name', 'Agent digest');

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe('cancelled')
        ->and($run->startedAt->equalTo($startedAt))->toBeTrue()
        ->and($run->finishedAt?->equalTo($finishedAt))->toBeTrue()
        ->and($run->detail)->toBe(SCHEDULE_TEST_CANCELLED_DETAIL);

    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.schedule.index'))
        ->assertOk()
        ->assertSee('Cancelled')
        ->assertSee(SCHEDULE_TEST_CANCELLED_DETAIL);
});

test('old schedule urls are not kept as compatibility routes', function (): void {
    $this->get('/admin/system/scheduling')
        ->assertNotFound();

    $this->get('/admin/system/scheduled-tasks')
        ->assertNotFound();
});
