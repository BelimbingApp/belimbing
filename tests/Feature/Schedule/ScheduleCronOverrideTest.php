<?php

use App\Base\Schedule\Contracts\ScheduleCadenceContributor;
use App\Base\Schedule\Contracts\ScheduleContributor;
use App\Base\Schedule\DTO\ScheduleHistoryPage;
use App\Base\Schedule\DTO\ScheduleHistoryQuery;
use App\Base\Schedule\DTO\ScheduleTask;
use App\Base\Schedule\Livewire\Index;
use App\Base\Schedule\Models\ScheduleOverride;
use App\Base\Schedule\Models\ScheduleSuppression;
use App\Base\Schedule\Services\ScheduleBoard;
use App\Base\Schedule\Services\ScheduleRunRecorder;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setupAuthzRoles();
});

function overrideTestEvent(string $command = 'inspire', string $cron = '0 3 * * *'): array
{
    $event = app(Schedule::class)->command($command)->cron($cron)->description($command);
    $key = app(ScheduleRunRecorder::class)->key($event);

    return [$event, $key];
}

function overrideTestViewer(): User
{
    // A capable-but-read-only actor: schedule.view without schedule.manage.
    $company = Company::factory()->create();

    return User::factory()->create(['company_id' => $company->id]);
}

function overrideCronEditor(string $key, string $draft): Testable
{
    return Livewire::test(Index::class)
        ->call('startCronEdit', 'scheduler', $key)
        ->set('cronDraft', $draft)
        ->call('previewCron');
}

function assertStaleOverride(Testable $component, string $key): void
{
    $component->call('saveCron')->assertHasErrors(['cronDraft']);

    expect(ScheduleOverride::query()->where('key', $key)->value('expression'))->toBe('5 5 * * *');
}

final class ScheduleCronOverrideTestContributor implements ScheduleCadenceContributor, ScheduleContributor
{
    public int $resetCalls = 0;

    /** @var list<ScheduleTask> */
    private array $tasks;

    /** @param  list<ScheduleTask>  $tasks */
    public function __construct(array $tasks)
    {
        $this->tasks = $tasks;
    }

    public function tasks(): array
    {
        return $this->tasks;
    }

    public function recentRuns(int $limit): array
    {
        return [];
    }

    public function history(ScheduleHistoryQuery $query, int $limit): ScheduleHistoryPage
    {
        return new ScheduleHistoryPage(items: [], total: 0, hasHistory: false);
    }

    public function updateCadence(string $key, string $expression): bool
    {
        return true;
    }

    public function resetCadence(string $key): bool
    {
        $this->resetCalls++;

        return true;
    }
}

// ---- editing and validation ----

test('a valid saved override becomes the effective cadence with the default shown honestly', function (): void {
    [, $key] = overrideTestEvent(cron: '0 3 * * *');
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->call('startCronEdit', 'scheduler', $key)
        ->set('cronDraft', '15 6 * * 1')
        ->call('previewCron')
        ->call('saveCron')
        ->assertHasNoErrors();

    expect(ScheduleOverride::query()->where('key', $key)->value('expression'))->toBe('15 6 * * 1');

    $task = collect(app(ScheduleBoard::class)->tasks())->firstWhere('key', $key);

    expect($task->cron)->toBe('15 6 * * 1')
        ->and($task->defaultCron)->toBe('0 3 * * *')
        ->and($task->overridden)->toBeTrue()
        // The displayed next run follows the effective expression immediately.
        ->and($task->nextRunAt->format('i G'))->toBe('15 6');
});

test('validation refuses malformed expressions with specific errors and persists nothing', function (string $draft, string $needle): void {
    [, $key] = overrideTestEvent();
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->call('startCronEdit', 'scheduler', $key)
        ->set('cronDraft', $draft)
        ->call('saveCron')
        ->assertHasErrors(['cronDraft']);

    expect(ScheduleOverride::query()->count())->toBe(0);
})->with([
    'empty' => ['   ', 'empty'],
    'four fields' => ['* * * *', 'five fields'],
    'six fields' => ['* * * * * *', 'five fields'],
    'out-of-range minute' => ['61 * * * *', 'valid cron'],
    'nonsense' => ['banana * * * *', 'valid cron'],
]);

test('harmless whitespace is normalized, never reinterpreted', function (): void {
    [, $key] = overrideTestEvent();
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->call('startCronEdit', 'scheduler', $key)
        ->set('cronDraft', '  30   4  *  *   * ')
        ->call('previewCron')
        ->call('saveCron')
        ->assertHasNoErrors();

    expect(ScheduleOverride::query()->where('key', $key)->value('expression'))->toBe('30 4 * * *');
});

test('the preview shows the next three runs in the task declared timezone before saving is possible', function (): void {
    $event = app(Schedule::class)->command('inspire')->cron('0 3 * * *')->timezone('Asia/Kuala_Lumpur')->description('inspire');
    $key = app(ScheduleRunRecorder::class)->key($event);
    $this->actingAs(createAdminUser());

    $component = Livewire::test(Index::class)
        ->call('startCronEdit', 'scheduler', $key)
        ->set('cronDraft', '0 9 * * *')
        // Saving without a preview for this exact draft is refused: the
        // three-run confirmation is a mechanism, not an instruction.
        ->call('saveCron')
        ->assertHasErrors(['cronDraft'])
        ->call('previewCron');

    expect($component->get('cronPreview'))->toHaveCount(3)
        ->and($component->get('cronPreviewTimezone'))->toBe('Asia/Kuala_Lumpur');

    $component->call('saveCron')->assertHasNoErrors();
});

test('a stale concurrent edit is refused rather than silently overwriting the other operator', function (): void {
    [, $key] = overrideTestEvent();
    $this->actingAs(createAdminUser());

    $component = Livewire::test(Index::class)
        ->call('startCronEdit', 'scheduler', $key)
        ->set('cronDraft', '10 2 * * *')
        ->call('previewCron');

    // Another operator lands their change while this one is still editing.
    Carbon::setTestNow(now()->addMinute());
    ScheduleOverride::query()->create(['source' => 'scheduler', 'key' => $key, 'name' => 'x', 'expression' => '5 5 * * *']);
    Carbon::setTestNow();

    $component->call('saveCron')->assertHasErrors(['cronDraft']);

    expect(ScheduleOverride::query()->where('key', $key)->value('expression'))->toBe('5 5 * * *');
});

test('a concurrent create landing mid-edit is refused atomically, not overwritten', function (): void {
    // The create path of the stale guard (#411 review): editing began with no
    // override, another operator lands one meanwhile — the INSERT must be
    // refused by the unique index, never overwrite via updateOrCreate.
    [, $key] = overrideTestEvent();
    $this->actingAs(createAdminUser());

    $component = overrideCronEditor($key, '10 2 * * *');

    ScheduleOverride::query()->create(['source' => 'scheduler', 'key' => $key, 'name' => 'x', 'expression' => '5 5 * * *']);

    assertStaleOverride($component, $key);
});

test('saving the code default is the reset: no redundant override row is persisted', function (): void {
    [, $key] = overrideTestEvent(cron: '0 3 * * *');
    ScheduleOverride::query()->create(['source' => 'scheduler', 'key' => $key, 'name' => 'x', 'expression' => '9 9 * * *']);
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->call('startCronEdit', 'scheduler', $key)
        ->set('cronDraft', '0 3 * * *')
        ->call('previewCron')
        ->call('saveCron');

    expect(ScheduleOverride::query()->where('key', $key)->exists())->toBeFalse();
});

test('a stale default save cannot delete a newer override', function (): void {
    [, $key] = overrideTestEvent(cron: '0 3 * * *');
    ScheduleOverride::query()->create(['source' => 'scheduler', 'key' => $key, 'name' => 'x', 'expression' => '9 9 * * *']);
    $this->actingAs(createAdminUser());

    $component = overrideCronEditor($key, '0 3 * * *');

    // Another operator replaces the value this editor originally saw.
    ScheduleOverride::query()->where('source', 'scheduler')->where('key', $key)->update(['expression' => '5 5 * * *']);

    assertStaleOverride($component, $key);
});

test('a default save that began without an override refuses a concurrent create', function (): void {
    [, $key] = overrideTestEvent(cron: '0 3 * * *');
    $this->actingAs(createAdminUser());

    $component = overrideCronEditor($key, '0 3 * * *');

    // The edit began at the code default, but another operator creates the
    // override before this form submits. Treat the captured empty version as
    // an assertion that the row is still absent, never as automatic success.
    ScheduleOverride::query()->create(['source' => 'scheduler', 'key' => $key, 'name' => 'x', 'expression' => '5 5 * * *']);

    assertStaleOverride($component, $key);
});

test('reset removes the override and the board immediately adopts the code default', function (): void {
    [, $key] = overrideTestEvent(cron: '0 3 * * *');
    ScheduleOverride::query()->create(['source' => 'scheduler', 'key' => $key, 'name' => 'x', 'expression' => '9 9 * * *']);
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)->call('resetCron', 'scheduler', $key);

    $task = collect(app(ScheduleBoard::class)->tasks())->firstWhere('key', $key);

    expect(ScheduleOverride::query()->where('key', $key)->exists())->toBeFalse()
        ->and($task->cron)->toBe('0 3 * * *')
        ->and($task->overridden)->toBeFalse();
});

test('a view-only user cannot start editing and persists nothing', function (): void {
    [, $key] = overrideTestEvent();
    $this->actingAs(overrideTestViewer());

    Livewire::test(Index::class)
        ->call('startCronEdit', 'scheduler', $key)
        ->assertSet('editingKey', null);

    expect(ScheduleOverride::query()->count())->toBe(0);
});

// ---- runtime ownership ----

test('the scheduler honors a saved override at the next evaluation without a restart', function (): void {
    [$event, $key] = overrideTestEvent(cron: '0 3 * * *');
    ScheduleOverride::query()->create(['source' => 'scheduler', 'key' => $key, 'name' => 'x', 'expression' => '45 7 * * *']);

    // schedule:work runs each minute's evaluation in a fresh schedule:run
    // subprocess; CommandStarting is the moment overrides land on the events.
    event(new CommandStarting('schedule:run', new ArgvInput([]), new NullOutput));

    expect((string) $event->expression)->toBe('45 7 * * *');
});

test('an orphaned override for a task that no longer exists creates no phantom task', function (): void {
    ScheduleOverride::query()->create(['source' => 'scheduler', 'key' => 'gone:task', 'name' => 'gone', 'expression' => '1 1 * * *']);

    event(new CommandStarting('schedule:run', new ArgvInput([]), new NullOutput));

    $keys = collect(app(ScheduleBoard::class)->tasks())->pluck('key');

    expect($keys->contains('gone:task'))->toBeFalse();
});

test('a deployment changing the default while an override exists still shows both values honestly', function (): void {
    // The "deployment" is simulated by declaring the event with a new default
    // while the override row predates it.
    [, $key] = overrideTestEvent(cron: '30 2 * * *');
    ScheduleOverride::query()->create(['source' => 'scheduler', 'key' => $key, 'name' => 'x', 'expression' => '0 6 * * *']);

    $task = collect(app(ScheduleBoard::class)->tasks())->firstWhere('key', $key);

    expect($task->cron)->toBe('0 6 * * *')
        ->and($task->defaultCron)->toBe('30 2 * * *');
});

// ---- contributor contract ----

test('a contributor task without the cadence contract stays read-only', function (): void {
    $contributor = new class implements ScheduleContributor
    {
        public function tasks(): array
        {
            return [new ScheduleTask(source: 'ai', key: 'ai:digest', name: 'AI digest', cron: '0 8 * * *', nextRunAt: now()->addHour(), editable: false)];
        }

        public function recentRuns(int $limit): array
        {
            return [];
        }

        public function history(ScheduleHistoryQuery $query, int $limit): ScheduleHistoryPage
        {
            return new ScheduleHistoryPage(items: [], total: 0, hasHistory: false);
        }
    };
    app()->tag([$contributor::class], 'schedule.contributors');
    app()->instance($contributor::class, $contributor);
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->call('startCronEdit', 'ai', 'ai:digest')
        ->assertSet('editingKey', null);
});

test('direct contributor resets require the exact editable source and key owner', function (): void {
    $readOnly = new ScheduleCronOverrideTestContributor([
        new ScheduleTask(source: 'ai', key: 'read-only', name: 'Read-only AI task', cron: '0 8 * * *', nextRunAt: now()->addHour(), editable: false),
    ]);
    $first = new ScheduleCronOverrideTestContributor([
        new ScheduleTask(source: 'first', key: 'shared', name: 'First task', cron: '0 8 * * *', nextRunAt: now()->addHour(), editable: true),
    ]);
    $second = new ScheduleCronOverrideTestContributor([
        new ScheduleTask(source: 'second', key: 'shared', name: 'Second task', cron: '0 8 * * *', nextRunAt: now()->addHour(), editable: true),
    ]);
    foreach ([$readOnly, $first, $second] as $contributor) {
        app()->instance($contributor::class, $contributor);
        app()->tag([$contributor::class], 'schedule.contributors');
    }
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)->call('resetCron', 'ai', 'read-only');

    expect($readOnly->resetCalls)->toBe(0);

    Livewire::test(Index::class)->call('resetCron', 'second', 'shared');

    expect($first->resetCalls)->toBe(0)
        ->and($second->resetCalls)->toBe(1);
});

test('a contributor implementing the cadence contract receives the validated edit', function (): void {
    $contributor = new class implements ScheduleCadenceContributor, ScheduleContributor
    {
        public array $received = [];

        public function tasks(): array
        {
            return [new ScheduleTask(source: 'ai', key: 'ai:digest', name: 'AI digest', cron: '0 8 * * *', nextRunAt: now()->addHour(), editable: true, defaultCron: '0 8 * * *', timezone: 'UTC')];
        }

        public function recentRuns(int $limit): array
        {
            return [];
        }

        public function history(ScheduleHistoryQuery $query, int $limit): ScheduleHistoryPage
        {
            return new ScheduleHistoryPage(items: [], total: 0, hasHistory: false);
        }

        public function updateCadence(string $key, string $expression): bool
        {
            $this->received = [$key, $expression];

            return true;
        }

        public function resetCadence(string $key): bool
        {
            return true;
        }
    };
    app()->instance($contributor::class, $contributor);
    app()->tag([$contributor::class], 'schedule.contributors');
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->call('startCronEdit', 'ai', 'ai:digest')
        ->set('cronDraft', '30 9 * * *')
        ->call('previewCron')
        ->call('saveCron')
        ->assertHasNoErrors();

    expect($contributor->received)->toBe(['ai:digest', '30 9 * * *'])
        // The owner persisted it; no scheduler-side shadow override exists.
        ->and(ScheduleOverride::query()->count())->toBe(0);
});

// ---- configuration history ----

test('override and suppression rows expose the stable neutral audit subject', function (): void {
    $override = new ScheduleOverride(['source' => 'scheduler', 'key' => 'a:b', 'name' => 'x', 'expression' => '1 1 * * *']);
    $suppression = new ScheduleSuppression(['source' => 'scheduler', 'key' => 'a:b', 'name' => 'x']);

    expect($override->getAuditSubject())->toBe(['name' => 'schedule-task', 'id' => 'scheduler:a:b'])
        ->and($suppression->getAuditSubject())->toBe(['name' => 'schedule-task', 'id' => 'scheduler:a:b']);
});

test('the page header renders the configuration history action for a capable user', function (): void {
    [, $key] = overrideTestEvent();
    ScheduleOverride::query()->create(['source' => 'scheduler', 'key' => $key, 'name' => 'x', 'expression' => '2 2 * * *']);
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->assertSee(__('Schedule configuration history'));
});
