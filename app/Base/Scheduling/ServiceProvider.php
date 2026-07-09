<?php

namespace App\Base\Scheduling;

use App\Base\Scheduling\Models\ScheduleSuppression;
use App\Base\Scheduling\Services\ScheduleRunRecorder;
use App\Base\Scheduling\Services\SchedulingBoard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ScheduleRunRecorder::class);
        $this->app->singleton(SchedulingBoard::class);
    }

    /**
     * Record every scheduler execution framework-wide. Modules get run
     * history for free; non-scheduler sources join the board by tagging a
     * SchedulingContributor implementation with `scheduling.contributors`.
     */
    public function boot(): void
    {
        $recorder = fn (): ScheduleRunRecorder => $this->app->make(ScheduleRunRecorder::class);

        Event::listen(ScheduledTaskStarting::class, fn (ScheduledTaskStarting $event) => $recorder()->taskStarting($event));
        Event::listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $event) => $recorder()->taskFinished($event));
        Event::listen(ScheduledBackgroundTaskFinished::class, fn (ScheduledBackgroundTaskFinished $event) => $recorder()->backgroundTaskFinished($event));
        Event::listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $event) => $recorder()->taskFailed($event));

        // Pause/resume enforcement: when the scheduler tick starts, every
        // provider has booted and all events exist, so this is the one safe
        // moment to attach skip filters for suppressed entries.
        Event::listen(CommandStarting::class, function (CommandStarting $event) use ($recorder): void {
            if (! in_array($event->command, ['schedule:run', 'schedule:work', 'schedule:test'], true)) {
                return;
            }

            if (! Schema::hasTable('base_schedule_suppressions')) {
                return;
            }

            $suppressed = ScheduleSuppression::query()->pluck('name')->flip();

            foreach ($this->app->make(Schedule::class)->events() as $task) {
                $name = $recorder()->name($task);

                if ($suppressed->has($name)) {
                    $task->skip(fn (): bool => true);
                }
            }
        });
    }
}
