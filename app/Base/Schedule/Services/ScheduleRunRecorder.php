<?php

namespace App\Base\Schedule\Services;

use App\Base\Schedule\DTO\ScheduleRunReservation;
use App\Base\Schedule\Models\ScheduleRun;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
            //
            // But only while it's still open: a worker can recover a job
            // *after* its attached row was already reconciled to failed by
            // the staleness window or explicitly superseded by an operator
            // (#407 review, fable) — transitioning a terminal row back to
            // `running` would silently erase that failed/superseded record
            // the moment the slow worker finally shows up, which is exactly
            // the kind of retroactive lie this whole mechanism exists to
            // prevent. A terminal attached row means the execution it once
            // tracked is no longer the same story as the one now starting,
            // so this gets a fresh row of its own — two honest records
            // instead of one falsified one, matching complete()'s own
            // finished_at guard for the same reason.
            $existing = $this->existingRun($event->task);

            if ($existing instanceof ScheduleRun && $existing->finished_at === null) {
                $existing->update([
                    'status' => 'running',
                    'started_at' => now(),
                ]);
                $run = $existing;
            } else {
                // A terminal $existing row still means a worker eventually
                // did pick this job up — the fresh row it earns is honestly
                // that same manually-requested run finally executing late,
                // not a fresh scheduler tick, so it keeps the same
                // trigger/actor provenance rather than reading as anonymous.
                $run = ScheduleRun::query()->create([
                    'source' => 'scheduler',
                    'trigger' => $existing?->trigger ?? 'scheduled',
                    'triggered_by_user_id' => $existing?->triggered_by_user_id,
                    'triggered_by_name' => $existing?->triggered_by_name,
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
     * Atomically decides and performs a manual "Run now" dispatch — the
     * active-row check, any stale/supersede reconciliation, and the queued
     * row insert are one operation under a per-key row lock, rather than
     * separate reads and writes a second concurrent request could interleave
     * with (#407 review, luna's P1 / terra's implementation guidance).
     *
     * A per-key row in base_schedule_run_gates (created on first use,
     * never deleted) gives every reservation attempt for the same key a
     * stable target to serialize on via SELECT ... FOR UPDATE — the gate
     * row carries no state of its own. Only exactly one concurrent caller
     * for a key ever gets a `created` reservation; the rest get `refused`
     * having mutated nothing.
     */
    public function reserveManualRun(
        string $key,
        string $name,
        ?string $expression,
        ?int $triggeredByUserId,
        ?string $triggeredByName,
        bool $force,
    ): ScheduleRunReservation {
        if (! $this->ready() || ! Schema::hasTable('base_schedule_run_gates')) {
            return ScheduleRunReservation::refused();
        }

        return DB::transaction(function () use ($key, $name, $expression, $triggeredByUserId, $triggeredByName, $force): ScheduleRunReservation {
            // A bounded wait, not a permanent hang: if the gate row is held
            // long enough to hit this, something is genuinely wrong (a
            // stuck transaction elsewhere), and a manual "Run now" request
            // should fail fast with a clear error rather than tie up a web
            // worker indefinitely — same instinct as the DataShare mirror's
            // own lock_timeout. SQLite (tests, single-writer already) has
            // no such GUC.
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL lock_timeout = '5s'");
            }

            DB::table('base_schedule_run_gates')->insertOrIgnore([
                'source' => 'scheduler',
                'key' => $key,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('base_schedule_run_gates')
                ->where('source', 'scheduler')
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            // Locked alongside the gate row: no other reservation attempt
            // for this key can be mid-decision while this one reads it.
            $active = ScheduleRun::query()
                ->where('source', 'scheduler')
                ->where('key', $key)
                ->whereIn('status', ['queued', 'running'])
                ->lockForUpdate()
                ->orderByDesc('started_at')
                ->first();

            if ($active instanceof ScheduleRun && $active->status === 'queued' && $this->isStale($active)) {
                $this->terminalizeOpenRow($active, 'failed', __(
                    'This run was queued but no worker picked it up within :minutes minutes — the queue connection or a worker may be down. Check queue health, then try again.',
                    ['minutes' => self::QUEUE_PICKUP_STALE_AFTER_MINUTES],
                ));
                $active = null;
            }

            if ($active instanceof ScheduleRun) {
                if (! $force || ! $this->isStale($active)) {
                    return ScheduleRunReservation::refused();
                }

                // Supersedes the exact row this transaction locked and
                // validated as stale — never a re-query by key, which
                // could target a different row a concurrent request had
                // since inserted (terra's finding on the Force path).
                $this->terminalizeOpenRow($active, 'failed', $triggeredByName !== null
                    ? __('Superseded by a new manual run requested by :name.', ['name' => $triggeredByName])
                    : __('Superseded by a new manual run.'));
            }

            $run = ScheduleRun::query()->create([
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

            return ScheduleRunReservation::created($run);
        });
    }

    /**
     * How long a `queued` row may sit unpicked before a stalled queue or
     * dead worker is the more honest explanation than "still waiting" —
     * and, since #401 review, the bound that keeps an unpicked queue from
     * becoming a *permanent* lock on the Run now control (there was no
     * such row, and so no such lock, before this queued state existed).
     * Applies only to `queued`: task runtimes vary too widely to guess a
     * safe universal timeout for `running` without risking the worse lie
     * of declaring a healthy long-running task dead — see
     * supersedeActiveRun() for the operator-driven escape instead.
     */
    public const int QUEUE_PICKUP_STALE_AFTER_MINUTES = 5;

    /**
     * The row currently blocking a new manual dispatch for this key, if
     * any. A `queued` row past the pickup window is reconciled to an
     * honest `failed` state as a side effect (mirrors
     * DeploymentRunHistory::abandonStalePendingRun()'s "close what nothing
     * is going to finish" reconciliation) rather than counted as active,
     * so a stalled queue self-heals instead of locking the control forever.
     */
    public function activeRun(string $key): ?ScheduleRun
    {
        $run = $this->activeRunRow($key);

        if (! $run instanceof ScheduleRun) {
            return null;
        }

        if ($run->status === 'queued' && $this->isStale($run)) {
            $this->failUnstartedRun($run->id, __(
                'This run was queued but no worker picked it up within :minutes minutes — the queue connection or a worker may be down. Check queue health, then try again.',
                ['minutes' => self::QUEUE_PICKUP_STALE_AFTER_MINUTES],
            ));

            return null;
        }

        return $run;
    }

    /**
     * True while a scheduler key already has a queued or running row —
     * used to refuse a duplicate manual dispatch rather than stacking two
     * concurrent "Run now" requests for the same task (#401).
     */
    public function hasActiveRun(string $key): bool
    {
        return $this->activeRun($key) !== null;
    }

    /**
     * Lets a capable operator close out a stuck queued/running row and
     * dispatch a fresh one, rather than a stalled worker locking the
     * control forever with only database access as a way out. The
     * superseded row is marked `failed` — truthfully, by name, never
     * silently discarded — recording who chose to move past it (#401
     * follow-up: a queued/running row is a new kind of state this PR
     * introduces, so a new kind of permanent lock is a regression it must
     * not ship without an escape).
     */
    public function supersedeActiveRun(string $key, ?string $supersededByName): void
    {
        $run = $this->activeRunRow($key);

        if (! $run instanceof ScheduleRun) {
            return;
        }

        $this->failUnstartedRun($run->id, $supersededByName !== null
            ? __('Superseded by a new manual run requested by :name.', ['name' => $supersededByName])
            : __('Superseded by a new manual run.'));
    }

    /**
     * Whether an active row is old enough that a manual override is worth
     * offering — mirrors DeploymentRecoveryController only allowing
     * recovery once a run's lease has actually expired, rather than an
     * operator being able to supersede a run that started seconds ago.
     */
    public function activeRunLooksStuck(ScheduleRun $run): bool
    {
        return $this->isStale($run);
    }

    private function isStale(ScheduleRun $run): bool
    {
        return $run->started_at !== null
            && $run->started_at->lt(now()->subMinutes(self::QUEUE_PICKUP_STALE_AFTER_MINUTES));
    }

    private function activeRunRow(string $key): ?ScheduleRun
    {
        if (! $this->ready()) {
            return null;
        }

        return ScheduleRun::query()
            ->where('source', 'scheduler')
            ->where('key', $key)
            ->whereIn('status', ['queued', 'running'])
            ->orderByDesc('started_at')
            ->first();
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

            if (! $run instanceof ScheduleRun) {
                return;
            }

            $this->terminalizeOpenRow($run, 'failed', $reason);
        });
    }

    /**
     * Closes an already-loaded row in place — never a re-query by id or
     * key — so a caller that already validated and locked a specific row
     * (reserveManualRun()) terminalizes exactly that row, not whatever
     * currently matches its key (#407 review, terra).
     */
    private function terminalizeOpenRow(ScheduleRun $run, string $status, string $reason): void
    {
        if ($run->finished_at !== null) {
            return;
        }

        $now = now();

        $run->update([
            'status' => $status,
            'finished_at' => $now,
            'runtime_ms' => $this->runtimeMs($run, $now, null),
            'output_excerpt' => $this->truncate($reason, self::STORAGE_STRING_LIMIT),
        ]);
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
