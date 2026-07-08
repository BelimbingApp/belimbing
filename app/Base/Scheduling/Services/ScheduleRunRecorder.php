<?php

namespace App\Base\Scheduling\Services;

use App\Base\Scheduling\Models\ScheduleRun;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schema;

/**
 * Records every Laravel scheduler execution - all modules, zero per-module
 * wiring - into base_schedule_runs. Foreground tasks complete on
 * ScheduledTaskFinished; background tasks complete on
 * ScheduledBackgroundTaskFinished (their immediate Finished event only marks
 * process launch). Start and finish may happen in different processes, so
 * the running row is the bridge.
 */
class ScheduleRunRecorder
{
    private const RETENTION_DAYS = 90;

    public function taskStarting(ScheduledTaskStarting $event): void
    {
        if (! $this->ready()) {
            return;
        }

        ScheduleRun::query()->create([
            'source' => 'scheduler',
            'name' => $this->name($event->task),
            'status' => 'running',
            'started_at' => now(),
        ]);

        // Opportunistic retention pruning; volumes are tiny at this cadence.
        ScheduleRun::query()->where('started_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
    }

    public function taskFinished(ScheduledTaskFinished $event): void
    {
        if ($event->task->runInBackground) {
            return; // completion arrives via ScheduledBackgroundTaskFinished
        }

        $this->complete($event->task, $event->task->exitCode);
    }

    public function backgroundTaskFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $this->complete($event->task, $event->task->exitCode);
    }

    public function taskFailed(ScheduledTaskFailed $event): void
    {
        $this->complete($event->task, 1, $event->exception->getMessage());
    }

    private function complete(Event $task, ?int $exitCode, ?string $failure = null): void
    {
        if (! $this->ready()) {
            return;
        }

        $run = ScheduleRun::query()
            ->where('source', 'scheduler')
            ->where('name', $this->name($task))
            ->where('status', 'running')
            ->orderByDesc('started_at')
            ->first() ?? ScheduleRun::query()->create([
                'source' => 'scheduler',
                'name' => $this->name($task),
                'status' => 'running',
                'started_at' => now(),
            ]);

        $succeeded = $failure === null && ($exitCode === null || $exitCode === 0);

        $run->update([
            'status' => $succeeded ? 'succeeded' : 'failed',
            'finished_at' => now(),
            'exit_code' => $exitCode,
            'output_excerpt' => $this->outputExcerpt($task, $failure),
        ]);
    }

    /**
     * The artisan command (or description) without the PHP binary noise.
     */
    public function name(Event $task): string
    {
        $command = (string) $task->command;

        if ($command === '') {
            return $task->description ?: 'closure';
        }

        $artisan = str($command)->after('artisan');

        return trim(str_replace(['"', "'"], '', $artisan->value() !== $command ? $artisan->value() : $command));
    }

    private function outputExcerpt(Event $task, ?string $failure): ?string
    {
        if ($failure !== null) {
            return mb_substr($failure, 0, 2000);
        }

        try {
            $path = $task->output;

            if (is_string($path) && $path !== '' && ! in_array($path, ['/dev/null', 'NUL'], true) && is_file($path)) {
                $content = trim((string) file_get_contents($path));

                return $content === '' ? null : mb_substr($content, -2000);
            }
        } catch (\Throwable) {
            // Output capture is best-effort; the run row itself is the record.
        }

        return null;
    }

    private function ready(): bool
    {
        return Schema::hasTable('base_schedule_runs');
    }
}
