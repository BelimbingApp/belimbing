<?php

namespace App\Base\Schedule\Livewire\ScheduledTasks;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Base\Foundation\Livewire\Concerns\SelectsPerPage;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Base\Schedule\DTO\ScheduledTaskRow;
use App\Base\Schedule\Jobs\RunScheduledTaskJob;
use App\Base\Schedule\Models\ScheduleRunHistory;
use App\Base\Schedule\Services\ScheduleHistoryPruner;
use App\Base\Schedule\Services\ScheduleRunRecorder;
use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\User\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Index extends Component
{
    use InteractsWithNotifications;
    use SelectsPerPage;
    use TogglesSort;
    use WithPagination;

    public string $sortBy = 'command';

    public string $sortDir = 'asc';

    public string $historySearch = '';

    public string $historyStatus = '';

    public string $historySortBy = 'id';

    public string $historySortDir = 'desc';

    private const SORTABLE = [
        'command' => true,
        'expression' => true,
        'last_run' => true,
        'next_run' => true,
        'status' => true,
        'flags' => true,
    ];

    private const HISTORY_SORTABLE = [
        'id' => 'id',
        'command' => 'command_key',
        'status' => 'status',
        'started_at' => 'started_at',
        'finished_at' => 'finished_at',
        'runtime_ms' => 'runtime_ms',
    ];

    private ScheduleRunRecorder $recorder;

    public function boot(ScheduleRunRecorder $recorder): void
    {
        $this->recorder = $recorder;
    }

    public function mount(): void
    {
        $this->requireCapability('admin.system.scheduled-task.list');
    }

    public function updatedHistorySearch(): void
    {
        $this->resetPage();
    }

    public function updatedHistoryStatus(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'command' => 'asc',
                'expression' => 'asc',
                'last_run' => 'desc',
                'next_run' => 'asc',
                'status' => 'asc',
                'flags' => 'asc',
            ],
            resetPage: false,
        );
    }

    public function sortHistory(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::HISTORY_SORTABLE,
            defaultDir: [
                'id' => 'desc',
                'command' => 'asc',
                'status' => 'asc',
                'started_at' => 'desc',
                'finished_at' => 'desc',
                'runtime_ms' => 'desc',
            ],
            sortByProperty: 'historySortBy',
            sortDirProperty: 'historySortDir',
        );
    }

    /**
     * Queue one registered schedule event now (honors filtersPass; forces foreground).
     */
    public function runNow(string $commandKey): void
    {
        $this->requireCapability('admin.system.scheduled-task.execute');

        $commandKey = $this->recorder->normalizeCommand($commandKey);

        if ($commandKey === '' || ! $this->isRegisteredCommand($commandKey)) {
            $this->notifyError(__('That scheduled command is not registered.'));

            return;
        }

        if ($this->recorder->isCommandRunning($commandKey)) {
            $this->notifyError(__(':command is already running. Wait for it to finish.', [
                'command' => $commandKey,
            ]));

            return;
        }

        RunScheduledTaskJob::dispatch($commandKey)
            ->onConnection('database')
            ->afterResponse();

        $this->notifySuccess(__('Queued :command — status updates while the job runs.', [
            'command' => $commandKey,
        ]));
    }

    public function saveField(string $field, mixed $value): void
    {
        $this->requireCapability('admin.system.scheduled-task.manage');

        $coerced = match ($field) {
            'schedule.history.keep_days' => (string) max(0, (int) $value),
            'schedule.history.keep_count' => (string) max(0, (int) $value),
            default => null,
        };

        if ($coerced === null) {
            return;
        }

        app(SettingsService::class)->set($field, $coerced);
        $this->notifySuccess(__('Retention setting saved.'));
    }

    public function cleanCommand(string $command): string
    {
        return $this->recorder->normalizeCommand($command);
    }

    public function render(): View
    {
        $this->requireCapability('admin.system.scheduled-task.list');

        // Laravel's withSchedule() only attaches when Artisan starts. Starting
        // the console kernel here keeps any remaining withSchedule registrations
        // visible alongside provider-booted schedules (Investment, AI, Commerce).
        app(ConsoleKernel::class)->all();

        $schedule = app(Schedule::class);
        $lastRuns = $this->recorder->lastRunsByCommandKey();
        $rows = collect($schedule->events())
            ->map(fn ($event): ScheduledTaskRow => ScheduledTaskRow::fromEvent(
                $event,
                $lastRuns->get($this->cleanCommand($event->command)),
                $this->recorder,
            ))
            ->sort(fn (ScheduledTaskRow $a, ScheduledTaskRow $b): int => $this->compareRows($a, $b))
            ->values()
            ->all();

        $hasRunning = collect($rows)->contains(fn (ScheduledTaskRow $row): bool => $row->isRunning())
            || ScheduleRunHistory::query()->where('status', 'running')->exists();

        $pruner = app(ScheduleHistoryPruner::class);

        return view('livewire.admin.system.scheduled-tasks.index', [
            'rows' => $rows,
            'totalCount' => count($rows),
            'canRun' => $this->capabilityAllows('admin.system.scheduled-task.execute'),
            'canManageRetention' => $this->capabilityAllows('admin.system.scheduled-task.manage'),
            'hasRunning' => $hasRunning,
            'historyRows' => $this->historyRows(),
            'keepDays' => $pruner->keepDays(),
            'keepCount' => $pruner->keepCount(),
        ]);
    }

    private function historyRows(): LengthAwarePaginator
    {
        $query = ScheduleRunHistory::query();

        if ($this->historyStatus !== '') {
            $query->where('status', $this->historyStatus);
        }

        if (trim($this->historySearch) !== '') {
            $search = trim($this->historySearch);
            $query->where(function ($builder) use ($search): void {
                $builder->where('command_key', 'like', '%'.$search.'%')
                    ->orWhere('command', 'like', '%'.$search.'%')
                    ->orWhere('output', 'like', '%'.$search.'%');
            });
        }

        $sortColumn = self::HISTORY_SORTABLE[$this->historySortBy] ?? 'id';
        $query->orderBy($sortColumn, $this->historySortDir === 'asc' ? 'asc' : 'desc')
            ->orderByDesc('id');

        return $query->paginate($this->clampedPerPage());
    }

    private function isRegisteredCommand(string $commandKey): bool
    {
        app(ConsoleKernel::class)->all();

        foreach (app(Schedule::class)->events() as $event) {
            if ($this->cleanCommand((string) $event->command) === $commandKey) {
                return true;
            }
        }

        return false;
    }

    private function requireCapability(string $capability): void
    {
        if (! $this->capabilityAllows($capability)) {
            throw new HttpException(403, __('This action is unauthorized.'));
        }
    }

    private function capabilityAllows(string $capability): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return app(AuthorizationService::class)
            ->can(Actor::forUser($user), $capability)
            ->allowed;
    }

    private function compareRows(ScheduledTaskRow $a, ScheduledTaskRow $b): int
    {
        $dir = $this->sortDir === 'desc' ? -1 : 1;

        $flags = fn (ScheduledTaskRow $row): string => implode('|', $row->flags);
        $lastRun = fn (ScheduledTaskRow $row): int => $row->lastRunAt()?->getTimestamp() ?? 0;
        $nextRun = fn (ScheduledTaskRow $row): int => $row->nextRunAt?->getTimestamp() ?? PHP_INT_MAX;

        $primary = match ($this->sortBy) {
            'command' => $dir * strcmp($a->command, $b->command),
            'expression' => $dir * strcmp($a->expression, $b->expression),
            'last_run' => $dir * ($lastRun($a) <=> $lastRun($b)),
            'next_run' => $dir * ($nextRun($a) <=> $nextRun($b)),
            'status' => $dir * strcmp($a->lastStatus, $b->lastStatus),
            'flags' => $dir * strcmp($flags($a), $flags($b)),
            default => $dir * strcmp($a->command, $b->command),
        };

        if ($primary !== 0) {
            return $primary;
        }

        return strcmp($a->expression, $b->expression);
    }
}
