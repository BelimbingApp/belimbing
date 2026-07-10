<?php

namespace App\Base\Schedule\Jobs;

use App\Base\Schedule\Models\ScheduleSuppression;
use App\Base\Schedule\Services\ScheduleRunRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Runs one already-registered Laravel scheduler event from the Schedule page.
 */
class RunScheduledTaskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $key,
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
            throw new RuntimeException("Scheduled task [{$this->key}] is not registered.");
        }

        if ($this->suppressed()) {
            $dispatcher->dispatch(new ScheduledTaskSkipped($event));

            return;
        }

        if (! $event->filtersPass(app())) {
            $dispatcher->dispatch(new ScheduledTaskSkipped($event));

            return;
        }

        $dispatcher->dispatch(new ScheduledTaskStarting($event));

        $start = microtime(true);
        $wasBackground = $event->runInBackground;
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

            if ($event->exitCode !== null && $event->exitCode !== 0) {
                throw new RuntimeException(
                    "Scheduled task [{$recorder->name($event)}] failed with exit code [{$event->exitCode}]."
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
            if ($recorder->key($event) === $this->key) {
                return $event;
            }
        }

        return null;
    }

    private function suppressed(): bool
    {
        if (! Schema::hasTable('base_schedule_suppressions')) {
            return false;
        }

        return ScheduleSuppression::query()
            ->where('source', 'scheduler')
            ->where('key', $this->key)
            ->exists();
    }
}
