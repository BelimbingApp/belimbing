<?php

namespace App\Base\Schedule\Services;

use App\Base\Schedule\Models\ScheduleRun;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
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

    private const OUTPUT_LIMIT = 2000;

    private const RUN_ID_PROPERTY = 'blbScheduleRunId';

    public function __construct(
        private readonly ScheduleHistoryPruner $historyPruner,
    ) {}

    public function taskStarting(ScheduledTaskStarting $event): void
    {
        $this->guard(function () use ($event): void {
            if (! $this->ready()) {
                return;
            }

            $this->prepareOutputPath($event->task);

            $run = ScheduleRun::query()->create([
                'source' => 'scheduler',
                'key' => $this->key($event->task),
                'name' => $this->name($event->task),
                'expression' => $this->expression($event->task),
                'status' => 'running',
                'started_at' => now(),
            ]);

            $event->task->{self::RUN_ID_PROPERTY} = $run->id;
            $this->historyPruner->prune();
        });
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

        $this->complete($event->task, $this->statusFromExitCode($this->exitCode($event->task)), $this->exitCode($event->task), $event->runtime);
    }

    public function backgroundTaskFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $this->complete($event->task, $this->statusFromExitCode($this->exitCode($event->task)), $this->exitCode($event->task));
    }

    public function taskFailed(ScheduledTaskFailed $event): void
    {
        $this->complete($event->task, 'failed', $this->exitCode($event->task) ?? 1, failure: $event->exception->getMessage());
    }

    public function taskSkipped(ScheduledTaskSkipped $event): void
    {
        $this->complete($event->task, 'skipped');
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

    public function deterministicOutputPath(Event $task): string
    {
        return storage_path('logs/schedule-'.sha1($task->mutexName()).'.log');
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
                        'output_excerpt' => $this->mergeOutput($run->output_excerpt, $this->outputExcerpt($task, $failure)),
                    ]);
                }

                return;
            }

            $run->update([
                'status' => $status,
                'finished_at' => $now,
                'exit_code' => $exitCode,
                'runtime_ms' => $runtimeSeconds !== null
                    ? (int) round($runtimeSeconds * 1000)
                    : ($run->started_at !== null ? max(0, (int) $run->started_at->diffInMilliseconds($now)) : null),
                'output_excerpt' => $this->outputExcerpt($task, $failure),
            ]);
        });
    }

    private function resolveRun(Event $task): ScheduleRun
    {
        $runId = $task->{self::RUN_ID_PROPERTY} ?? null;

        if (is_int($runId) || ctype_digit((string) $runId)) {
            $run = ScheduleRun::query()->find((int) $runId);

            if ($run instanceof ScheduleRun) {
                return $run;
            }
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

    private function prepareOutputPath(Event $task): void
    {
        $current = $task->output ?? null;
        $default = $task->getDefaultOutput();

        if (! is_string($current) || $current === '' || $current === $default || in_array(strtolower($current), ['nul', '/dev/null'], true)) {
            $task->sendOutputTo($this->deterministicOutputPath($task));
        }
    }

    private function outputExcerpt(Event $task, ?string $failure): ?string
    {
        return $this->mergeOutput($this->readOutput($task), $failure);
    }

    private function readOutput(Event $task): ?string
    {
        $path = $task->output ?? null;

        if (! is_string($path) || $path === '' || in_array(strtolower($path), ['nul', '/dev/null'], true)) {
            return null;
        }

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $size = filesize($path);
            if (is_int($size) && $size > self::OUTPUT_LIMIT) {
                fseek($handle, -self::OUTPUT_LIMIT, SEEK_END);
            }

            $contents = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        if ($contents === false || trim($contents) === '') {
            return null;
        }

        return $this->truncate(trim($contents), self::OUTPUT_LIMIT);
    }

    private function mergeOutput(?string ...$parts): ?string
    {
        $merged = [];

        foreach ($parts as $part) {
            $part = $part === null ? null : trim($part);

            if ($part !== null && $part !== '' && ! in_array($part, $merged, true)) {
                $merged[] = $part;
            }
        }

        return $merged === [] ? null : $this->truncate(implode("\n", $merged), self::OUTPUT_LIMIT);
    }

    private function expression(Event $task): ?string
    {
        $expression = trim((string) ($task->expression ?? ''));

        return $expression === '' ? null : $this->truncate($expression, 64);
    }

    private function exitCode(Event $task): ?int
    {
        $exitCode = $task->exitCode ?? null;

        return is_int($exitCode) ? $exitCode : (is_numeric($exitCode) ? (int) $exitCode : null);
    }

    private function statusFromExitCode(?int $exitCode): string
    {
        return ($exitCode === null || $exitCode === 0) ? 'succeeded' : 'failed';
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
