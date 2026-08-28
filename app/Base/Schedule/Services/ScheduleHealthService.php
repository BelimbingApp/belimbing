<?php

namespace App\Base\Schedule\Services;

use App\Base\Schedule\DTO\ScheduleTask;
use App\Base\Schedule\DTO\UnhealthyScheduleTask;
use App\Base\Schedule\Models\ScheduleRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The compact health projection behind the Schedule status-bar diagnostic:
 * which currently-active tasks' latest definitive outcome is a failure, how
 * many times in a row, and when the scheduler last recorded any activity.
 * Bounded, indexed queries plus a short-lived cache so the status bar
 * (rendered on every authenticated page) stays cheap.
 *
 * Cache shape: two keys with different TTLs. The unhealthy-task list
 * (consecutive-failure streaks) is stable on the order of minutes — the
 * scheduler does not generate failures faster than that — so 60s is fine.
 * The recent-activity heartbeat is cheaper to recompute (one indexed
 * \`max('started_at')\`) and the status-bar contract reads "no recent
 * activity" only when it crosses the threshold, so 15s keeps the diagnostic
 * truthful without inflating the per-render cost.
 */
final class ScheduleHealthService
{
    private const int UNHEALTHY_TASKS_CACHE_TTL_SECONDS = 60;

    private const int HEARTBEAT_CACHE_TTL_SECONDS = 15;

    private const int CONSECUTIVE_FAILURE_SCAN = 10;

    public function __construct(private readonly ScheduleBoard $board) {}

    /**
     * @return list<UnhealthyScheduleTask>
     */
    public function unhealthyTasks(): array
    {
        return Cache::remember(
            'schedule.health.unhealthy-tasks',
            self::UNHEALTHY_TASKS_CACHE_TTL_SECONDS,
            fn (): array => $this->computeUnhealthyTasks(),
        );
    }

    /**
     * Most recent scheduler-keyed activity timestamp, or null if no runs
     * have ever been recorded. The cache TTL is short enough that the
     * "no recent activity" diagnostic reflects fresh writes.
     */
    public function lastRecordedActivity(): ?Carbon
    {
        return Cache::remember(
            'schedule.health.last-recorded-at',
            self::HEARTBEAT_CACHE_TTL_SECONDS,
            function (): ?Carbon {
                if (! Schema::hasTable('base_schedule_runs')) {
                    return null;
                }

                $startedAt = ScheduleRun::query()->max('started_at');

                return $startedAt ? Carbon::parse($startedAt) : null;
            },
        );
    }

    /**
     * @return list<UnhealthyScheduleTask>
     */
    private function computeUnhealthyTasks(): array
    {
        if (! Schema::hasTable('base_schedule_runs')) {
            return [];
        }

        $tasks = $this->board->tasks();
        $schedulerKeys = [];
        $unhealthy = [];

        foreach ($tasks as $task) {
            // Paused and never-run tasks are not failures.
            if ($task->paused || $task->status === null || $task->status === '') {
                continue;
            }

            if ($task->source === 'scheduler') {
                $schedulerKeys[] = $task->key;
            } elseif ($task->status === 'failed') {
                // Contributor tasks expose their latest status directly; the
                // ledger that would count consecutive failures is theirs.
                $unhealthy[] = new UnhealthyScheduleTask(
                    source: $task->source,
                    key: $task->key,
                    name: $task->name,
                    lastAttemptAt: $task->lastRunAt ?? now(),
                    consecutiveFailures: 1,
                );
            }
        }

        if ($schedulerKeys === []) {
            return $unhealthy;
        }

        $uniqueKeys = array_values(array_unique($schedulerKeys));

        // One indexed query covers both the latest-definitive-outcome and the
        // consecutive-failure scan: the runs are already in newest-first order
        // from the ORDER BY, and per-key grouping in PHP gives each key the
        // recent slice it needs without a second round-trip. The limit caps
        // the per-key scan window to CONSECUTIVE_FAILURE_SCAN, matching the
        // previous two-query implementation's bound so behaviour is identical.
        $runs = ScheduleRun::query()
            ->where('source', 'scheduler')
            ->whereIn('key', $uniqueKeys)
            ->orderByDesc('started_at')
            ->limit(self::CONSECUTIVE_FAILURE_SCAN * count($uniqueKeys))
            ->get();

        $failedKeys = [];
        $lastAttemptByKey = [];
        $consecutiveByKey = [];

        foreach ($runs->groupBy('key') as $key => $keyRuns) {
            $consecutive = 0;

            foreach ($keyRuns as $run) {
                if ($run->status === 'failed') {
                    $consecutive++;
                    if (! isset($lastAttemptByKey[$key])) {
                        $lastAttemptByKey[$key] = $run->started_at;
                        $failedKeys[] = $key;
                    }
                } elseif ($run->status === 'succeeded') {
                    // Recovery breaks the streak; running and skipped neither
                    // establish recovery nor break it.
                    break;
                }
            }

            $consecutiveByKey[$key] = max(1, $consecutive);
        }

        if ($failedKeys === []) {
            return $unhealthy;
        }

        $tasksByKey = collect($tasks)
            ->filter(fn (ScheduleTask $task): bool => $task->source === 'scheduler')
            ->keyBy('key');

        foreach ($failedKeys as $key) {
            $task = $tasksByKey->get($key);

            $unhealthy[] = new UnhealthyScheduleTask(
                source: 'scheduler',
                key: $key,
                name: $task?->name ?? $key,
                lastAttemptAt: $lastAttemptByKey[$key] ?? now(),
                consecutiveFailures: $consecutiveByKey[$key] ?? 1,
            );
        }

        return $unhealthy;
    }
}
