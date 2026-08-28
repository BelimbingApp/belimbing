<?php

namespace App\Base\Schedule\Jobs;

use App\Base\Schedule\Exceptions\ScheduledTaskExecutionException;
use App\Base\Schedule\Models\ScheduleSuppression;
use App\Base\Schedule\Services\ScheduleRunRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
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
        public readonly ?int $runId = null,
    ) {}

    public function handle(
        Schedule $schedule,
        ScheduleRunRecorder $recorder,
        Dispatcher $dispatcher,
        ExceptionHandler $exceptions,
    ): void {
        app(ConsoleKernel::class)->all();

        $event = $recorder->findEvent($schedule, $this->key);

        if ($event === null) {
            if ($this->runId !== null) {
                $recorder->failUnstartedRun($this->runId, __('This task is no longer registered — its schedule entry may have been renamed or removed.'));
            }

            throw ScheduledTaskExecutionException::notRegistered($this->key);
        }

        // Carries the pre-created `queued` row (if any) onto the Event so
        // taskStarting()/taskSkipped() below transition that same row
        // instead of writing a second, disconnected one (#401).
        if ($this->runId !== null) {
            $recorder->attachRun($event, $this->runId);
        }

        if ($this->suppressed()) {
            // Recorded directly, with a reason, before the generic event
            // dispatch below — whichever handler runs first "wins" the row,
            // and only this call carries the specific reason text.
            $recorder->taskSkipped(new ScheduledTaskSkipped($event), __('Skipped — this task is currently paused.'));
            $dispatcher->dispatch(new ScheduledTaskSkipped($event));

            return;
        }

        // withoutOverlapping() registers its overlap check as a skip filter
        // (see ManagesAttributes::withoutOverlapping()), so the generic
        // filtersPass() check below already rejects it — check it first,
        // by name, so the operator gets "overlap protection" instead of a
        // generic "schedule conditions" message for the single most likely
        // reason a manual re-run gets rejected (#401).
        if ($event->withoutOverlapping && $event->mutex->exists($event)) {
            $recorder->taskSkipped(new ScheduledTaskSkipped($event), __('Skipped — another run of this task is already in progress (overlap protection).'));
            $dispatcher->dispatch(new ScheduledTaskSkipped($event));

            return;
        }

        if (! $event->filtersPass(app())) {
            $recorder->taskSkipped(new ScheduledTaskSkipped($event), __('Skipped — the task\'s own schedule conditions rejected this run.'));
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
                $recorder->taskSkipped(new ScheduledTaskSkipped($event), __('Skipped — another run of this task is already in progress (overlap protection).'));
                $dispatcher->dispatch(new ScheduledTaskSkipped($event));

                return;
            }

            $dispatcher->dispatch(new ScheduledTaskFinished(
                $event,
                round(microtime(true) - $start, 2),
            ));

            if ($event->exitCode !== null && $event->exitCode !== 0) {
                throw ScheduledTaskExecutionException::failed($event, $recorder->name($event));
            }
        } catch (Throwable $e) {
            $dispatcher->dispatch(new ScheduledTaskFailed($event, $e));
            $exceptions->report($e);

            throw $e;
        } finally {
            $event->runInBackground = $wasBackground;
        }
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
