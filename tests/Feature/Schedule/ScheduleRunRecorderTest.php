<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Schedule\Jobs\RunScheduledTaskJob;
use App\Base\Schedule\Livewire\ScheduledTasks\Index;
use App\Base\Schedule\Models\ScheduleRun;
use App\Base\Schedule\Models\ScheduleRunHistory;
use App\Base\Schedule\Services\ScheduleHistoryPruner;
use App\Base\Schedule\Services\ScheduleRunRecorder;
use App\Base\Schedule\Support\ScheduleRunStatuses;
use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setupAuthzRoles();
});

function makeScheduleRunRecorderTask(string $command): Event
{
    return app(Schedule::class)->command($command)->daily();
}

function createSystemViewerUser(): User
{
    setupAuthzRoles();

    $role = Role::query()->where('code', 'system_viewer')->whereNull('company_id')->firstOrFail();
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);

    return $user;
}

function historyAttrs(array $overrides = []): array
{
    return array_merge([
        'command_key' => 'blb:sample',
        'command' => 'blb:sample',
        'attempt_key' => (string) Str::uuid(),
        'status' => ScheduleRunStatuses::SUCCEEDED,
        'started_at' => now(),
        'finished_at' => now(),
    ], $overrides);
}

test('recorder upserts succeeded runs with output and history', function (): void {
    $recorder = app(ScheduleRunRecorder::class);
    $task = makeScheduleRunRecorderTask('php artisan blb:test --sample');
    $outputPath = storage_path('framework/testing/schedule-run-output.txt');

    if (! is_dir(dirname($outputPath))) {
        mkdir(dirname($outputPath), 0777, true);
    }

    file_put_contents($outputPath, 'scheduler output');
    $task->sendOutputTo($outputPath);
    $task->exitCode = 0;

    $recorder->rememberStarting($task);
    $recorder->rememberFinished($task, 1.234);

    $run = ScheduleRun::query()->sole();
    $history = ScheduleRunHistory::query()->sole();

    expect($run->command_key)->toBe('blb:test --sample')
        ->and($run->attempt_key)->not->toBeNull()
        ->and($run->status)->toBe(ScheduleRunStatuses::SUCCEEDED)
        ->and($run->exit_code)->toBe(0)
        ->and($run->runtime_ms)->toBe(1234)
        ->and($run->output)->toBe('scheduler output')
        ->and($history->status)->toBe(ScheduleRunStatuses::SUCCEEDED)
        ->and($history->attempt_key)->toBe($run->attempt_key)
        ->and($history->output)->toBe('scheduler output');
});

test('recorder keeps one last-run row while history grows across attempts', function (): void {
    $recorder = app(ScheduleRunRecorder::class);
    $task = makeScheduleRunRecorderTask('blb:test --sample');
    $task->exitCode = 0;

    $recorder->rememberStarting($task);
    $recorder->rememberFinished($task, 0.1);
    $firstId = ScheduleRun::query()->sole()->id;

    $recorder->rememberStarting($task);
    $recorder->rememberFinished($task, 0.2);

    expect(ScheduleRun::query()->count())->toBe(1)
        ->and(ScheduleRun::query()->sole()->id)->toBe($firstId)
        ->and(ScheduleRunHistory::query()->count())->toBe(2)
        ->and(ScheduleRunHistory::query()->pluck('attempt_key')->unique()->count())->toBe(2);
});

test('finished then failed for non-zero exit yields one history row', function (): void {
    $recorder = app(ScheduleRunRecorder::class);
    $task = makeScheduleRunRecorderTask('blb:test --fail');
    $task->exitCode = 1;

    $recorder->rememberStarting($task);
    $recorder->rememberFinished($task, 0.4);
    $recorder->rememberFailed($task, new RuntimeException('Scheduled command failed with exit code [1].'));

    expect(ScheduleRunHistory::query()->count())->toBe(1)
        ->and(ScheduleRunHistory::query()->sole()->status)->toBe(ScheduleRunStatuses::FAILED)
        ->and(ScheduleRunHistory::query()->sole()->output)->toContain('exit code [1]')
        ->and(ScheduleRun::query()->sole()->status)->toBe(ScheduleRunStatuses::FAILED);
});

test('filter skip without starting inserts a standalone skipped row', function (): void {
    $recorder = app(ScheduleRunRecorder::class);
    $task = makeScheduleRunRecorderTask('blb:filter-skip');

    $recorder->rememberSkipped($task);

    expect(ScheduleRunHistory::query()->count())->toBe(1)
        ->and(ScheduleRunHistory::query()->sole()->status)->toBe(ScheduleRunStatuses::SKIPPED)
        ->and(ScheduleRun::query()->sole()->status)->toBe(ScheduleRunStatuses::SKIPPED);
});

test('overlap skip after starting completes the running history row', function (): void {
    $recorder = app(ScheduleRunRecorder::class);
    $task = makeScheduleRunRecorderTask('blb:overlap-skip');

    $recorder->rememberStarting($task);
    $task->skippedBecauseOverlapping = true;
    $recorder->rememberSkipped($task);

    expect(ScheduleRunHistory::query()->count())->toBe(1)
        ->and(ScheduleRunHistory::query()->sole()->status)->toBe(ScheduleRunStatuses::SKIPPED)
        ->and(ScheduleRun::query()->sole()->status)->toBe(ScheduleRunStatuses::SKIPPED);
});

test('finished with skippedBecauseOverlapping records skipped not succeeded', function (): void {
    $recorder = app(ScheduleRunRecorder::class);
    $task = makeScheduleRunRecorderTask('blb:overlap-finished');

    $recorder->rememberStarting($task);
    $task->skippedBecauseOverlapping = true;
    $task->exitCode = null;
    $recorder->rememberFinished($task, 0.01);

    expect(ScheduleRunHistory::query()->sole()->status)->toBe(ScheduleRunStatuses::SKIPPED)
        ->and(ScheduleRun::query()->sole()->status)->toBe(ScheduleRunStatuses::SKIPPED);
});

test('attempt keys keep concurrent same-command finishes on the correct rows', function (): void {
    $recorder = app(ScheduleRunRecorder::class);
    $first = makeScheduleRunRecorderTask('blb:concurrent');
    $second = makeScheduleRunRecorderTask('blb:concurrent');

    $firstOut = storage_path('framework/testing/schedule-first.txt');
    $secondOut = storage_path('framework/testing/schedule-second.txt');
    if (! is_dir(dirname($firstOut))) {
        mkdir(dirname($firstOut), 0777, true);
    }
    file_put_contents($firstOut, 'first output');
    file_put_contents($secondOut, 'second output');

    $first->sendOutputTo($firstOut);
    $second->sendOutputTo($secondOut);

    $recorder->rememberStarting($first);
    $recorder->rememberStarting($second);

    $first->exitCode = 0;
    $second->exitCode = 1;

    // Finish out of order: second attempt completes first.
    $recorder->rememberFinished($second, 0.2);
    $recorder->rememberFinished($first, 0.5);

    $rows = ScheduleRunHistory::query()->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->output)->toBe('first output')
        ->and($rows[0]->status)->toBe(ScheduleRunStatuses::SUCCEEDED)
        ->and($rows[1]->output)->toBe('second output')
        ->and($rows[1]->status)->toBe(ScheduleRunStatuses::FAILED)
        ->and($rows[0]->attempt_key)->not->toBe($rows[1]->attempt_key);
});

test('background finished restores deterministic output path', function (): void {
    $recorder = app(ScheduleRunRecorder::class);
    $task = makeScheduleRunRecorderTask('blb:background-out');

    $recorder->rememberStarting($task);
    $path = $recorder->deterministicOutputPath($task);
    file_put_contents($path, "background captured\n");

    // Simulate schedule:finish reconstructing Event with default /dev/null output.
    $finishTask = makeScheduleRunRecorderTask('blb:background-out');
    $finishTask->output = $finishTask->getDefaultOutput();
    $finishTask->exitCode = 0;
    // Mirror last-run attempt_key as the background process would see via DB.
    $last = ScheduleRun::query()->sole();
    expect($last->status)->toBe(ScheduleRunStatuses::RUNNING);

    $recorder->rememberBackgroundFinished($finishTask);

    expect(ScheduleRunHistory::query()->sole()->output)->toBe("background captured\n")
        ->and(ScheduleRunHistory::query()->sole()->status)->toBe(ScheduleRunStatuses::SUCCEEDED);
});

test('recorder folds exception messages into output on failure', function (): void {
    $recorder = app(ScheduleRunRecorder::class);
    $task = makeScheduleRunRecorderTask('blb:test --sample');
    $task->exitCode = 1;

    $recorder->rememberStarting($task);
    $recorder->rememberFailed($task, new RuntimeException(str_repeat('x', 9000)));

    $run = ScheduleRun::query()->sole();

    expect($run->status)->toBe(ScheduleRunStatuses::FAILED)
        ->and(mb_strlen((string) $run->output))->toBe(8000)
        ->and(Schema::hasColumn('base_schedule_runs', 'error'))->toBeFalse();
});

test('scheduled tasks index shows never when no run exists', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->assertOk()
        ->assertSee('Last run')
        ->assertSee('Status')
        ->assertSee('History')
        ->assertSee('Settings')
        ->assertSee('Never');
});

test('scheduled tasks index shows succeeded after recording', function (): void {
    $recorder = app(ScheduleRunRecorder::class);
    $task = makeScheduleRunRecorderTask('commerce:marketplace:ebay:pull --orders');
    $task->exitCode = 0;

    $recorder->rememberFinished($task, 2.0);

    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->assertOk()
        ->assertSee('commerce:marketplace:ebay:pull --orders', false)
        ->assertSee('Succeeded')
        ->assertDontSee('Exit 0')
        ->assertSee('#'.ScheduleRun::query()->sole()->id)
        ->assertSee('Next run')
        ->assertSee('#'.ScheduleRunHistory::query()->sole()->id);
});

test('scheduled tasks index polls while a run is running', function (): void {
    ScheduleRun::query()->create([
        'command_key' => 'commerce:marketplace:ebay:pull --orders',
        'command' => 'commerce:marketplace:ebay:pull --orders',
        'expression' => '0 * * * *',
        'attempt_key' => (string) Str::uuid(),
        'status' => ScheduleRunStatuses::RUNNING,
        'started_at' => now(),
    ]);

    $this->actingAs(createAdminUser());

    $html = Livewire::test(Index::class)
        ->assertOk()
        ->assertSee('Running')
        ->html();

    expect($html)->toContain('wire:poll.3s');
});

test('history tab filters by status and search', function (): void {
    ScheduleRunHistory::query()->create(historyAttrs([
        'command_key' => 'blb:alpha',
        'command' => 'blb:alpha',
        'status' => ScheduleRunStatuses::SUCCEEDED,
        'output' => 'alpha ok',
        'started_at' => now()->subMinute(),
    ]));
    ScheduleRunHistory::query()->create(historyAttrs([
        'command_key' => 'blb:beta',
        'command' => 'blb:beta',
        'status' => ScheduleRunStatuses::FAILED,
        'output' => 'beta boom',
        'started_at' => now()->subMinute(),
    ]));

    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->set('historyStatus', 'failed')
        ->assertSee('blb:beta', false)
        ->assertDontSee('blb:alpha', false)
        ->set('historyStatus', '')
        ->set('historySearch', 'alpha')
        ->assertSee('blb:alpha', false)
        ->assertDontSee('blb:beta', false);
});

test('settings tab persists retention into base_settings', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->call('saveField', 'schedule.history.keep_days', '14')
        ->call('saveField', 'schedule.history.keep_count', '100')
        ->assertHasNoErrors();

    $settings = app(SettingsService::class);

    expect((int) $settings->get('schedule.history.keep_days'))->toBe(14)
        ->and((int) $settings->get('schedule.history.keep_count'))->toBe(100)
        ->and(app(ScheduleHistoryPruner::class)->keepDays())->toBe(14)
        ->and(app(ScheduleHistoryPruner::class)->keepCount())->toBe(100);
});

test('system viewer can list but cannot execute or manage retention', function (): void {
    Queue::fake();

    $this->actingAs(createSystemViewerUser());

    Livewire::test(Index::class)
        ->assertOk()
        ->assertSee('Scheduled Tasks')
        ->call('runNow', 'commerce:marketplace:ebay:pull --orders')
        ->assertForbidden();

    Queue::assertNothingPushed();

    Livewire::test(Index::class)
        ->call('saveField', 'schedule.history.keep_days', '1')
        ->assertForbidden();
});

test('unauthenticated and unauthorized users cannot open scheduled tasks', function (): void {
    $this->get(route('admin.system.scheduled-tasks.index'))
        ->assertRedirect();

    $user = User::factory()->create(['company_id' => Company::factory()->create()->id]);
    $this->actingAs($user)
        ->get(route('admin.system.scheduled-tasks.index'))
        ->assertForbidden();
});

test('run now refuses when command is already running', function (): void {
    Queue::fake();

    ScheduleRun::query()->create([
        'command_key' => 'commerce:marketplace:ebay:pull --orders',
        'command' => 'commerce:marketplace:ebay:pull --orders',
        'attempt_key' => (string) Str::uuid(),
        'status' => ScheduleRunStatuses::RUNNING,
        'started_at' => now(),
    ]);

    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->call('runNow', 'commerce:marketplace:ebay:pull --orders');

    Queue::assertNothingPushed();
});

test('prune respects keep_days and keep_count without touching last-run rows', function (): void {
    $settings = app(SettingsService::class);
    $settings->set('schedule.history.keep_days', '7');
    $settings->set('schedule.history.keep_count', '2');

    ScheduleRun::query()->create([
        'command_key' => 'blb:keep-last',
        'command' => 'blb:keep-last',
        'status' => ScheduleRunStatuses::SUCCEEDED,
        'started_at' => now()->subDays(40),
        'finished_at' => now()->subDays(40),
    ]);

    foreach ([
        ['blb:old', now()->subDays(40)],
        ['blb:mid', now()->subDays(2)],
        ['blb:new-a', now()->subHour()],
        ['blb:new-b', now()],
    ] as [$command, $when]) {
        ScheduleRunHistory::query()->create(historyAttrs([
            'command_key' => $command,
            'command' => $command,
            'status' => ScheduleRunStatuses::SUCCEEDED,
            'started_at' => $when,
            'finished_at' => $when,
        ]));
    }

    $result = app(ScheduleHistoryPruner::class)->prune();

    expect($result['deleted'])->toBeGreaterThan(0)
        ->and(ScheduleRunHistory::query()->count())->toBe(2)
        ->and(ScheduleRunHistory::query()->pluck('command_key')->sort()->values()->all())->toBe(['blb:new-a', 'blb:new-b'])
        ->and(ScheduleRun::query()->count())->toBe(1);
});

test('prune artisan command is registered on the schedule', function (): void {
    app(Kernel::class)->all();

    $commands = collect(app(Schedule::class)->events())
        ->map(fn (Event $event): string => app(ScheduleRunRecorder::class)->normalizeCommand((string) $event->command))
        ->all();

    expect($commands)->toContain('blb:schedule:history:prune');

    Artisan::call('blb:schedule:history:prune');
    expect(Artisan::output())->toContain('Pruned');
});

test('run now queues a registered scheduled command', function (): void {
    Queue::fake();

    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->call('runNow', 'commerce:marketplace:ebay:pull --orders')
        ->assertHasNoErrors();

    Queue::assertPushed(RunScheduledTaskJob::class, function (RunScheduledTaskJob $job): bool {
        return $job->commandKey === 'commerce:marketplace:ebay:pull --orders';
    });
});

test('run now rejects unregistered commands', function (): void {
    Queue::fake();

    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->call('runNow', 'not:a:real:command');

    Queue::assertNothingPushed();
});
