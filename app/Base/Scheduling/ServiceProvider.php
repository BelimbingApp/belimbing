<?php

namespace App\Base\Scheduling;

use App\Base\Scheduling\Services\ScheduleRunRecorder;
use App\Base\Scheduling\Services\SchedulingBoard;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Facades\Event;
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
    }
}
