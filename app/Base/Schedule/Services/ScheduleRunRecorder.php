<?php

namespace App\Base\Schedule\Services;

use App\Base\Schedule\Models\ScheduleRun;
use App\Base\Schedule\Models\ScheduleRunHistory;
use App\Base\Schedule\Support\ScheduleRunStatuses;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ScheduleRunRecorder
{
    private const OUTPUT_LIMIT = 8000;

    private const ATTEMPT_KEY_PROPERTY = 'blbScheduleAttemptKey';

    /**
     * Strip Laravel's scheduler command wrapper down to the artisan command
     * users recognize on the Scheduled Tasks page.
     */
    public function normalizeCommand(string $rawCommand): string
    {
        $command = trim($rawCommand);
        $command = trim($command, "'\"");
        $command = preg_replace('/^.*["\']?(?:artisan|artisan\.bat)["\']?\s+/i', '', $command) ?? $command;
        $command = trim($command, "'\"");

        return preg_replace('/\s+/', ' ', trim($command)) ?? trim($command);
    }

    public function deterministicOutputPath(Event $task): string
    {
        return storage_path('logs/schedule-'.sha1($task->mutexName()).'.log');
    }

    public function isCommandRunning(string $commandKey): bool
    {
        $commandKey = $this->normalizeCommand($commandKey);

        return ScheduleRun::query()
            ->where('command_key', $commandKey)
            ->where('status', ScheduleRunStatuses::RUNNING)
            ->exists()
            || ScheduleRunHistory::query()
                ->where('command_key', $commandKey)
                ->where('status', ScheduleRunStatuses::RUNNING)
                ->exists();
    }

    public function rememberStarting(Event $task): void
    {
        $this->guard(function () use ($task): void {
            $task->storeOutput();
            // Each Starting is a new attempt, even if the same Event instance is reused.
            unset($task->{self::ATTEMPT_KEY_PROPERTY});
            $attemptKey = $this->bindAttemptKey($task);

            $attributes = [
                'attempt_key' => $attemptKey,
                'status' => ScheduleRunStatuses::RUNNING,
                'exit_code' => null,
                'runtime_ms' => null,
                'output' => null,
                'started_at' => now(),
                'finished_at' => null,
            ];

            $this->upsertLastRun($task, $attributes);
            $this->insertHistory($task, $attributes);
        });
    }

    public function rememberFinished(Event $task, float $runtimeSeconds): void
    {
        $this->guard(function () use ($task, $runtimeSeconds): void {
            if ($task->skippedBecauseOverlapping) {
                $this->completeAsSkipped($task);

                return;
            }

            $exitCode = $this->exitCode($task);
            $runtimeMs = (int) round($runtimeSeconds * 1000);
            $status = $this->statusFromExitCode($exitCode);

            $attributes = [
                'status' => $status,
                'exit_code' => $exitCode,
                'runtime_ms' => $runtimeMs,
                'output' => $this->readOutput($task),
                'started_at' => $this->startedAt($task, $runtimeMs),
                'finished_at' => now(),
            ];

            $this->upsertLastRun($task, $attributes);
            $this->completeHistory($task, $attributes);
        });
    }

    public function rememberFailed(Event $task, Throwable $e): void
    {
        $this->guard(function () use ($task, $e): void {
            $attributes = [
                'status' => ScheduleRunStatuses::FAILED,
                'exit_code' => $this->exitCode($task),
                'runtime_ms' => null,
                'output' => $this->mergeOutput($this->readOutput($task), $e->getMessage()),
                'started_at' => $this->startedAt($task),
                'finished_at' => now(),
            ];

            $this->upsertLastRun($task, $attributes);
            $this->completeHistory($task, $attributes, enrichTerminalFailed: true);
        });
    }

    public function rememberSkipped(Event $task): void
    {
        $this->guard(function () use ($task): void {
            $this->completeAsSkipped($task);
        });
    }

    public function rememberBackgroundFinished(Event $task): void
    {
        $this->guard(function () use ($task): void {
            $this->adoptAttemptFromLastRun($task);
            $this->restoreDeterministicOutputPath($task);
            $exitCode = $this->exitCode($task);

            $attributes = [
                'attempt_key' => $this->boundAttemptKey($task),
                'status' => $this->statusFromExitCode($exitCode),
                'exit_code' => $exitCode,
                'runtime_ms' => null,
                'output' => $this->readOutput($task),
                'started_at' => $this->startedAt($task),
                'finished_at' => now(),
            ];

            $this->upsertLastRun($task, array_filter(
                $attributes,
                fn (mixed $value, string $key): bool => $key !== 'attempt_key' || $value !== null,
                ARRAY_FILTER_USE_BOTH,
            ));
            $this->completeHistory($task, $attributes);
        });
    }

    /**
     * @return Collection<string, ScheduleRun>
     */
    public function lastRunsByCommandKey(): Collection
    {
        return ScheduleRun::query()
            ->get()
            ->keyBy('command_key');
    }

    public function forCommand(string $commandKey): ?ScheduleRun
    {
        return ScheduleRun::query()
            ->where('command_key', $this->normalizeCommand($commandKey))
            ->first();
    }

    private function completeAsSkipped(Event $task): void
    {
        $now = now();

        $attributes = [
            'status' => ScheduleRunStatuses::SKIPPED,
            'exit_code' => null,
            'runtime_ms' => null,
            'output' => null,
            'started_at' => $now,
            'finished_at' => $now,
        ];

        $this->upsertLastRun($task, $attributes);

        if ($this->boundAttemptKey($task) !== null || $this->findRunningHistory($task) !== null) {
            $this->completeHistory($task, $attributes);

            return;
        }

        $this->insertHistory($task, [
            'attempt_key' => $this->bindAttemptKey($task),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertLastRun(Event $task, array $attributes): void
    {
        $commandKey = $this->normalizeCommand($task->command);

        ScheduleRun::query()->updateOrCreate(
            ['command_key' => $commandKey],
            [
                'command' => $commandKey,
                'expression' => $this->truncate((string) ($task->expression ?? ''), 64),
                ...$attributes,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertHistory(Event $task, array $attributes): void
    {
        $commandKey = $this->normalizeCommand($task->command);
        $attemptKey = (string) ($attributes['attempt_key'] ?? $this->bindAttemptKey($task));

        ScheduleRunHistory::query()->create([
            'command_key' => $commandKey,
            'command' => $commandKey,
            'expression' => $this->truncate((string) ($task->expression ?? ''), 64),
            ...$attributes,
            'attempt_key' => $attemptKey,
        ]);
    }

    /**
     * Complete the attempt identified by attempt_key. Failed after Finished
     * enriches the same terminal row instead of inserting a duplicate.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function completeHistory(Event $task, array $attributes, bool $enrichTerminalFailed = false): void
    {
        $history = $this->resolveHistoryForCompletion($task);

        if ($history !== null) {
            if (ScheduleRunStatuses::isTerminal($history->status) && ! $enrichTerminalFailed) {
                return;
            }

            if (
                $enrichTerminalFailed
                && ScheduleRunStatuses::isTerminal($history->status)
                && $history->status !== ScheduleRunStatuses::FAILED
                && $history->status !== ScheduleRunStatuses::RUNNING
            ) {
                // Do not overwrite succeeded/skipped with a late Failed.
                return;
            }

            if ($enrichTerminalFailed && $history->status === ScheduleRunStatuses::FAILED) {
                $attributes['output'] = $this->mergeOutput($history->output, (string) ($attributes['output'] ?? ''));
                if ($history->runtime_ms !== null && ($attributes['runtime_ms'] ?? null) === null) {
                    $attributes['runtime_ms'] = $history->runtime_ms;
                }
                if ($history->started_at !== null) {
                    $attributes['started_at'] = $history->started_at;
                }
            }

            $history->fill(array_diff_key($attributes, ['attempt_key' => true]))->save();

            return;
        }

        $this->insertHistory($task, [
            'attempt_key' => $this->bindAttemptKey($task),
            ...$attributes,
        ]);
    }

    private function resolveHistoryForCompletion(Event $task): ?ScheduleRunHistory
    {
        $attemptKey = $this->boundAttemptKey($task);

        if ($attemptKey !== null) {
            $byKey = ScheduleRunHistory::query()->where('attempt_key', $attemptKey)->first();
            if ($byKey !== null) {
                return $byKey;
            }
        }

        // Background schedule:finish reconstructs Event without the in-memory
        // attempt key. Prefer the last-run mirror's attempt when still running.
        $lastRun = $this->forCommand($this->normalizeCommand($task->command));
        if (
            is_string($lastRun?->attempt_key)
            && $lastRun->attempt_key !== ''
            && $lastRun->status === ScheduleRunStatuses::RUNNING
        ) {
            $byLast = ScheduleRunHistory::query()
                ->where('attempt_key', $lastRun->attempt_key)
                ->first();
            if ($byLast !== null) {
                return $byLast;
            }
        }

        return $this->findRunningHistory($task);
    }

    private function findRunningHistory(Event $task): ?ScheduleRunHistory
    {
        return ScheduleRunHistory::query()
            ->where('command_key', $this->normalizeCommand($task->command))
            ->where('status', ScheduleRunStatuses::RUNNING)
            ->orderByDesc('id')
            ->first();
    }

    private function adoptAttemptFromLastRun(Event $task): void
    {
        if ($this->boundAttemptKey($task) !== null) {
            return;
        }

        $lastRun = $this->forCommand($this->normalizeCommand($task->command));
        if (
            is_string($lastRun?->attempt_key)
            && $lastRun->attempt_key !== ''
            && $lastRun->status === ScheduleRunStatuses::RUNNING
        ) {
            $task->{self::ATTEMPT_KEY_PROPERTY} = $lastRun->attempt_key;
        }
    }

    private function bindAttemptKey(Event $task): string
    {
        $existing = $this->boundAttemptKey($task);
        if ($existing !== null) {
            return $existing;
        }

        $attemptKey = (string) Str::uuid();
        $task->{self::ATTEMPT_KEY_PROPERTY} = $attemptKey;

        return $attemptKey;
    }

    private function boundAttemptKey(Event $task): ?string
    {
        $value = $task->{self::ATTEMPT_KEY_PROPERTY} ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function restoreDeterministicOutputPath(Event $task): void
    {
        $default = $task->getDefaultOutput();
        $current = $task->output ?? null;

        if (! is_string($current) || $current === '' || $current === $default) {
            $task->sendOutputTo($this->deterministicOutputPath($task));
        }
    }

    private function exitCode(Event $task): ?int
    {
        $exitCode = $task->exitCode ?? null;

        return is_int($exitCode) ? $exitCode : (is_numeric($exitCode) ? (int) $exitCode : null);
    }

    /**
     * Background finishes sometimes omit exitCode; treat unknown as succeeded
     * unless Laravel reported a concrete non-zero code.
     */
    private function statusFromExitCode(?int $exitCode): string
    {
        return ($exitCode === null || $exitCode === 0)
            ? ScheduleRunStatuses::SUCCEEDED
            : ScheduleRunStatuses::FAILED;
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
            $contents = fread($handle, self::OUTPUT_LIMIT + 1);
        } finally {
            fclose($handle);
        }

        if ($contents === false || trim($contents) === '') {
            return null;
        }

        return $this->truncate($contents, self::OUTPUT_LIMIT);
    }

    private function mergeOutput(?string $commandOutput, string $exceptionMessage): ?string
    {
        $parts = array_values(array_filter([
            $commandOutput !== null && trim($commandOutput) !== '' ? trim($commandOutput) : null,
            trim($exceptionMessage) !== '' ? trim($exceptionMessage) : null,
        ]));

        if ($parts === []) {
            return null;
        }

        // Deduplicate when Failed re-merges output already containing the message.
        $merged = [];
        foreach ($parts as $part) {
            if (! in_array($part, $merged, true)) {
                $merged[] = $part;
            }
        }

        return $this->truncate(implode("\n", $merged), self::OUTPUT_LIMIT);
    }

    private function startedAt(Event $task, ?int $runtimeMs = null): mixed
    {
        $attemptKey = $this->boundAttemptKey($task);
        if ($attemptKey !== null) {
            $history = ScheduleRunHistory::query()->where('attempt_key', $attemptKey)->first();
            if ($history?->started_at !== null) {
                return $history->started_at;
            }
        }

        $existing = $this->forCommand($this->normalizeCommand($task->command));
        if ($existing?->started_at !== null && $existing->status === ScheduleRunStatuses::RUNNING) {
            return $existing->started_at;
        }

        return $runtimeMs !== null ? now()->subMilliseconds($runtimeMs) : now();
    }

    private function truncate(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }

    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning('Scheduled task run recorder failed.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
