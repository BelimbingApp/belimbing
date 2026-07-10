<?php

namespace App\Base\Schedule\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Livewire\Concerns\ChecksCapabilityAuthorization;
use App\Base\Foundation\Livewire\Concerns\FiltersByPeriod;
use App\Base\Foundation\Livewire\Concerns\SelectsPerPage;
use App\Base\Schedule\DTO\RecordedRun;
use App\Base\Schedule\DTO\ScheduleTask;
use App\Base\Schedule\Jobs\RunScheduledTaskJob;
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
    use FiltersByPeriod;
    use SelectsPerPage;
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
     * @param  list<RecordedRun>  $runs
     * @return list<RecordedRun>
     */
    private function filteredHistory(array $runs, Carbon $from, Carbon $to): array
    {
        $search = mb_strtolower(trim($this->historySearch));
        $status = $this->historyStatus;

        $filtered = array_values(array_filter($runs, function (RecordedRun $run) use ($search, $status, $from, $to): bool {
            if ($search !== '' && ! str_contains(mb_strtolower($run->name), $search)) {
                return false;
            }

            if ($status !== 'all' && $run->status !== $status) {
                return false;
            }

            $startedAt = $run->startedAt->getTimestamp();

            if ($startedAt < $from->getTimestamp()) {
                return false;
            }

            if ($startedAt > $to->getTimestamp()) {
                return false;
            }

            return true;
        }));

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

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @param  callable(T, string): mixed  $value
     * @return list<T>
     */
    private function sortItems(array $items, string $column, string $direction, callable $value): array
    {
        usort($items, function (mixed $a, mixed $b) use ($column, $direction, $value): int {
            $left = $value($a, $column);
            $right = $value($b, $column);

            if ($left === null && $right === null) {
                return 0;
            }

            if ($left === null) {
                return 1;
            }

            if ($right === null) {
                return -1;
            }

            $comparison = $this->compareValues($left, $right);

            if ($comparison === 0) {
                return 0;
            }

            return $direction === 'desc' ? -$comparison : $comparison;
        });

        return $items;
    }

    private function compareValues(mixed $left, mixed $right): int
    {
        if (is_string($left) || is_string($right)) {
            return strnatcasecmp((string) $left, (string) $right);
        }

        return $left <=> $right;
    }

    /**
     * @param  list<ScheduleTask>  $tasks
     * @return array<string, string|null>
     */
    private function cronDescriptions(array $tasks): array
    {
        $descriptions = [];

        foreach ($tasks as $task) {
            $descriptions[$task->source.'|'.$task->key] = $this->describeCron($task->cron);
        }

        return $descriptions;
    }

    private function describeCron(string $cron): ?string
    {
        $cron = trim($cron);
        $fields = preg_split('/\s+/', $cron) ?: [];

        if (count($fields) !== 5) {
            return null;
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $fields;

        if ($cron === '* * * * *') {
            return __('Every minute');
        }

        if (preg_match('/^\*\/([1-9]\d*)$/', $minute, $match) === 1
            && $hour === '*'
            && $dayOfMonth === '*'
            && $month === '*'
            && $dayOfWeek === '*') {
            $count = (int) $match[1];

            return trans_choice('Every :count minute|Every :count minutes', $count, ['count' => $count]);
        }

        if ($minute === '0'
            && preg_match('/^\*\/([1-9]\d*)$/', $hour, $match) === 1
            && $dayOfMonth === '*'
            && $month === '*'
            && $dayOfWeek === '*') {
            $count = (int) $match[1];

            return trans_choice('Every :count hour|Every :count hours', $count, ['count' => $count]);
        }

        if ($this->isNumericCronField($minute) && $hour === '*' && $dayOfMonth === '*' && $month === '*' && $dayOfWeek === '*') {
            return __('Hourly at :minute minutes past the hour', ['minute' => str_pad($minute, 2, '0', STR_PAD_LEFT)]);
        }

        if ($this->isNumericCronField($minute) && $this->isNumericCronField($hour) && $month === '*') {
            $time = $this->cronTimeLabel($hour, $minute);

            if ($dayOfMonth === '*' && $dayOfWeek === '*') {
                return __('Daily at :time', ['time' => $time]);
            }

            if ($dayOfMonth === '*' && $dayOfWeek === '1-5') {
                return __('Weekdays at :time', ['time' => $time]);
            }

            if ($dayOfMonth === '*' && $this->weekdayName($dayOfWeek) !== null) {
                return __('Weekly on :day at :time', ['day' => $this->weekdayName($dayOfWeek), 'time' => $time]);
            }

            if ($this->isNumericCronField($dayOfMonth) && $dayOfWeek === '*') {
                return __('Monthly on day :day at :time', ['day' => (int) $dayOfMonth, 'time' => $time]);
            }
        }

        return CronExpression::isValidExpression($cron) ? __('Custom cron schedule') : __('Invalid cron expression');
    }

    private function isNumericCronField(string $field): bool
    {
        return ctype_digit($field);
    }

    private function cronTimeLabel(string $hour, string $minute): string
    {
        return str_pad($hour, 2, '0', STR_PAD_LEFT).':'.str_pad($minute, 2, '0', STR_PAD_LEFT);
    }

    private function weekdayName(string $dayOfWeek): ?string
    {
        return match ($dayOfWeek) {
            '0', '7' => __('Sunday'),
            '1' => __('Monday'),
            '2' => __('Tuesday'),
            '3' => __('Wednesday'),
            '4' => __('Thursday'),
            '5' => __('Friday'),
            '6' => __('Saturday'),
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    private function taskStatusOptions(): array
    {
        return [
            'all' => __('All task statuses'),
            'never' => __('Never run'),
            'queued' => __('Queued'),
            'running' => __('Running'),
            'succeeded' => __('Succeeded'),
            'failed' => __('Failed'),
            'cancelled' => __('Cancelled'),
            'skipped' => __('Skipped'),
            'paused' => __('Paused'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function historyStatusOptions(): array
    {
        return [
            'all' => __('All run statuses'),
            'queued' => __('Queued'),
            'running' => __('Running'),
            'succeeded' => __('Succeeded'),
            'failed' => __('Failed'),
            'cancelled' => __('Cancelled'),
            'skipped' => __('Skipped'),
        ];
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
