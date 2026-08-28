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
 */
final class ScheduleHealthService
{
    private const int CACHE_TTL_SECONDS = 60;

    private const int CONSECUTIVE_FAILURE_SCAN = 10;

    public function __construct(private readonly ScheduleBoard $board) {}

    /**
     * @return array{unhealthy: list<UnhealthyScheduleTask>, last_recorded_at: ?Carbon}
     */
    public function snapshot(): array
    {
        return Cache::remember(
            'schedule.health.snapshot',
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->computeSnapshot(),
        );
    }

    /**
     * @return list<UnhealthyScheduleTask>
     */
    public function unhealthyTasks(): array
    {
        return $this->snapshot()['unhealthy'];
    }

    /**
     * @return array{unhealthy: list<UnhealthyScheduleTask>, last_recorded_at: ?Carbon}
     */
    private function computeSnapshot(): array
    {
        if (! Schema::hasTable('base_schedule_runs')) {
            return ['unhealthy' => [], 'last_recorded_at' => null];
        }

        $tasks = $this->board->tasks();
        $schedulerKeys = [];
        $unhealthy = [];
        $lastRecordedAt = null;

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
            return ['unhealthy' => $unhealthy, 'last_recorded_at' => null];
        }

        $uniqueKeys = array_values(array_unique($schedulerKeys));

        // One indexed query covers the latest-definitive-outcome, the
        // consecutive-failure scan, AND the most-recent-activity timestamp
        // the diagnostic provider needs. Newest-first from the ORDER BY,
        // grouped in PHP. The limit caps the per-key scan window at
        // CONSECUTIVE_FAILURE_SCAN (10) — matching the previous behaviour —
        // and `first()->started_at` of the result set is the most recent
        // recorded activity across the scheduler keys.
        $runs = ScheduleRun::query()
            ->where('source', 'scheduler')
            ->whereIn('key', $uniqueKeys)
            ->orderByDesc('started_at')
            ->limit(self::CONSECUTIVE_FAILURE_SCAN * count($uniqueKeys))
            ->get();

        $lastRecordedAt = $runs->first()?->started_at;

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
            return ['unhealthy' => $unhealthy, 'last_recorded_at' => $lastRecordedAt];
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

        return ['unhealthy' => $unhealthy, 'last_recorded_at' => $lastRecordedAt];
    }
}
