<?php

namespace App\Base\Schedule\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Livewire\Concerns\ChecksCapabilityAuthorization;
use App\Base\Foundation\Livewire\Concerns\FiltersByPeriod;
use App\Base\Foundation\Livewire\Concerns\SelectsPerPage;
use App\Base\Schedule\Contracts\ScheduleCadenceContributor;
use App\Base\Schedule\DTO\ScheduleHistoryPage;
use App\Base\Schedule\DTO\ScheduleHistoryQuery;
use App\Base\Schedule\DTO\ScheduleTask;
use App\Base\Schedule\Jobs\RunScheduledTaskJob;
use App\Base\Schedule\Livewire\Concerns\DescribesCronSchedules;
use App\Base\Schedule\Livewire\Concerns\ProvidesScheduleStatusOptions;
use App\Base\Schedule\Livewire\Concerns\SortsScheduleBoardItems;
use App\Base\Schedule\Models\ScheduleOverride;
use App\Base\Schedule\Models\ScheduleSuppression;
use App\Base\Schedule\Services\ScheduleBoard;
use App\Base\Schedule\Services\ScheduleHealthService;
use App\Base\Schedule\Services\ScheduleHistoryPruner;
use App\Base\Schedule\Services\ScheduleRunRecorder;
use App\Base\Settings\Contracts\SettingsService;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Central schedule observability: everything scheduled to fire (Laravel
 * scheduler + contributor sources), soonest first, and the merged run
 * history. Editing stays where schedules are owned (module pages, code);
 * this page exposes safe operations on registered scheduler entries.
 */
class Index extends Component
{
    use ChecksCapabilityAuthorization;
    use DescribesCronSchedules;
    use FiltersByPeriod;
    use ProvidesScheduleStatusOptions;
    use SelectsPerPage;
    use SortsScheduleBoardItems;
    use WithPagination;

    private const array TASK_SORTS = ['name', 'next_run', 'last_run'];

    private const array HISTORY_SORTS = ['started_at', 'name', 'source', 'status'];

    public string $tab = 'tasks';

    public string $keepDays = (string) ScheduleHistoryPruner::DEFAULT_KEEP_DAYS;

    public string $taskSearch = '';

    public string $taskStatus = 'all';

    public string $taskSort = 'next_run';

    public string $taskSortDirection = 'asc';

    public string $historySearch = '';

    public string $historyStatus = 'all';

    public string $historySort = 'started_at';

    public string $historySortDirection = 'desc';

    public function mount(ScheduleHistoryPruner $historyPruner): void
    {
        $this->keepDays = (string) $historyPruner->keepDays();
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['tasks', 'history', 'settings'], true)) {
            $this->tab = $tab;
        }
    }

    public function updatedTaskSearch(): void
    {
        $this->taskSearch = trim($this->taskSearch);
    }

    public function updatedHistorySearch(): void
    {
        $this->historySearch = trim($this->historySearch);
        $this->resetPage();
    }

    public function updatedHistoryStatus(): void
    {
        $this->resetPage();
    }

    public function sortTasks(string $column): void
    {
        if (! in_array($column, self::TASK_SORTS, true)) {
            return;
        }

        if ($this->taskSort === $column) {
            $this->taskSortDirection = $this->taskSortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->taskSort = $column;
        $this->taskSortDirection = $column === 'last_run' ? 'desc' : 'asc';
    }

    public function sortHistory(string $column): void
    {
        if (! in_array($column, self::HISTORY_SORTS, true)) {
            return;
        }

        if ($this->historySort === $column) {
            $this->historySortDirection = $this->historySortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->historySort = $column;
            $this->historySortDirection = $column === 'started_at' ? 'desc' : 'asc';
        }

        $this->resetPage();
    }

    /**
     * Makes "Run now" an observable, honest state transition instead of a
     * fire-and-forget dispatch (#401): the task must already be registered,
     * must not already have a queued/running row, and gets its `queued`
     * ledger row written before the job dispatch call returns — so a job
     * that never reaches the worker still leaves visible, correct state.
     *
     * $force lets a capable operator supersede a queued/running row that
     * has sat unresponsive past ScheduleRunRecorder's staleness window —
     * the Blade view only ever sends true once activeRunLooksStuck() would
     * agree, but that's re-checked inside reserveManualRun() rather than
     * trusted from the client.
     *
     * The active-row check, any stale/supersede reconciliation, and the
     * queued-row insert are all decided inside reserveManualRun() as one
     * locked transaction — not read here and written separately, which two
     * concurrent requests for the same key could otherwise interleave
     * (#407 review, luna's P1 / terra's implementation guidance). Dispatch
     * only happens after that transaction has committed.
     */
    public function runNow(string $key, ScheduleRunRecorder $recorder, Schedule $schedule, bool $force = false): void
    {
        if (! $this->checkCapability('admin.system.schedule.execute')) {
            return;
        }

        $event = $recorder->findEvent($schedule, $key);

        if ($event === null) {
            $this->notifyError(__('This task is no longer registered and cannot be run.'));

            return;
        }

        $user = auth()->user();
        $userName = $user !== null ? (string) data_get($user, 'name') : null;

        $reservation = $recorder->reserveManualRun(
            $key,
            $recorder->name($event),
            (string) $event->expression,
            $user !== null ? (int) $user->getAuthIdentifier() : null,
            $userName,
            $force,
        );

        if (! $reservation->created || $reservation->run === null) {
            $this->notifyWarning(__('This task is already queued or running.'));

            return;
        }

        $run = $reservation->run;

        try {
            RunScheduledTaskJob::dispatch($key, $run->id);
        } catch (Throwable $e) {
            $recorder->failUnstartedRun($run->id, __('Could not queue this run: :message', ['message' => $e->getMessage()]));

            report($e);
            $this->notifyError(__('Could not queue the run — check the queue connection.'));

            return;
        }

        $this->notify(__('Run queued.'));
    }

    // Inline cron editing (#398): one row at a time, identified by
    // source+key. `cronPreviewFor` records which normalized expression the
    // shown preview belongs to — saving requires the preview to have been
    // taken for exactly the draft being saved, so the next-three-runs
    // confirmation is a mechanism, not an instruction.
    public ?string $editingSource = null;

    public ?string $editingKey = null;

    public string $cronDraft = '';

    public ?string $cronVersion = null;

    /** @var list<string> */
    public array $cronPreview = [];

    public ?string $cronPreviewFor = null;

    public ?string $cronPreviewTimezone = null;

    public function startCronEdit(string $source, string $key): void
    {
        if (! $this->checkCapability('admin.system.schedule.manage')) {
            return;
        }

        $task = $this->boardTask($source, $key);

        if ($task === null || ! $task->editable) {
            $this->notifyError(__("This task's cadence cannot be edited from this page — its owner does not support it."));

            return;
        }

        $this->editingSource = $source;
        $this->editingKey = $key;
        $this->cronDraft = $task->cron;
        // The value the operator is editing FROM is the version token: a
        // concurrent save changes the stored expression by definition, so an
        // atomic conditional write keyed on it needs no timestamp precision
        // (#411 review — the previous read-then-write left a race window).
        // Empty string = "no override existed when editing began".
        $this->cronVersion = $task->overridden ? $task->cron : '';
        $this->cronPreview = [];
        $this->cronPreviewFor = null;
        $this->cronPreviewTimezone = null;
        $this->resetErrorBag('cronDraft');
    }

    public function cancelCronEdit(): void
    {
        $this->editingSource = null;
        $this->editingKey = null;
        $this->cronDraft = '';
        $this->cronVersion = null;
        $this->cronPreview = [];
        $this->cronPreviewFor = null;
        $this->cronPreviewTimezone = null;
        $this->resetErrorBag('cronDraft');
    }

    public function previewCron(): void
    {
        if (! $this->checkCapability('admin.system.schedule.manage') || $this->editingKey === null) {
            return;
        }

        $task = $this->boardTask((string) $this->editingSource, $this->editingKey);
        $expression = $this->validCronOrError($this->cronDraft);

        if ($task === null || $expression === null) {
            $this->cronPreview = [];
            $this->cronPreviewFor = null;

            return;
        }

        $timezone = $task->timezone ?? (string) config('app.timezone');
        $cursor = Carbon::now($timezone);
        $preview = [];

        // Evaluated in the task's declared timezone — the same clock the
        // runtime will use — and displayed with that timezone stated.
        $cron = new CronExpression($expression);
        for ($i = 0; $i < 3; $i++) {
            $cursor = Carbon::instance($cron->getNextRunDate($cursor, 0, false, $timezone));
            $preview[] = $cursor->format('D, M j Y H:i');
        }

        $this->cronPreview = $preview;
        $this->cronPreviewFor = $expression;
        $this->cronPreviewTimezone = $timezone;
    }

    public function saveCron(): void
    {
        if (! $this->checkCapability('admin.system.schedule.manage') || $this->editingKey === null) {
            return;
        }

        $source = (string) $this->editingSource;
        $key = $this->editingKey;
        $task = $this->boardTask($source, $key);

        if ($task === null || ! $task->editable) {
            $this->notifyError(__("This task's cadence cannot be edited from this page — its owner does not support it."));

            return;
        }

        $expression = $this->validCronOrError($this->cronDraft);

        if ($expression === null) {
            return;
        }

        if ($this->cronPreviewFor !== $expression) {
            $this->addError('cronDraft', __('Preview the next run times before saving.'));

            return;
        }

        if ($expression === $task->defaultCron) {
            // Saving the default IS the reset — do not persist a redundant
            // override that would freeze the task against future deployments
            // changing the code default.
            $this->resetCron($source, $key);

            return;
        }

        if ($source !== 'scheduler') {
            $this->saveContributorCadence($key, $expression);

            return;
        }

        // Stale-edit guard, atomic (#411 review): a read-then-write check
        // leaves a window where a concurrent save lands between the read and
        // the write and is silently overwritten. Instead the write itself
        // carries the expectation — INSERT relies on the source+key unique
        // index to refuse a row that appeared meanwhile, and UPDATE matches
        // the expression this operator started editing from, so zero
        // affected rows IS the staleness verdict.
        if (($this->cronVersion ?? '') === '') {
            try {
                ScheduleOverride::query()->create(
                    ['source' => 'scheduler', 'key' => $key, 'name' => $task->name, 'expression' => $expression],
                );
            } catch (UniqueConstraintViolationException) {
                $this->addError('cronDraft', __('This cadence was changed by someone else while you were editing — review the current value and try again.'));

                return;
            }
        } else {
            $affected = ScheduleOverride::query()
                ->where('source', 'scheduler')
                ->where('key', $key)
                ->where('expression', $this->cronVersion)
                ->update(['name' => $task->name, 'expression' => $expression, 'updated_at' => now()]);

            if ($affected === 0) {
                $this->addError('cronDraft', __('This cadence was changed by someone else while you were editing — review the current value and try again.'));

                return;
            }
        }

        $this->cancelCronEdit();
        $this->notify(__('Cadence saved — the scheduler honors it from the next evaluation.'));
    }

    public function resetCron(string $source, string $key): void
    {
        if (! $this->checkCapability('admin.system.schedule.manage')) {
            return;
        }

        if ($source !== 'scheduler') {
            $contributor = $this->cadenceContributorFor($key);

            if ($contributor === null || ! $contributor->resetCadence($key)) {
                $this->notifyError(__("This task's owner did not accept the reset."));

                return;
            }
        } else {
            ScheduleOverride::query()->where('source', 'scheduler')->where('key', $key)->delete();
        }

        $this->cancelCronEdit();
        $this->notify(__('Cadence reset to the code-declared default.'));
    }

    /**
     * Normalizes harmless whitespace, then validates against the same cron
     * grammar the runtime accepts (dragonmantank/cron-expression — the class
     * the scheduler itself evaluates). Never reinterprets an invalid
     * expression: anything not exactly five valid fields is refused with a
     * specific error and nothing is persisted (#398).
     */
    private function validCronOrError(string $raw): ?string
    {
        $expression = trim(preg_replace('/\s+/', ' ', $raw) ?? '');

        if ($expression === '') {
            $this->addError('cronDraft', __('The cron expression cannot be empty.'));

            return null;
        }

        if (count(explode(' ', $expression)) !== 5) {
            $this->addError('cronDraft', __('A cron expression needs exactly five fields: minute, hour, day of month, month, day of week.'));

            return null;
        }

        if (! CronExpression::isValidExpression($expression)) {
            $this->addError('cronDraft', __('This is not a valid cron expression — check field ranges (minute 0–59, hour 0–23, day 1–31, month 1–12, weekday 0–7).'));

            return null;
        }

        return $expression;
    }

    private function saveContributorCadence(string $key, string $expression): void
    {
        $contributor = $this->cadenceContributorFor($key);

        if ($contributor === null) {
            $this->notifyError(__("This task's cadence cannot be edited from this page — its owner does not support it."));

            return;
        }

        if (! $contributor->updateCadence($key, $expression)) {
            $this->notifyError(__("This task's owner did not accept the new cadence."));

            return;
        }

        $this->cancelCronEdit();
        $this->notify(__('Cadence saved with the task owner.'));
    }

    private function cadenceContributorFor(string $key): ?ScheduleCadenceContributor
    {
        foreach (app()->tagged('schedule.contributors') as $contributor) {
            if (! $contributor instanceof ScheduleCadenceContributor) {
                continue;
            }

            foreach ($contributor->tasks() as $task) {
                if ($task->key === $key) {
                    return $contributor;
                }
            }
        }

        return null;
    }

    private function boardTask(string $source, string $key): ?ScheduleTask
    {
        foreach (app(ScheduleBoard::class)->tasks() as $task) {
            if ($task->source === $source && $task->key === $key) {
                return $task;
            }
        }

        return null;
    }

    public function pause(string $key, string $name): void
    {
        if (! $this->checkCapability('admin.system.schedule.manage')) {
            return;
        }

        ScheduleSuppression::query()->firstOrCreate([
            'source' => 'scheduler',
            'key' => $key,
        ], [
            'name' => $name,
        ]);

        $this->notify(__('Task paused.'));
    }

    public function resume(string $key): void
    {
        if (! $this->checkCapability('admin.system.schedule.manage')) {
            return;
        }

        ScheduleSuppression::query()
            ->where('source', 'scheduler')
            ->where('key', $key)
            ->delete();
        ScheduleHealthService::invalidate();

        $this->notify(__('Task resumed.'));
    }

    public function saveField(string $field, string $value): void
    {
        if ($field !== 'keepDays') {
            return;
        }

        $this->keepDays = $value;
        $this->persistRetention(app(SettingsService::class));
    }

    public function saveRetention(SettingsService $settings): void
    {
        $this->persistRetention($settings);
    }

    private function persistRetention(SettingsService $settings): void
    {
        if (! $this->checkCapability('admin.system.schedule.manage')) {
            return;
        }

        $validated = $this->validate([
            'keepDays' => ['required', 'integer', 'min:0', 'max:3650'],
        ]);

        $settings->set(ScheduleHistoryPruner::KEEP_DAYS_KEY, (int) $validated['keepDays']);

        $this->notify(__('Retention saved.'));
    }

    public function render(ScheduleBoard $board): View
    {
        $allTasks = $board->tasks();
        $tasks = $this->filteredTasks($allTasks);
        [$from, $to, $historyRangeError] = $this->periodRange();

        $history = $historyRangeError === null
            ? $board->history(
                new ScheduleHistoryQuery(
                    from: $from,
                    to: $to,
                    status: $this->historyStatus,
                    search: mb_strtolower(trim($this->historySearch)),
                    sortColumn: $this->historySort,
                    sortDirection: $this->historySortDirection,
                ),
                $this->clampedPerPage(),
                max(1, (int) $this->getPage()),
            )
            : new ScheduleHistoryPage([], 0, false);

        $runs = $this->historyPaginator($history);

        return view('livewire.admin.system.schedule.index', [
            'tasks' => $tasks,
            'runs' => $runs,
            'taskEmptyMessage' => $allTasks === [] ? __('Nothing is scheduled.') : __('No tasks match the current filters.'),
            'historyEmptyMessage' => $historyRangeError ?? ($history->hasHistory ? __('No runs match the current filters.') : __('No runs recorded yet.')),
            'historyRangeError' => $historyRangeError,
            'cronDescriptions' => $this->cronDescriptions($tasks),
            'periodOptions' => $this->periodOptions(),
            'taskStatusOptions' => $this->taskStatusOptions(),
            'historyStatusOptions' => $this->historyStatusOptions(),
            'canExecute' => $this->can('admin.system.schedule.execute'),
            'canManage' => $this->can('admin.system.schedule.manage'),
            // Configuration-history subjects (#398): the schedule-task
            // identities that have persisted configuration (an override or a
            // suppression) — the union both models expose via getAuditSubject.
            'historySubjects' => $this->configurationHistorySubjects(),
        ]);
    }

    /**
     * @return list<array{name: string, id: string}>
     */
    private function configurationHistorySubjects(): array
    {
        $subjects = collect();

        try {
            $subjects = ScheduleOverride::query()->get()
                ->map(fn (ScheduleOverride $o): array => ['name' => 'schedule-task', 'id' => $o->source.':'.$o->key])
                ->concat(ScheduleSuppression::query()->get()
                    ->map(fn (ScheduleSuppression $x): array => ['name' => 'schedule-task', 'id' => $x->source.':'.$x->key]));
        } catch (\Throwable) {
            // Tables absent (fresh install mid-migration): the header action
            // simply renders without subjects.
        }

        return $subjects->unique('id')->values()->all();
    }

    /**
     * @param  list<ScheduleTask>  $tasks
     * @return list<ScheduleTask>
     */
    private function filteredTasks(array $tasks): array
    {
        $search = mb_strtolower(trim($this->taskSearch));
        $status = $this->taskStatus;

        $filtered = array_values(array_filter($tasks, function (ScheduleTask $task) use ($search, $status): bool {
            if ($search !== '' && ! str_contains(mb_strtolower($task->name), $search)) {
                return false;
            }

            return match ($status) {
                'all' => true,
                'paused' => $task->paused,
                'never' => $task->status === null || $task->status === '',
                default => $task->status === $status,
            };
        }));

        return $this->sortItems(
            $filtered,
            $this->taskSort,
            $this->taskSortDirection,
            fn (ScheduleTask $task, string $column): mixed => match ($column) {
                'name' => mb_strtolower($task->name),
                'last_run' => $task->lastRunAt?->getTimestamp(),
                default => $task->nextRunAt?->getTimestamp(),
            },
        );
    }

    /**
     * Build the paginator for one merged history page. The page is clamped to
     * the last page when a filter change shrank the result set.
     */
    private function historyPaginator(ScheduleHistoryPage $history): LengthAwarePaginator
    {
        $perPage = $this->clampedPerPage();
        $total = $history->total;
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) $this->getPage()), $lastPage);

        if ($page !== (int) $this->getPage()) {
            $this->setPage($page);
        }

        return new LengthAwarePaginator(
            $history->items,
            $total,
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    private function can(string $capability): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return app(AuthorizationService::class)
            ->can(Actor::forUser($user), $capability)
            ->allowed;
    }
}
