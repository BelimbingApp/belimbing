<?php

namespace App\Base\Schedule\Services;

use App\Base\Schedule\Models\ScheduleRun;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Records every Laravel scheduler execution into one append-style ledger.
 * The Event instance carries the run id inside one PHP process; background
 * finishes fall back to the latest running row for the same stable key.
 */
class ScheduleRunRecorder
{
    private const STORAGE_STRING_LIMIT = 255;

    private const RUN_ID_PROPERTY = 'blbScheduleRunId';

    public function __construct(
        private readonly ScheduleHistoryPruner $historyPruner,
        private readonly ScheduleRunOutput $output,
    ) {}

    public function taskStarting(ScheduledTaskStarting $event): void
    {
        $this->guard(function () use ($event): void {
            if (! $this->ready()) {
                return;
            }

            $this->output->prepare($event->task);

            // A manual "Run now" click already created a `queued` row before
            // this event ever fired (#401) — transition that same row rather
            // than creating a second one, so the operator sees one continuous
            // record instead of an orphaned queued row plus a fresh running one.
            $existing = $this->existingRun($event->task);

            if ($existing instanceof ScheduleRun) {
                $existing->update([
                    'status' => 'running',
                    'started_at' => now(),
                ]);
                $run = $existing;
            } else {
                $run = ScheduleRun::query()->create([
                    'source' => 'scheduler',
                    'key' => $this->key($event->task),
                    'name' => $this->name($event->task),
                    'expression' => $this->expression($event->task),
                    'status' => 'running',
                    'started_at' => now(),
                ]);
            }

            $event->task->{self::RUN_ID_PROPERTY} = $run->id;
            $this->historyPruner->prune();
        });
    }

    /**
     * Creates the durable `queued` row an operator's "Run now" click needs to
     * exist *before* dispatch returns — the whole point being that a job that
     * never reaches ScheduledTaskStarting (queue down, worker never picks it
     * up) still leaves observable, honest state instead of nothing (#401).
     */
    public function queueManualRun(
        string $key,
        string $name,
        ?string $expression,
        ?int $triggeredByUserId,
        ?string $triggeredByName,
    ): ?ScheduleRun {
        if (! $this->ready()) {
            return null;
        }

        return ScheduleRun::query()->create([
            'source' => 'scheduler',
            'trigger' => 'manual',
            'triggered_by_user_id' => $triggeredByUserId,
            'triggered_by_name' => $triggeredByName,
            'key' => $key,
            'name' => $name,
            'expression' => $expression,
            'status' => 'queued',
            'started_at' => now(),
        ]);
    }

    /**
     * True while a scheduler key already has a queued or running row —
     * used to refuse a duplicate manual dispatch rather than stacking two
     * concurrent "Run now" requests for the same task (#401).
     */
    public function hasActiveRun(string $key): bool
    {
        if (! $this->ready()) {
            return false;
        }

        return ScheduleRun::query()
            ->where('source', 'scheduler')
            ->where('key', $key)
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }

    /**
     * Marks a run that never reached ScheduledTaskStarting as failed —
     * covers "the key isn't registered" and "the job itself couldn't be
     * dispatched", neither of which ever produces a ScheduledTaskFailed
     * event because no Event instance exists yet (#401).
     */
    public function failUnstartedRun(int $runId, string $reason): void
    {
        $this->guard(function () use ($runId, $reason): void {
            if (! $this->ready()) {
                return;
            }

            $run = ScheduleRun::query()->find($runId);

            if (! $run instanceof ScheduleRun || $run->finished_at !== null) {
                return;
            }

            $now = now();

            $run->update([
                'status' => 'failed',
                'finished_at' => $now,
                'runtime_ms' => $this->runtimeMs($run, $now, null),
                'output_excerpt' => $this->truncate($reason, self::STORAGE_STRING_LIMIT),
            ]);
        });
    }

    /**
     * Attaches a pre-created run row (typically a `queued` row from
     * queueManualRun()) to the Event instance the job is about to execute,
     * so taskStarting()/taskSkipped() below transition that same row
     * instead of creating a disconnected one (#401).
     */
    public function attachRun(Event $task, int $runId): void
    {
        $task->{self::RUN_ID_PROPERTY} = $runId;
    }

    /**
     * The same "find the registered scheduler event for this stable key"
     * lookup the manual-run job needs — shared so the Livewire action and
     * the job agree on what "not registered" means (#401).
     */
    public function findEvent(Schedule $schedule, string $key): ?Event
    {
        foreach ($schedule->events() as $event) {
            if ($this->key($event) === $key) {
                return $event;
            }
        }

        return null;
    }

    public function taskFinished(ScheduledTaskFinished $event): void
    {
        if ($event->task->runInBackground) {
            return;
        }

        if ($event->task->skippedBecauseOverlapping) {
            $this->complete($event->task, 'skipped');

            return;
        }

        $exitCode = $this->exitCode($event->task);

        $this->complete($event->task, $exitCode === null || $exitCode === 0 ? 'succeeded' : 'failed', $exitCode, $event->runtime);
    }

    public function backgroundTaskFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $exitCode = $this->exitCode($event->task);

        $this->complete($event->task, $exitCode === null || $exitCode === 0 ? 'succeeded' : 'failed', $exitCode);
    }

    public function taskFailed(ScheduledTaskFailed $event): void
    {
        $this->complete($event->task, 'failed', $this->exitCode($event->task) ?? 1, failure: $event->exception->getMessage());
    }

    public function taskSkipped(ScheduledTaskSkipped $event, ?string $reason = null): void
    {
        $this->complete($event->task, 'skipped', failure: $reason);
    }

    /**
     * The artisan command or callback description without PHP/artisan wrapper noise.
     */
    public function name(Event $task): string
    {
        $command = $this->normalizeCommand((string) $task->command);

        if ($command !== '') {
            return $this->truncate($command, self::STORAGE_STRING_LIMIT);
        }

        $summary = trim((string) $task->getSummaryForDisplay());

        return $this->truncate($summary !== '' ? $summary : 'closure', self::STORAGE_STRING_LIMIT);
    }

    /**
     * Stable scheduler identity for storage and actions. Names can be display
     * text; keys are the thing we match across runs.
     */
    public function key(Event $task): string
    {
        $command = $this->normalizeCommand((string) $task->command);

        if ($command !== '') {
            return $this->stableKey($command);
        }

        return 'callback:'.sha1($task->mutexName().'|'.$this->name($task));
    }

    public function normalizeCommand(string $rawCommand): string
    {
        $command = trim($rawCommand, " \t\n\r\0\x0B'\"");
        $command = preg_replace('/^.*["\']?(?:artisan|artisan\.bat)["\']?\s+/i', '', $command) ?? $command;
        $command = trim($command, " \t\n\r\0\x0B'\"");

        return preg_replace('/\s+/', ' ', trim($command)) ?? trim($command);
    }

    private function complete(
        Event $task,
        string $status,
        ?int $exitCode = null,
        ?float $runtimeSeconds = null,
        ?string $failure = null,
    ): void {
        $this->guard(function () use ($task, $status, $exitCode, $runtimeSeconds, $failure): void {
            if (! $this->ready()) {
                return;
            }

            $run = $this->resolveRun($task);
            $now = now();

            if ($run->finished_at !== null) {
                if ($status === 'failed' && $run->status === 'failed') {
                    $run->update([
                        'exit_code' => $exitCode,
                        'output_excerpt' => $this->output->merge($run->output_excerpt, $this->output->excerpt($task, $failure)),
                    ]);
                }

                return;
            }

            $run->update([
                'status' => $status,
                'finished_at' => $now,
                'exit_code' => $exitCode,
                'runtime_ms' => $this->runtimeMs($run, $now, $runtimeSeconds),
                'output_excerpt' => $this->output->excerpt($task, $failure),
            ]);
        });
    }

    private function existingRun(Event $task): ?ScheduleRun
    {
        $runId = $task->{self::RUN_ID_PROPERTY} ?? null;

        if (is_int($runId) || ctype_digit((string) $runId)) {
            $run = ScheduleRun::query()->find((int) $runId);

            if ($run instanceof ScheduleRun) {
                return $run;
            }
        }

        return null;
    }

    private function resolveRun(Event $task): ScheduleRun
    {
        $existing = $this->existingRun($task);

        if ($existing instanceof ScheduleRun) {
            return $existing;
        }

        $key = $this->key($task);

        $run = ScheduleRun::query()
            ->where('source', 'scheduler')
            ->where('key', $key)
            ->where('status', 'running')
            ->orderByDesc('started_at')
            ->first();

        if ($run instanceof ScheduleRun) {
            $task->{self::RUN_ID_PROPERTY} = $run->id;

            return $run;
        }

        $run = ScheduleRun::query()->create([
            'source' => 'scheduler',
            'key' => $key,
            'name' => $this->name($task),
            'expression' => $this->expression($task),
            'status' => 'running',
            'started_at' => now(),
        ]);
        $task->{self::RUN_ID_PROPERTY} = $run->id;

        return $run;
    }

    private function expression(Event $task): ?string
    {
        $expression = trim((string) ($task->expression ?? ''));

        return $expression === '' ? null : $this->truncate($expression, 64);
    }

    private function exitCode(Event $task): ?int
    {
        $exitCode = $task->exitCode ?? null;

        if (is_int($exitCode)) {
            return $exitCode;
        }

        return is_numeric($exitCode) ? (int) $exitCode : null;
    }

    private function truncate(string $value, int $limit): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }

    private function stableKey(string $value): string
    {
        if (mb_strlen($value) <= self::STORAGE_STRING_LIMIT) {
            return $value;
        }

        return mb_substr($value, 0, self::STORAGE_STRING_LIMIT - 41).':'.sha1($value);
    }

    private function runtimeMs(ScheduleRun $run, Carbon $now, ?float $runtimeSeconds): ?int
    {
        if ($runtimeSeconds !== null) {
            return (int) round($runtimeSeconds * 1000);
        }

        return $run->started_at !== null
            ? max(0, (int) $run->started_at->diffInMilliseconds($now))
            : null;
    }

    private function ready(): bool
    {
        return Schema::hasTable('base_schedule_runs');
    }

    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning('Schedule run recorder failed.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
