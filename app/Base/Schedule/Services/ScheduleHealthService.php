<?php

namespace App\Base\Schedule\Services;

use App\Base\Schedule\DTO\ScheduleTask;
use App\Base\Schedule\DTO\UnhealthyScheduleTask;
use App\Base\Schedule\Models\ScheduleRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The compact health projection behind the Schedule status-bar diagnostic:
 * which currently-active tasks' latest definitive outcome is a failure, and
 * how many times in a row. Bounded, indexed queries plus a short-lived cache
 * so the status bar (rendered on every authenticated page) stays cheap.
 */
final class ScheduleHealthService
{
    private const int CACHE_TTL_SECONDS = 60;

    private const int CONSECUTIVE_FAILURE_SCAN = 10;

    public function __construct(private readonly ScheduleBoard $board) {}

    /**
     * @return list<UnhealthyScheduleTask>
     */
    public function unhealthyTasks(): array
    {
        return Cache::remember(
            'schedule.health.unhealthy-tasks',
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->computeUnhealthyTasks(),
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

        // Latest definitive outcome per scheduler key. A later running or
        // skipped run must not erase a prior failure, so only failed/succeeded
        // rows count as outcomes.
        $latestOutcomes = ScheduleRun::query()
            ->where('source', 'scheduler')
            ->whereIn('key', array_values(array_unique($schedulerKeys)))
            ->whereIn('status', ['failed', 'succeeded'])
            ->orderByDesc('started_at')
            ->get()
            ->unique('key');

        $failedKeys = [];
        $lastAttemptByKey = [];

        foreach ($latestOutcomes as $run) {
            if ($run->status !== 'failed') {
                continue;
            }

            $failedKeys[] = $run->key;
            $lastAttemptByKey[$run->key] = $run->started_at;
        }

        if ($failedKeys === []) {
            return $unhealthy;
        }

        $consecutiveByKey = $this->consecutiveFailures($failedKeys);

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

    /**
     * Consecutive failed outcomes per key, newest first, ignoring running and
     * skipped rows (they neither establish recovery nor break the streak).
     *
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    private function consecutiveFailures(array $keys): array
    {
        $runs = ScheduleRun::query()
            ->where('source', 'scheduler')
            ->whereIn('key', $keys)
            ->orderByDesc('started_at')
            ->limit(self::CONSECUTIVE_FAILURE_SCAN * count($keys))
            ->get();

        $counts = [];

        foreach ($runs->groupBy('key') as $key => $group) {
            $consecutive = 0;

            foreach ($group as $run) {
                if ($run->status === 'failed') {
                    $consecutive++;
                } elseif ($run->status === 'succeeded') {
                    break;
                }
            }

            $counts[$key] = max(1, $consecutive);
        }

        return $counts;
    }
}
