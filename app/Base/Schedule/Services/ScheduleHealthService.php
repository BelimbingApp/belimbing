<?php

namespace App\Base\Schedule\Services;

use App\Base\Schedule\Contracts\ScheduleHealthContributor;
use App\Base\Schedule\DTO\ScheduleHealthSnapshot;
use App\Base\Schedule\DTO\UnhealthyScheduleTask;
use App\Base\Schedule\Models\ScheduleRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Compact, invalidation-safe schedule health projection for shared chrome.
 *
 * The cache stores scalars and arrays rather than model instances so it is safe
 * across worker reloads and cache drivers. Every relevant ledger or suppression
 * model invalidates the one snapshot key; the short TTL is only a backstop.
 */
final class ScheduleHealthService
{
    public const CACHE_KEY = 'schedule.health.snapshot';

    private const int CACHE_TTL_SECONDS = 15;

    private const int RUNS_PER_TASK = 10;

    public function __construct(
        private readonly Schedule $schedule,
        private readonly ScheduleRunRecorder $recorder,
    ) {}

    public static function invalidate(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // A cache outage must not make a scheduler write fail.
        }
    }

    public function snapshot(): ScheduleHealthSnapshot
    {
        $cached = Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->computeSnapshot(),
        );

        return new ScheduleHealthSnapshot(
            lastRecordedActivity: $cached['last_recorded_activity'] === null
                ? null
                : Carbon::parse($cached['last_recorded_activity']),
            unhealthyTasks: array_map(
                fn (array $task): UnhealthyScheduleTask => new UnhealthyScheduleTask(
                    source: $task['source'],
                    key: $task['key'],
                    name: $task['name'],
                    lastAttemptAt: Carbon::parse($task['last_attempt_at']),
                    consecutiveFailures: $task['consecutive_failures'],
                ),
                $cached['unhealthy_tasks'],
            ),
        );
    }

    /**
     * @return array{last_recorded_activity: string|null, unhealthy_tasks: list<array{source: string, key: string, name: string, last_attempt_at: string, consecutive_failures: int}>}
     */
    private function computeSnapshot(): array
    {
        $unhealthy = $this->schedulerUnhealthyTasks();

        foreach (app()->tagged(ScheduleHealthContributor::CONTAINER_TAG) as $contributor) {
            try {
                $unhealthy = [...$unhealthy, ...$contributor->unhealthyTasks()];
            } catch (Throwable $e) {
                logger()->warning('Schedule health contributor failed.', [
                    'contributor' => $contributor::class,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $lastRecordedActivity = ScheduleRun::query()->max('started_at');

        return [
            'last_recorded_activity' => $lastRecordedActivity === null ? null : (string) $lastRecordedActivity,
            'unhealthy_tasks' => array_map(
                fn (UnhealthyScheduleTask $task): array => [
                    'source' => $task->source,
                    'key' => $task->key,
                    'name' => $task->name,
                    'last_attempt_at' => $task->lastAttemptAt->toIso8601String(),
                    'consecutive_failures' => $task->consecutiveFailures,
                ],
                $unhealthy,
            ),
        ];
    }

    /**
     * @return list<UnhealthyScheduleTask>
     */
    private function schedulerUnhealthyTasks(): array
    {
        $active = $this->activeSchedulerTasks();

        if ($active === []) {
            return [];
        }

        $unhealthy = [];

        foreach ($this->recentSchedulerRuns(array_keys($active))->groupBy('key') as $key => $runs) {
            $task = $this->unhealthyTaskForRuns($key, $active[$key], $runs);

            if ($task !== null) {
                $unhealthy[] = $task;
            }
        }

        return $unhealthy;
    }

    /**
     * @return array<string, string>
     */
    private function activeSchedulerTasks(): array
    {
        $active = [];

        foreach ($this->schedule->events() as $event) {
            $key = $this->recorder->key($event);
            $active[$key] = $this->recorder->name($event);
        }

        return $active;
    }

    /**
     * @param  list<string>  $keys
     * @return Collection<int, ScheduleRun>
     */
    private function recentSchedulerRuns(array $keys): Collection
    {
        $query = ScheduleRun::query()
            ->where('source', 'scheduler')
            ->whereIn('key', $keys);
        $runTable = (new ScheduleRun)->getTable();
        $suppressionTable = 'base_schedule_suppressions';
        $grammar = $query->getQuery()->getGrammar();
        $key = $grammar->wrap('key');
        $startedAt = $grammar->wrap('started_at');
        $id = $grammar->wrap('id');

        $ranked = $query
            ->whereNotExists(function (Builder $suppressed) use ($runTable, $suppressionTable): void {
                $suppressed
                    ->selectRaw('1')
                    ->from($suppressionTable)
                    ->where($suppressionTable.'.source', 'scheduler')
                    ->whereColumn($suppressionTable.'.key', $runTable.'.key');
            })
            ->select(['id', 'key', 'name', 'status', 'started_at'])
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY {$key} ORDER BY {$startedAt} DESC, {$id} DESC) AS run_rank");

        return ScheduleRun::query()
            ->fromSub($ranked->toBase(), 'recent_schedule_runs')
            ->where('run_rank', '<=', self::RUNS_PER_TASK)
            ->orderBy('key')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  Collection<int, ScheduleRun>  $runs
     */
    private function unhealthyTaskForRuns(string $key, string $name, Collection $runs): ?UnhealthyScheduleTask
    {
        $consecutiveFailures = 0;
        $lastAttemptAt = null;

        foreach ($runs as $run) {
            if ($run->status === 'failed') {
                $consecutiveFailures++;
                $lastAttemptAt ??= $run->started_at;

                continue;
            }

            if ($run->status === 'succeeded') {
                return null;
            }
        }

        return $lastAttemptAt === null
            ? null
            : new UnhealthyScheduleTask(
                source: 'scheduler',
                key: $key,
                name: $name,
                lastAttemptAt: $lastAttemptAt,
                consecutiveFailures: max(1, $consecutiveFailures),
            );
    }
}
