<?php

namespace App\Base\Scheduling\Services;

use App\Base\Scheduling\Contracts\SchedulingContributor;
use App\Base\Scheduling\DTO\RecordedRun;
use App\Base\Scheduling\DTO\UpcomingRun;
use App\Base\Scheduling\Models\ScheduleRun;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The single answer to "what is scheduled, what ran, what's next": Laravel
 * scheduler events plus every tagged SchedulingContributor, merged and
 * sorted. Contributor failures are logged and skipped - one broken module
 * must not blank the whole board.
 */
class SchedulingBoard
{
    public function __construct(
        private readonly Schedule $schedule,
        private readonly ScheduleRunRecorder $recorder,
    ) {}

    /**
     * @return list<UpcomingRun>
     */
    public function upcoming(): array
    {
        $rows = [];

        foreach ($this->schedule->events() as $event) {
            $name = $this->recorder->name($event);
            $timezone = (string) ($event->timezone ?? config('app.timezone'));

            $rows[] = new UpcomingRun(
                source: 'scheduler',
                name: $name,
                cron: $event->expression,
                nextRunAt: CronExpression::isValidExpression($event->expression)
                    ? Carbon::instance((new CronExpression($event->expression))->getNextRunDate(now()->setTimezone($timezone), 0, false, $timezone))
                    : null,
                lastStatus: Schema::hasTable('base_schedule_runs')
                    ? ScheduleRun::query()->where('name', $name)->latest('started_at')->value('status')
                    : null,
            );
        }

        foreach ($this->contributors() as $contributor) {
            try {
                $rows = [...$rows, ...$contributor->upcoming()];
            } catch (Throwable $e) {
                Log::warning('Scheduling contributor upcoming() failed', ['contributor' => $contributor::class, 'error' => $e->getMessage()]);
            }
        }

        usort($rows, fn (UpcomingRun $a, UpcomingRun $b): int => ($a->nextRunAt?->timestamp ?? PHP_INT_MAX) <=> ($b->nextRunAt?->timestamp ?? PHP_INT_MAX));

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
                Log::warning('Scheduling contributor recentRuns() failed', ['contributor' => $contributor::class, 'error' => $e->getMessage()]);
            }
        }

        usort($rows, fn (RecordedRun $a, RecordedRun $b): int => $b->startedAt->timestamp <=> $a->startedAt->timestamp);

        return array_slice($rows, 0, $limit);
    }

    /**
     * @return iterable<SchedulingContributor>
     */
    private function contributors(): iterable
    {
        return app()->tagged('scheduling.contributors');
    }
}
