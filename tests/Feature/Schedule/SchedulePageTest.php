<?php

use App\Base\Schedule\Contracts\ScheduleContributor;
use App\Base\Schedule\DTO\RecordedRun;
use App\Base\Schedule\DTO\ScheduleHistoryPage;
use App\Base\Schedule\DTO\ScheduleHistoryQuery;
use App\Base\Schedule\DTO\ScheduleTask;
use App\Base\Schedule\Jobs\RunScheduledTaskJob;
use App\Base\Schedule\Livewire\Index;
use App\Base\Schedule\Models\ScheduleRun;
use App\Base\Schedule\Models\ScheduleSuppression;
use App\Base\Schedule\Services\ScheduleBoard;
use App\Base\Schedule\Services\ScheduleRunRecorder;
use App\Base\Settings\Contracts\SettingsService;
use App\Core\AI\Enums\OperationStatus;
use App\Core\AI\Enums\OperationType;
use App\Core\AI\Models\OperationDispatch;
use App\Core\AI\Services\Scheduling\ScheduleDefinitionContributor;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\ScheduleHealthFixtures;

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

function scheduleTestEventKey(): string
{
    return app(ScheduleRunRecorder::class)->key(scheduleTestEvent());
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

        public function history(ScheduleHistoryQuery $query, int $limit): ScheduleHistoryPage
        {
            return new ScheduleHistoryPage(
                [new RecordedRun('ai-agent', SCHEDULE_DIGEST_NAME, 'succeeded', now()->subHour(), now()->subHour()->addMinutes(2), 'ok')],
                1,
                true,
            );
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

    $query = new ScheduleHistoryQuery(now()->subDays(30), now(), 'all', '', 'started_at', 'desc');

    expect(collect($board->history($query, 25, 1)->items)->pluck('name'))->toContain(SCHEDULE_DIGEST_NAME);
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
    $admin = createAdminUser();
    $this->actingAs($admin);

    $key = scheduleTestEventKey();

    Livewire\Livewire::test(Index::class)
        ->call('runNow', $key);

    // The queued row must exist before dispatch returns — that's the whole
    // point (#401): a job that never reaches the worker still leaves an
    // honest, durable state instead of nothing.
    $run = ScheduleRun::query()->where('key', $key)->sole();

    expect($run->status)->toBe('queued')
        ->and($run->trigger)->toBe('manual')
        ->and($run->triggered_by_user_id)->toBe($admin->id)
        ->and($run->triggered_by_name)->toBe($admin->name);

    Queue::assertPushed(RunScheduledTaskJob::class, fn (RunScheduledTaskJob $job): bool => $job->key === $key && $job->runId === $run->id);
});

test('running now for an unregistered key notifies an error and dispatches nothing', function (): void {
    Queue::fake();
    $this->actingAs(createAdminUser());

    Livewire\Livewire::test(Index::class)
        ->call('runNow', 'no-such-scheduler-key')
        ->assertDispatched('notify', variant: 'error');

    Queue::assertNotPushed(RunScheduledTaskJob::class);
    expect(ScheduleRun::query()->count())->toBe(0);
});

test('running now while a run is already queued or running is refused rather than stacked', function (): void {
    Queue::fake();
    $this->actingAs(createAdminUser());

    $key = scheduleTestEventKey();

    Livewire\Livewire::test(Index::class)->call('runNow', $key);

    expect(ScheduleRun::query()->where('key', $key)->count())->toBe(1);

    Livewire\Livewire::test(Index::class)
        ->call('runNow', $key)
        ->assertDispatched('notify', variant: 'warning');

    // Still exactly one row and one dispatched job — the second click did
    // not stack a duplicate manual run (#401's dedupe requirement).
    expect(ScheduleRun::query()->where('key', $key)->count())->toBe(1);
    Queue::assertPushed(RunScheduledTaskJob::class, 1);
});

test('a queued row a worker never picked up is reconciled to failed instead of locking the control forever', function (): void {
    Queue::fake();
    $this->actingAs(createAdminUser());

    $key = scheduleTestEventKey();

    ScheduleRun::query()->create([
        'source' => 'scheduler',
        'trigger' => 'manual',
        'key' => $key,
        'name' => 'inspire',
        'status' => 'queued',
        'started_at' => now()->subMinutes(ScheduleRunRecorder::QUEUE_PICKUP_STALE_AFTER_MINUTES + 1),
    ]);

    expect(app(ScheduleRunRecorder::class)->hasActiveRun($key))->toBeFalse();

    $stale = ScheduleRun::query()->where('key', $key)->sole();

    expect($stale->status)->toBe('failed')
        ->and($stale->output_excerpt)->toContain('no worker picked it up');

    // The reconciliation unblocked it — a fresh manual run can proceed.
    Livewire\Livewire::test(Index::class)->call('runNow', $key);

    expect(ScheduleRun::query()->where('key', $key)->count())->toBe(2);
    Queue::assertPushed(RunScheduledTaskJob::class, 1);
});

test('a running row is never auto-reconciled no matter how old, to avoid declaring a healthy long task dead', function (): void {
    $key = scheduleTestEventKey();

    ScheduleRun::query()->create([
        'source' => 'scheduler',
        'trigger' => 'manual',
        'key' => $key,
        'name' => 'inspire',
        'status' => 'running',
        'started_at' => now()->subHours(6),
    ]);

    expect(app(ScheduleRunRecorder::class)->hasActiveRun($key))->toBeTrue();

    $run = ScheduleRun::query()->where('key', $key)->sole();
    expect($run->status)->toBe('running');
});

test('forcing a run refuses to supersede an active run that is not yet stale', function (): void {
    Queue::fake();
    $this->actingAs(createAdminUser());

    $key = scheduleTestEventKey();

    ScheduleRun::query()->create([
        'source' => 'scheduler',
        'trigger' => 'manual',
        'key' => $key,
        'name' => 'inspire',
        'status' => 'running',
        'started_at' => now(),
    ]);

    Livewire\Livewire::test(Index::class)
        ->call('runNow', $key, true)
        ->assertDispatched('notify', variant: 'warning');

    $run = ScheduleRun::query()->where('key', $key)->sole();
    expect($run->status)->toBe('running');
    Queue::assertNotPushed(RunScheduledTaskJob::class);
});

test('a capable operator can force-run a stuck row, which is marked superseded by name rather than discarded', function (): void {
    Queue::fake();
    $admin = createAdminUser();
    $this->actingAs($admin);

    $key = scheduleTestEventKey();

    $stuck = ScheduleRun::query()->create([
        'source' => 'scheduler',
        'trigger' => 'manual',
        'key' => $key,
        'name' => 'inspire',
        'status' => 'running',
        'started_at' => now()->subMinutes(ScheduleRunRecorder::QUEUE_PICKUP_STALE_AFTER_MINUTES + 1),
    ]);

    Livewire\Livewire::test(Index::class)
        ->call('runNow', $key, true)
        ->assertDispatched('notify');

    $stuck->refresh();

    expect($stuck->status)->toBe('failed')
        ->and($stuck->output_excerpt)->toContain('Superseded by a new manual run requested by '.$admin->name);

    $fresh = ScheduleRun::query()->where('key', $key)->where('status', 'queued')->sole();
    expect($fresh->id)->not->toBe($stuck->id);

    Queue::assertPushed(RunScheduledTaskJob::class, fn (RunScheduledTaskJob $job): bool => $job->runId === $fresh->id);
});

test('the Force run control appears only once an active run looks stuck', function (): void {
    $this->actingAs(createAdminUser());

    $key = scheduleTestEventKey();

    ScheduleRun::query()->create([
        'source' => 'scheduler',
        'trigger' => 'manual',
        'key' => $key,
        'name' => 'inspire',
        'status' => 'running',
        'started_at' => now(),
    ]);

    // Fresh: neither the normal Play control nor Force run should show.
    // Asserted against each control's accessible label, which is
    // task-specific and Blade-echoed — unlike wire:click, which @js()
    // renders identically for every row regardless of key, so it can't
    // isolate one row's control from any other real scheduler task also
    // registered in this test's full application boot.
    Livewire\Livewire::test(Index::class)
        ->assertDontSee('Run inspire now')
        ->assertDontSee('Force run inspire');

    ScheduleRun::query()->where('key', $key)->update([
        'started_at' => now()->subMinutes(ScheduleRunRecorder::QUEUE_PICKUP_STALE_AFTER_MINUTES + 1),
    ]);

    Livewire\Livewire::test(Index::class)
        ->assertDontSee('Run inspire now')
        ->assertSee('Force run inspire (currently running, unresponsive for over '.ScheduleRunRecorder::QUEUE_PICKUP_STALE_AFTER_MINUTES.' minutes)');
});

test('a dispatch failure marks the queued row failed and notifies rather than leaving it stuck', function (): void {
    $this->actingAs(createAdminUser());

    $key = scheduleTestEventKey();

    // An unresolvable queue connection makes dispatch() itself throw.
    config(['queue.default' => 'schedule-test-missing-connection']);

    Livewire\Livewire::test(Index::class)
        ->call('runNow', $key)
        ->assertDispatched('notify', variant: 'error');

    $run = ScheduleRun::query()->where('key', $key)->sole();

    expect($run->status)->toBe('failed')
        ->and($run->finished_at)->not->toBeNull();
});

test('the Run now control disappears while a task is queued or running', function (): void {
    $this->actingAs(createAdminUser());

    $key = scheduleTestEventKey();

    ScheduleRun::query()->create([
        'source' => 'scheduler',
        'trigger' => 'manual',
        'key' => $key,
        'name' => 'inspire',
        'status' => 'queued',
        'started_at' => now(),
    ]);

    // The play action's accessible label is task-specific and Blade-echoed
    // (unlike its wire:click, which @js() renders identically for every
    // row regardless of key — not a reliable way to isolate one row's
    // control), so assert on that instead of the wire:click markup.
    Livewire\Livewire::test(Index::class)
        ->assertSee('Queued')
        ->assertDontSee('Run inspire now');
});

test('manually triggered history rows are labelled with who ran them', function (): void {
    $admin = createAdminUser();

    ScheduleRun::query()->create([
        'source' => 'scheduler',
        'trigger' => 'manual',
        'triggered_by_user_id' => $admin->id,
        'triggered_by_name' => $admin->name,
        'key' => 'ui-manual-run',
        'name' => 'UI Manual Run',
        'status' => 'succeeded',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    $this->actingAs($admin);

    $this->get(route('admin.system.schedule.index'))
        ->assertOk()
        ->assertSee('UI Manual Run')
        ->assertSee('Run now by '.$admin->name);
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

        public function history(ScheduleHistoryQuery $query, int $limit): ScheduleHistoryPage
        {
            return new ScheduleHistoryPage([], 0, false);
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

    $run = collect(app(ScheduleDefinitionContributor::class)->history(
        new ScheduleHistoryQuery(now()->subDays(30), now(), 'all', '', 'started_at', 'desc'),
        50,
    )->items)
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

test('AI contributor filters and limits its database projection without materializing retained dispatches', function (): void {
    $now = now()->startOfSecond();

    foreach (range(1, 600) as $index) {
        OperationDispatch::query()->create([
            'id' => 'op_schedule_noisy_'.$index,
            'operation_type' => OperationType::ScheduledTask,
            'task' => 'Noisy schedule payload '.$index,
            'status' => OperationStatus::Succeeded,
            'meta' => ['schedule_description' => 'Noisy schedule '.$index],
            'started_at' => $now->copy()->subSeconds($index),
            'finished_at' => $now->copy()->subSeconds($index)->addSecond(),
        ]);
    }

    OperationDispatch::query()->create([
        'id' => 'op_schedule_needle',
        'operation_type' => OperationType::HeadlessTask,
        'task' => 'Needle payload',
        'status' => OperationStatus::Failed,
        'meta' => ['schedule_description' => 'Needle schedule'],
        'started_at' => $now->copy()->subDays(2),
        'finished_at' => $now->copy()->subDays(2)->addMinute(),
    ]);

    $queries = [];
    DB::listen(function (QueryExecuted $event) use (&$queries): void {
        if (str_contains($event->sql, 'ai_operation_dispatches')) {
            $queries[] = strtolower($event->sql);
        }
    });

    $history = app(ScheduleDefinitionContributor::class)->history(
        new ScheduleHistoryQuery(now()->subDays(30), now(), 'all', 'needle', 'name', 'asc'),
        1,
    );

    $projection = collect($queries)->first(fn (string $sql): bool => str_contains($sql, 'schedule_history_name'));

    expect($history->total)->toBe(1)
        ->and(collect($history->items)->pluck('name')->all())->toBe(['Needle schedule'])
        ->and($projection)->not->toBeNull()
        ->and($projection)->toContain('lower(coalesce')
        ->and($projection)->toMatch('/limit\\s+(?:\\?|1)/');
});

test('AI contributor selects JSON grammar from its active connection driver', function (): void {
    $connection = 'schedule-history-postgres';
    $original = config('database.connections.'.$connection);
    config()->set('database.connections.'.$connection, [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => 5432,
        'database' => 'schedule_history_test',
        'username' => 'testing',
        'password' => '',
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'schema' => 'public',
        'sslmode' => 'prefer',
    ]);

    try {
        $contributor = app(ScheduleDefinitionContributor::class);
        $nameExpression = new ReflectionMethod($contributor, 'historyNameExpression');
        $sourceExpression = new ReflectionMethod($contributor, 'historySourceExpression');
        $scheduleIdExpression = new ReflectionMethod($contributor, 'scheduleIdExpression');
        $scheduleIdComparison = new ReflectionMethod($contributor, 'scheduleIdComparison');
        $driver = OperationDispatch::on($connection)->getQuery()->getConnection()->getDriverName();

        expect($driver)->toBe('pgsql')
            ->and($nameExpression->invoke($contributor, $driver))->toContain("meta->>'source_key'")
            ->and($sourceExpression->invoke($contributor, $driver))->toContain("meta->>'source'")
            ->and($scheduleIdExpression->invoke($contributor, $driver, 'ai_operation_dispatches.meta'))
            ->toContain("ai_operation_dispatches.meta->>'schedule_id'")
            ->and($scheduleIdComparison->invoke($contributor, $driver, "ai_operation_dispatches.meta->>'schedule_id'"))
            ->toBe("ai_operation_dispatches.meta->>'schedule_id' = CAST(ai_schedule_definitions.id AS TEXT)")
            ->and($scheduleIdComparison->invoke($contributor, $driver, 'health_dispatches.schedule_id_value', 'health_definitions.id'))
            ->toBe('health_dispatches.schedule_id_value = CAST(health_definitions.id AS TEXT)');
    } finally {
        DB::purge($connection);
        config()->set('database.connections.'.$connection, $original);
    }
});

test('AI health projection joins a failed dispatch to its definition', function (): void {
    ScheduleHealthFixtures::failedAiSchedule('op_schedule_health_projection');

    $tasks = app(ScheduleDefinitionContributor::class)->unhealthyTasks();

    expect($tasks)->toHaveCount(1)
        ->and($tasks[0]->source)->toBe('core-ai')
        ->and($tasks[0]->name)->toBe('nightly-summary')
        ->and($tasks[0]->consecutiveFailures)->toBe(1);
});

test('AI contributor searches metadata names with literal LIKE metacharacters', function (): void {
    $now = now()->startOfSecond();

    OperationDispatch::query()->create([
        'id' => 'op_schedule_literal_like',
        'operation_type' => OperationType::ScheduledTask,
        'task' => 'Literal search payload',
        'status' => OperationStatus::Succeeded,
        'meta' => ['schedule_description' => 'Report 100%_complete \\ archive'],
        'started_at' => $now->subMinute(),
        'finished_at' => $now,
    ]);
    OperationDispatch::query()->create([
        'id' => 'op_schedule_like_near_match',
        'operation_type' => OperationType::ScheduledTask,
        'task' => 'Near match payload',
        'status' => OperationStatus::Succeeded,
        'meta' => ['schedule_description' => 'Report 100xxcomplete x archive'],
        'started_at' => $now->subMinutes(2),
        'finished_at' => $now->subMinute(),
    ]);

    $history = app(ScheduleDefinitionContributor::class)->history(
        new ScheduleHistoryQuery(now()->subDay(), now(), 'all', 'report 100%_complete \\ archive', 'started_at', 'desc'),
        25,
    );

    expect($history->total)->toBe(1)
        ->and(collect($history->items)->pluck('name')->all())->toBe(['Report 100%_complete \\ archive']);
});

test('history filters apply before truncation so an old failure stays discoverable under high-frequency successes', function (): void {
    $now = now()->startOfSecond();

    // 600 recent successful runs would consume the old fixed 500-row slice.
    foreach (range(1, 600) as $index) {
        ScheduleRun::query()->create([
            'source' => 'scheduler',
            'key' => 'high-frequency-'.$index,
            'name' => 'high frequency task',
            'status' => 'succeeded',
            'started_at' => $now->copy()->subSeconds($index),
            'finished_at' => $now->copy()->subSeconds($index)->addSeconds(1),
        ]);
    }

    // One older failure inside the selected period, outside the newest 500.
    ScheduleRun::query()->create([
        'source' => 'scheduler',
        'key' => 'daily-task',
        'name' => 'daily business task',
        'status' => 'failed',
        'started_at' => $now->copy()->subDays(2),
        'finished_at' => $now->copy()->subDays(2)->addMinutes(2),
    ]);

    $this->actingAs(createAdminUser());

    Livewire\Livewire::test(Index::class)
        ->set('historyStatus', 'failed')
        ->assertViewHas('runs', fn (LengthAwarePaginator $runs): bool => $runs->total() === 1
            && collect($runs->items())->pluck('name')->values()->all() === ['daily business task'])
        ->set('historyStatus', 'all')
        ->set('historySearch', 'daily business')
        ->assertViewHas('runs', fn (LengthAwarePaginator $runs): bool => $runs->total() === 1
            && collect($runs->items())->pluck('name')->values()->all() === ['daily business task']);
});

test('history empty state distinguishes no recorded runs from no matching runs', function (): void {
    $this->actingAs(createAdminUser());

    Livewire\Livewire::test(Index::class)
        ->assertViewHas('historyEmptyMessage', __('No runs recorded yet.'));

    ScheduleRun::query()->create([
        'source' => 'scheduler',
        'key' => 'only-task',
        'name' => 'only task',
        'status' => 'succeeded',
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour()->addSeconds(5),
    ]);

    Livewire\Livewire::test(Index::class)
        ->set('historySearch', 'no such task')
        ->assertViewHas('historyEmptyMessage', __('No runs match the current filters.'));
});

test('scheduler and contributor histories merge without starving each other before filtering', function (): void {
    $now = now()->startOfSecond();

    foreach (range(1, 3) as $index) {
        ScheduleRun::query()->create([
            'source' => 'scheduler',
            'key' => 'scheduler-'.$index,
            'name' => 'scheduler task '.$index,
            'status' => 'succeeded',
            'started_at' => $now->copy()->subMinutes($index),
            'finished_at' => $now->copy()->subMinutes($index)->addSeconds(1),
        ]);
    }

    $contributor = new class implements ScheduleContributor
    {
        public function tasks(): array
        {
            return [];
        }

        public function history(ScheduleHistoryQuery $query, int $limit): ScheduleHistoryPage
        {
            return new ScheduleHistoryPage(
                [
                    new RecordedRun('ai-agent', 'contributor task', 'failed', now()->subMinutes(2), now()->subMinutes(2)->addSeconds(3), 'boom'),
                ],
                1,
                true,
            );
        }
    };
    app()->instance('schedule-merge-contributor', $contributor);
    app()->tag(['schedule-merge-contributor'], 'schedule.contributors');

    $this->actingAs(createAdminUser());

    Livewire\Livewire::test(Index::class)
        ->assertViewHas('runs', fn (LengthAwarePaginator $runs): bool => $runs->total() === 4
            && collect($runs->items())->pluck('name')->contains('contributor task')
            && collect($runs->items())->pluck('name')->contains('scheduler task 1'))
        ->set('historyStatus', 'failed')
        ->assertViewHas('runs', fn (LengthAwarePaginator $runs): bool => $runs->total() === 1
            && collect($runs->items())->pluck('name')->values()->all() === ['contributor task']);
});

test('old schedule urls are not kept as compatibility routes', function (): void {
    $this->get('/admin/system/scheduling')
        ->assertNotFound();

    $this->get('/admin/system/scheduled-tasks')
        ->assertNotFound();
});
