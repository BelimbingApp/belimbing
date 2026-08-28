<?php

namespace App\Base\Schedule\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Livewire\Concerns\ChecksCapabilityAuthorization;
use App\Base\Foundation\Livewire\Concerns\FiltersByPeriod;
use App\Base\Foundation\Livewire\Concerns\SelectsPerPage;
use App\Base\Schedule\DTO\ScheduleHistoryPage;
use App\Base\Schedule\DTO\ScheduleHistoryQuery;
use App\Base\Schedule\DTO\ScheduleTask;
use App\Base\Schedule\Jobs\RunScheduledTaskJob;
use App\Base\Schedule\Livewire\Concerns\DescribesCronSchedules;
use App\Base\Schedule\Livewire\Concerns\ProvidesScheduleStatusOptions;
use App\Base\Schedule\Livewire\Concerns\SortsScheduleBoardItems;
use App\Base\Schedule\Models\ScheduleSuppression;
use App\Base\Schedule\Services\ScheduleBoard;
use App\Base\Schedule\Services\ScheduleHealthService;
use App\Base\Schedule\Services\ScheduleHistoryPruner;
use App\Base\Schedule\Services\ScheduleRunRecorder;
use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
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
        ]);
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
