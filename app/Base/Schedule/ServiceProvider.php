<?php

namespace App\Base\Schedule;

use App\Base\Schedule\Console\Commands\PruneScheduleHistoryCommand;
use App\Base\Schedule\Services\ScheduleHistoryPruner;
use App\Base\Schedule\Services\ScheduleRunRecorder;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/schedule.php', 'schedule');

        $this->app->singleton(ScheduleRunRecorder::class);
        $this->app->singleton(ScheduleHistoryPruner::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneScheduleHistoryCommand::class,
            ]);
        }

        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event): void {
            app(ScheduleRunRecorder::class)->rememberStarting($event->task);
        });

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            app(ScheduleRunRecorder::class)->rememberFinished($event->task, $event->runtime);
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            app(ScheduleRunRecorder::class)->rememberFailed($event->task, $event->exception);
        });

        Event::listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event): void {
            app(ScheduleRunRecorder::class)->rememberSkipped($event->task);
        });

        Event::listen(ScheduledBackgroundTaskFinished::class, function (ScheduledBackgroundTaskFinished $event): void {
            app(ScheduleRunRecorder::class)->rememberBackgroundFinished($event->task);
        });

        // Register on booted() so the admin Scheduled Tasks page sees this
        // command without requiring Artisan's withSchedule() path.
        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('blb:schedule:history:prune')
                ->dailyAt('03:15')
                ->withoutOverlapping();
        });
    }
}
