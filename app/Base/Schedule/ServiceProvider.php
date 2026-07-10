<?php

namespace App\Base\Schedule;

use App\Base\Schedule\Models\ScheduleSuppression;
use App\Base\Schedule\Services\ScheduleBoard;
use App\Base\Schedule\Services\ScheduleHistoryPruner;
use App\Base\Schedule\Services\ScheduleRunRecorder;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Throwable;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * @var array<int, true>
     */
    private array $suppressionFiltersAttached = [];

    public function register(): void
    {
        $this->app->singleton(ScheduleHistoryPruner::class);
        $this->app->singleton(ScheduleRunRecorder::class);
        $this->app->singleton(ScheduleBoard::class);
    }

    /**
     * Record every scheduler execution framework-wide. Modules get run
     * history for free; non-scheduler sources join the board by tagging a
     * ScheduleContributor implementation with `schedule.contributors`.
     */
    public function boot(): void
    {
        $recorder = fn (): ScheduleRunRecorder => $this->app->make(ScheduleRunRecorder::class);

        Event::listen(ScheduledTaskStarting::class, fn (ScheduledTaskStarting $event) => $recorder()->taskStarting($event));
        Event::listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $event) => $recorder()->taskFinished($event));
        Event::listen(ScheduledBackgroundTaskFinished::class, fn (ScheduledBackgroundTaskFinished $event) => $recorder()->backgroundTaskFinished($event));
        Event::listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $event) => $recorder()->taskFailed($event));
        Event::listen(ScheduledTaskSkipped::class, fn (ScheduledTaskSkipped $event) => $recorder()->taskSkipped($event));

        // Pause/resume enforcement: when the scheduler starts, every provider
        // has booted and all events exist, so this is the one safe moment to
        // attach dynamic skip filters.
        Event::listen(CommandStarting::class, function (CommandStarting $event) use ($recorder): void {
            if (! in_array($event->command, ['schedule:run', 'schedule:work', 'schedule:test'], true)) {
                return;
            }

            $this->attachSuppressionFilters($recorder());
        });
    }

    private function attachSuppressionFilters(ScheduleRunRecorder $recorder): void
    {
        foreach ($this->app->make(Schedule::class)->events() as $task) {
            $objectId = spl_object_id($task);

            if (isset($this->suppressionFiltersAttached[$objectId])) {
                continue;
            }

            $key = $recorder->key($task);

            $task->skip(fn (): bool => $this->isSuppressedSchedulerKey($key));

            $this->suppressionFiltersAttached[$objectId] = true;
        }
    }

    private function isSuppressedSchedulerKey(string $key): bool
    {
        try {
            return Schema::hasTable('base_schedule_suppressions')
                && ScheduleSuppression::query()
                    ->where('source', 'scheduler')
                    ->where('key', $key)
                    ->exists();
        } catch (Throwable $e) {
            Log::warning('Schedule suppression check failed.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
