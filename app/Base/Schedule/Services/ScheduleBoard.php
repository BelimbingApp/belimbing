<?php

namespace App\Base\Schedule\Services;

use App\Base\Schedule\Contracts\ScheduleContributor;
use App\Base\Schedule\DTO\RecordedRun;
use App\Base\Schedule\DTO\ScheduleHistoryPage;
use App\Base\Schedule\DTO\ScheduleHistoryQuery;
use App\Base\Schedule\DTO\ScheduleTask;
use App\Base\Schedule\Models\ScheduleOverride;
use App\Base\Schedule\Models\ScheduleRun;
use App\Base\Schedule\Models\ScheduleSuppression;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The single answer to "what is scheduled, what ran, what's next": Laravel
 * scheduler events plus every tagged ScheduleContributor, merged and
 * sorted. Contributor failures are logged and skipped - one broken module
 * must not blank the whole board.
 */
class ScheduleBoard
{
    public function __construct(
        private readonly Schedule $schedule,
        private readonly ScheduleRunRecorder $recorder,
    ) {}

    /**
     * @return list<ScheduleTask>
     */
    public function tasks(): array
    {
        $rows = [];
        $scheduledEvents = [];

        foreach ($this->schedule->events() as $event) {
            $key = $this->recorder->key($event);
            $scheduledEvents[] = [$event, $key];
        }

        $latestRuns = $this->latestSchedulerRuns(collect($scheduledEvents)->pluck(1)->all());
        $suppressed = $this->suppressedSchedulerKeys();
        $overrides = $this->schedulerOverrides();

        foreach ($scheduledEvents as [$event, $key]) {
            $latestRun = $latestRuns->get($key);
            $timezone = $this->eventTimezone($event);

            // In a web process the runtime hook never fires, so the event still
            // carries its code-declared expression — which is exactly what the
            // Default column needs. Effective = override when one exists; the
            // next run is computed from the effective value in the task's own
            // declared timezone, because that is what the runtime will honor.
            $default = (string) $event->expression;
            $override = $overrides->get($key);
            $effective = $override?->expression ?? $default;

            $rows[] = new ScheduleTask(
                source: 'scheduler',
                key: $key,
                name: $this->recorder->name($event),
                cron: $effective,
                nextRunAt: CronExpression::isValidExpression($effective)
                    ? Carbon::instance((new CronExpression($effective))->getNextRunDate(Carbon::now($timezone), 0, false, $timezone))
                    : null,
                status: $latestRun?->status,
                lastRunAt: $latestRun?->started_at,
                lastFinishedAt: $latestRun?->finished_at,
                lastResult: $this->resultFor($latestRun),
                paused: $suppressed->has($key),
                canRun: true,
                canPause: true,
                defaultCron: $default,
                overridden: $override !== null,
                editable: true,
                timezone: $timezone,
            );
        }

        foreach ($this->contributors() as $contributor) {
            try {
                $rows = [...$rows, ...$contributor->tasks()];
            } catch (Throwable $e) {
                Log::warning('Schedule contributor tasks() failed', ['contributor' => $contributor::class, 'error' => $e->getMessage()]);
            }
        }

        usort($rows, fn (ScheduleTask $a, ScheduleTask $b): int => ($a->nextRunAt?->timestamp ?? PHP_INT_MAX) <=> ($b->nextRunAt?->timestamp ?? PHP_INT_MAX));

        return $rows;
    }

    /**
     * One page of merged schedule history. Filters are pushed into each
     * source's query before any truncation, so a low-frequency failure stays
     * discoverable even under thousands of newer high-frequency successes.
     * Each source contributes a newest-first window large enough to cover the
     * requested page; the merged window is re-sorted and sliced, and the total
     * is the complete filtered count across sources.
     */
    public function history(ScheduleHistoryQuery $query, int $perPage, int $page): ScheduleHistoryPage
    {
        $perPage = max(1, $perPage);
        $requestedPage = max(1, $page);
        $window = $perPage * $requestedPage;

        $items = [];
        $total = 0;
        $hasHistory = false;

        $scheduler = $this->schedulerHistory($query, $window);
        $items = [...$items, ...$scheduler->items];
        $total += $scheduler->total;
        $hasHistory = $hasHistory || $scheduler->hasHistory;

        foreach ($this->contributors() as $contributor) {
            try {
                $slice = $contributor->history($query, $window);
                $items = [...$items, ...$slice->items];
                $total += $slice->total;
                $hasHistory = $hasHistory || $slice->hasHistory;
            } catch (Throwable $e) {
                Log::warning('Schedule contributor history() failed', ['contributor' => $contributor::class, 'error' => $e->getMessage()]);
            }
        }

        $items = $this->sortHistoryItems($items, $query->sortColumn, $query->sortDirection);

        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($requestedPage, $lastPage);
        $offset = ($page - 1) * $perPage;

        return new ScheduleHistoryPage(array_slice($items, $offset, $perPage), $total, $hasHistory);
    }

    private function schedulerHistory(ScheduleHistoryQuery $query, int $limit): ScheduleHistoryPage
    {
        if (! Schema::hasTable('base_schedule_runs')) {
            return new ScheduleHistoryPage([], 0, false);
        }

        $builder = ScheduleRun::query()
            ->where('started_at', '>=', $query->from)
            ->where('started_at', '<=', $query->to);

        if ($query->status !== 'all') {
            $builder->where('status', $query->status);
        }

        if ($query->search !== '') {
            $builder->whereRaw('LOWER(name) LIKE ?', ['%'.$query->search.'%']);
        }

        $total = (clone $builder)->count();
        $hasHistory = ScheduleRun::query()->exists();

        $sortColumn = in_array($query->sortColumn, ['started_at', 'name', 'source', 'status'], true)
            ? $query->sortColumn
            : 'started_at';
        $direction = $query->sortDirection === 'asc' ? 'asc' : 'desc';

        $builder->orderBy($sortColumn, $direction);
        if ($sortColumn !== 'started_at') {
            $builder->orderByDesc('started_at');
        }
        $builder->orderByDesc('id');

        $items = $builder
            ->limit($limit)
            ->get()
            ->map(fn (ScheduleRun $run): RecordedRun => new RecordedRun(
                source: $run->source,
                name: $run->name,
                status: $run->status,
                startedAt: $run->started_at,
                finishedAt: $run->finished_at,
                detail: $run->output_excerpt,
                trigger: $run->trigger ?? 'scheduled',
                triggeredByName: $run->triggered_by_name,
            ))
            ->all();

        return new ScheduleHistoryPage($items, $total, $hasHistory);
    }

    /**
     * @param  list<RecordedRun>  $items
     * @return list<RecordedRun>
     */
    private function sortHistoryItems(array $items, string $column, string $direction): array
    {
        usort($items, function (RecordedRun $a, RecordedRun $b) use ($column, $direction): int {
            $comparison = match ($column) {
                'name' => strnatcasecmp($a->name, $b->name),
                'source' => strnatcasecmp($a->source, $b->source),
                'status' => strnatcasecmp($a->status, $b->status),
                default => $a->startedAt->timestamp <=> $b->startedAt->timestamp,
            };

            $comparison = $direction === 'desc' ? -$comparison : $comparison;

            if ($comparison !== 0) {
                return $comparison;
            }

            // Deterministic tiebreak, independent of the primary direction:
            // newest first, then source, then name.
            $comparison = $b->startedAt->timestamp <=> $a->startedAt->timestamp;

            if ($comparison !== 0) {
                return $comparison;
            }

            $comparison = strnatcasecmp($a->source, $b->source);

            return $comparison !== 0 ? $comparison : strnatcasecmp($a->name, $b->name);
        });

        return $items;
    }

    /**
     * @param  list<string>  $keys
     * @return Collection<string, ScheduleRun>
     */
    private function latestSchedulerRuns(array $keys): Collection
    {
        if (! Schema::hasTable('base_schedule_runs') || $keys === []) {
            return collect();
        }

        /** @var EloquentCollection<int, ScheduleRun> $runs */
        $runs = ScheduleRun::query()
            ->where('source', 'scheduler')
            ->whereIn('key', array_values(array_unique($keys)))
            ->orderByDesc('started_at')
            ->get();

        return $runs->unique('key')->keyBy('key');
    }

    /**
     * @return Collection<string, true>
     */
    private function suppressedSchedulerKeys(): Collection
    {
        if (! Schema::hasTable('base_schedule_suppressions')) {
            return collect();
        }

        return ScheduleSuppression::query()
            ->where('source', 'scheduler')
            ->pluck('key')
            ->filter()
            ->flip();
    }

    /**
     * @return Collection<string, ScheduleOverride>
     */
    private function schedulerOverrides(): Collection
    {
        if (! Schema::hasTable('base_schedule_overrides')) {
            return collect();
        }

        return ScheduleOverride::query()
            ->where('source', 'scheduler')
            ->get()
            ->keyBy('key');
    }

    private function resultFor(?ScheduleRun $run): ?string
    {
        if ($run === null) {
            return null;
        }

        $result = null;

        if (is_string($run->output_excerpt) && trim($run->output_excerpt) !== '') {
            $result = mb_substr(trim($run->output_excerpt), 0, 240);
        } elseif ($run->exit_code !== null) {
            $result = 'Exit '.$run->exit_code;
        } else {
            $result = match ($run->status) {
                'running' => 'Running',
                'skipped' => 'Skipped',
                'succeeded' => 'Succeeded',
                'failed' => 'Failed',
                default => null,
            };
        }

        return $result;
    }

    private function eventTimezone(Event $event): string
    {
        $timezone = $event->timezone ?? config('app.timezone');

        return $timezone instanceof \DateTimeZone ? $timezone->getName() : (string) $timezone;
    }

    /**
     * @return iterable<ScheduleContributor>
     */
    private function contributors(): iterable
    {
        return app()->tagged('schedule.contributors');
    }
}
