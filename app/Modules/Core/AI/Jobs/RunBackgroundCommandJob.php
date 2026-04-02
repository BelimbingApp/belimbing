<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Jobs;

use App\Modules\Core\AI\Models\OperationDispatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Process\ProcessResult;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Queue job that executes a background artisan command.
 *
 * Loads the dispatch record, runs the artisan command via Process,
 * captures stdout/stderr/exit code, and records the result on the
 * OperationDispatch record. Status lifecycle: queued → running →
 * succeeded/failed.
 */
class RunBackgroundCommandJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Dedicated queue for background command execution.
     */
    public const QUEUE = 'ai-background-commands';

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300;

    /**
     * Maximum output length stored in result_summary (characters).
     */
    private const MAX_OUTPUT_LENGTH = 10000;

    /**
     * @param  string  $dispatchId  The ai_operation_dispatches primary key
     */
    public function __construct(
        public string $dispatchId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Human-readable name shown in payloads/logs.
     */
    public function displayName(): string
    {
        return 'RunBackgroundCommand['.$this->dispatchId.']';
    }

    /**
     * Execute the background artisan command.
     */
    public function handle(): void
    {
        $dispatch = OperationDispatch::query()->find($this->dispatchId);

        if ($dispatch === null || $dispatch->isTerminal()) {
            return;
        }

        $command = data_get($dispatch->meta, 'command');

        if (! is_string($command) || $command === '') {
            $dispatch->markFailed('No command found in dispatch metadata.');

            return;
        }

        $dispatch->markRunning();

        try {
            $fullCommand = 'php artisan '.$command;

            $result = Process::timeout($this->timeout - 10)
                ->path(base_path())
                ->run($fullCommand);

            $this->recordResult($dispatch, $result, $command);
        } catch (\Throwable $e) {
            Log::error('Background command failed with exception.', [
                'dispatch_id' => $this->dispatchId,
                'command' => $command,
                'error' => $e->getMessage(),
            ]);

            $dispatch->markFailed('Exception: '.$e->getMessage());

            throw $e;
        }
    }

    /**
     * Record the process result on the dispatch.
     *
     * @param  ProcessResult  $result  Process execution result
     * @param  string  $command  The executed command
     */
    private function recordResult(OperationDispatch $dispatch, $result, string $command): void
    {
        $exitCode = $result->exitCode();
        $stdout = $this->truncateOutput($result->output());
        $stderr = $this->truncateOutput($result->errorOutput());

        $summary = "Command: php artisan {$command}\nExit code: {$exitCode}";

        if ($stdout !== '') {
            $summary .= "\n\nOutput:\n{$stdout}";
        }

        if ($stderr !== '') {
            $summary .= "\n\nErrors:\n{$stderr}";
        }

        $runtimeMeta = [
            'exit_code' => $exitCode,
            'has_stderr' => $stderr !== '',
        ];

        if ($exitCode === 0) {
            $dispatch->markSucceeded(
                runId: 'cmd_'.$this->dispatchId,
                resultSummary: $summary,
                runtimeMeta: $runtimeMeta,
            );
        } else {
            $dispatch->markFailed($summary);
        }
    }

    /**
     * Truncate output to a safe storage length.
     */
    private function truncateOutput(string $output): string
    {
        $trimmed = trim($output);

        if (mb_strlen($trimmed) <= self::MAX_OUTPUT_LENGTH) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, self::MAX_OUTPUT_LENGTH)
            ."\n\n[truncated — ".(mb_strlen($trimmed) - self::MAX_OUTPUT_LENGTH).' characters omitted]';
    }
}
