<?php

namespace App\Base\Schedule\Jobs;

use App\Base\Schedule\Services\ScheduleRunRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Runs one registered schedule event from the admin UI.
 *
 * Honors filtersPass / pause skips like schedule:run. Forces foreground so
 * finish events fire in this worker. Does not claim full schedule:run parity
 * for every edge case (e.g. onOneServer mutex ownership).
 */
class RunScheduledTaskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $commandKey,
    ) {}

    public function handle(
        Schedule $schedule,
        ScheduleRunRecorder $recorder,
        Dispatcher $dispatcher,
        ExceptionHandler $exceptions,
    ): void {
        app(ConsoleKernel::class)->all();

        $event = $this->findEvent($schedule, $recorder);

        if ($event === null) {
            throw new RuntimeException("Scheduled command [{$this->commandKey}] is not registered.");
        }

        if (! $event->filtersPass(app())) {
            $dispatcher->dispatch(new ScheduledTaskSkipped($event));

            return;
        }

        $dispatcher->dispatch(new ScheduledTaskStarting($event));

        $start = microtime(true);
        $wasBackground = $event->runInBackground;
        // Manual runs must finish in this worker so last-run status is recorded;
        // schedule:run's background path defers finish to another process.
        $event->runInBackground = false;

        try {
            $event->run(app());

            if ($event->skippedBecauseOverlapping) {
                $dispatcher->dispatch(new ScheduledTaskSkipped($event));

                return;
            }

            $dispatcher->dispatch(new ScheduledTaskFinished(
                $event,
                round(microtime(true) - $start, 2),
            ));

            if ($event->exitCode != 0) {
                throw new RuntimeException(
                    "Scheduled command [{$event->command}] failed with exit code [{$event->exitCode}]."
                );
            }
        } catch (Throwable $e) {
            $dispatcher->dispatch(new ScheduledTaskFailed($event, $e));
            $exceptions->report($e);

            throw $e;
        } finally {
            $event->runInBackground = $wasBackground;
        }
    }

    private function findEvent(Schedule $schedule, ScheduleRunRecorder $recorder): ?Event
    {
        foreach ($schedule->events() as $event) {
            if ($event instanceof CallbackEvent) {
                continue;
            }

            if ($recorder->normalizeCommand((string) $event->command) === $this->commandKey) {
                return $event;
            }
        }

        return null;
    }
}
