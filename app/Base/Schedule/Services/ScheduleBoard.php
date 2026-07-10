<?php

namespace App\Base\Schedule\Services;

use App\Base\Schedule\Contracts\ScheduleContributor;
use App\Base\Schedule\DTO\RecordedRun;
use App\Base\Schedule\DTO\ScheduleTask;
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

        foreach ($scheduledEvents as [$event, $key]) {
            $latestRun = $latestRuns->get($key);
            $timezone = $this->eventTimezone($event);

            $rows[] = new ScheduleTask(
                source: 'scheduler',
                key: $key,
                name: $this->recorder->name($event),
                cron: (string) $event->expression,
                nextRunAt: CronExpression::isValidExpression((string) $event->expression)
                    ? Carbon::instance((new CronExpression((string) $event->expression))->getNextRunDate(Carbon::now($timezone), 0, false, $timezone))
                    : null,
                status: $latestRun?->status,
                lastRunAt: $latestRun?->started_at,
                lastFinishedAt: $latestRun?->finished_at,
                lastResult: $this->resultFor($latestRun),
                paused: $suppressed->has($key),
                canRun: true,
                canPause: true,
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
     * @return list<RecordedRun>
     */
    public function recentRuns(int $limit = 50): array
    {
        $rows = Schema::hasTable('base_schedule_runs')
            ? ScheduleRun::query()
                ->orderByDesc('started_at')
                ->limit($limit)
                ->get()
                ->map(fn (ScheduleRun $run): RecordedRun => new RecordedRun(
                    source: $run->source,
                    name: $run->name,
                    status: $run->status,
                    startedAt: $run->started_at,
                    finishedAt: $run->finished_at,
                    detail: $run->output_excerpt,
                ))
                ->all()
            : [];

        foreach ($this->contributors() as $contributor) {
            try {
                $rows = [...$rows, ...$contributor->recentRuns($limit)];
            } catch (Throwable $e) {
                Log::warning('Schedule contributor recentRuns() failed', ['contributor' => $contributor::class, 'error' => $e->getMessage()]);
            }
        }

        usort($rows, fn (RecordedRun $a, RecordedRun $b): int => $b->startedAt->timestamp <=> $a->startedAt->timestamp);

        return array_slice($rows, 0, $limit);
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

    private function resultFor(?ScheduleRun $run): ?string
    {
        if ($run === null) {
            return null;
        }

        if (is_string($run->output_excerpt) && trim($run->output_excerpt) !== '') {
            return mb_substr(trim($run->output_excerpt), 0, 240);
        }

        if ($run->exit_code !== null) {
            return 'Exit '.$run->exit_code;
        }

        return match ($run->status) {
            'running' => 'Running',
            'skipped' => 'Skipped',
            'succeeded' => 'Succeeded',
            'failed' => 'Failed',
            default => null,
        };
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
