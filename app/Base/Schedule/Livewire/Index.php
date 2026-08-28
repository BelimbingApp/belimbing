<?php

namespace App\Base\Schedule\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Livewire\Concerns\ChecksCapabilityAuthorization;
use App\Base\Foundation\Livewire\Concerns\FiltersByPeriod;
use App\Base\Foundation\Livewire\Concerns\SelectsPerPage;
use App\Base\Schedule\Contracts\ScheduleCadenceContributor;
use App\Base\Schedule\DTO\RecordedRun;
use App\Base\Schedule\DTO\ScheduleTask;
use App\Base\Schedule\Jobs\RunScheduledTaskJob;
use App\Base\Schedule\Livewire\Concerns\DescribesCronSchedules;
use App\Base\Schedule\Livewire\Concerns\FiltersScheduleRuns;
use App\Base\Schedule\Livewire\Concerns\ProvidesScheduleStatusOptions;
use App\Base\Schedule\Livewire\Concerns\SortsScheduleBoardItems;
use App\Base\Schedule\Models\ScheduleOverride;
use App\Base\Schedule\Models\ScheduleSuppression;
use App\Base\Schedule\Services\ScheduleBoard;
use App\Base\Schedule\Services\ScheduleHistoryPruner;
use App\Base\Settings\Contracts\SettingsService;
use Cron\CronExpression;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

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
    use FiltersScheduleRuns;
    use ProvidesScheduleStatusOptions;
    use SelectsPerPage;
    use SortsScheduleBoardItems;
    use WithPagination;

    private const int HISTORY_FETCH_LIMIT = 500;

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

    public function runNow(string $key): void
    {
        if (! $this->checkCapability('admin.system.schedule.execute')) {
            return;
        }

        RunScheduledTaskJob::dispatch($key);

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
        // The version the operator is editing FROM: a concurrent save changes
        // it, and stale writes are refused rather than silently overwriting
        // another operator's change. Empty string = "no override existed".
        $this->cronVersion = $task->overrideVersion ?? '';
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

        // Stale-edit guard: the override row this operator started editing
        // from must still be what is persisted — a concurrent edit bumps
        // updated_at and this write is refused instead of overwriting it.
        $current = ScheduleOverride::query()->where('source', 'scheduler')->where('key', $key)->first();
        $currentVersion = $current?->updated_at?->toISOString() ?? '';

        if (($this->cronVersion ?? '') !== $currentVersion) {
            $this->addError('cronDraft', __('This cadence was changed by someone else while you were editing — review the current value and try again.'));
            $this->cronVersion = $currentVersion;

            return;
        }

        ScheduleOverride::query()->updateOrCreate(
            ['source' => 'scheduler', 'key' => $key],
            ['name' => $task->name, 'expression' => $expression],
        );

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
        $allRuns = $board->recentRuns(self::HISTORY_FETCH_LIMIT);
        $tasks = $this->filteredTasks($allTasks);
        [$from, $to, $historyRangeError] = $this->periodRange();
        $runs = $this->paginateHistory($historyRangeError === null ? $this->filteredHistory($allRuns, $from, $to) : []);

        return view('livewire.admin.system.schedule.index', [
            'tasks' => $tasks,
            'runs' => $runs,
            'taskEmptyMessage' => $allTasks === [] ? __('Nothing is scheduled.') : __('No tasks match the current filters.'),
            'historyEmptyMessage' => $historyRangeError ?? ($allRuns === [] ? __('No runs recorded yet.') : __('No runs match the current filters.')),
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
     * @param  list<RecordedRun>  $runs
     * @return list<RecordedRun>
     */
    private function filteredHistory(array $runs, Carbon $from, Carbon $to): array
    {
        $search = mb_strtolower(trim($this->historySearch));
        $status = $this->historyStatus;

        $filtered = array_values(array_filter(
            $runs,
            fn (RecordedRun $run): bool => $this->historyRunMatchesFilters($run, $search, $status, $from, $to),
        ));

        return $this->sortItems(
            $filtered,
            $this->historySort,
            $this->historySortDirection,
            fn (RecordedRun $run, string $column): mixed => match ($column) {
                'name' => mb_strtolower($run->name),
                'source' => mb_strtolower($run->source),
                'status' => $run->status,
                default => $run->startedAt->getTimestamp(),
            },
        );
    }

    /**
     * @param  list<RecordedRun>  $runs
     * @return LengthAwarePaginator<int, RecordedRun>
     */
    private function paginateHistory(array $runs): LengthAwarePaginator
    {
        $perPage = $this->clampedPerPage();
        $total = count($runs);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) $this->getPage()), $lastPage);

        if ($page !== (int) $this->getPage()) {
            $this->setPage($page);
        }

        return new LengthAwarePaginator(
            array_slice($runs, ($page - 1) * $perPage, $perPage),
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
