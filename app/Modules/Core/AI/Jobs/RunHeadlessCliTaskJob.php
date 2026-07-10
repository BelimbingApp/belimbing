<?php

namespace App\Modules\Core\AI\Jobs;

use App\Base\AI\DTO\AiRuntimeError;
use App\Modules\Core\AI\DTO\HeadlessCliRunResult;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Models\ScheduleDefinition;
use App\Modules\Core\AI\Services\ControlPlane\RunRecorder;
use App\Modules\Core\AI\Services\ControlPlane\RunRecorderStartInput;
use App\Modules\Core\AI\Services\HeadlessCli\HeadlessCliExecutor;
use App\Modules\Core\AI\Values\CallUsage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RunHeadlessCliTaskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const QUEUE = 'ai-headless-tasks';

    public int $timeout = 3700;

    public function __construct(public string $dispatchId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function displayName(): string
    {
        return 'RunHeadlessCliTask['.$this->dispatchId.']';
    }

    public function handle(HeadlessCliExecutor $executor, RunRecorder $runRecorder): void
    {
        $dispatch = OperationDispatch::query()->find($this->dispatchId);

        if ($dispatch === null || $dispatch->isTerminal()) {
            return;
        }

        $scheduleId = data_get($dispatch->meta, 'schedule_id');

        if (! is_int($scheduleId) && ! (is_string($scheduleId) && ctype_digit($scheduleId))) {
            $dispatch->markFailed('Headless CLI dispatch is missing schedule_id metadata.');

            return;
        }

        $schedule = ScheduleDefinition::query()->find((int) $scheduleId);

        if ($schedule === null) {
            $dispatch->markFailed('Headless CLI schedule #'.$scheduleId.' no longer exists.');

            return;
        }

        $dispatch->markRunning();

        if ($dispatch->employee_id === null) {
            $dispatch->markFailed('Headless CLI dispatch is missing the Lara system Agent identity.');

            return;
        }

        $runId = (string) Str::ulid();

        $runRecorder->beginExecution(new RunRecorderStartInput(
            runId: $runId,
            employeeId: $dispatch->employee_id,
            source: 'headless_cli',
            executionMode: 'background',
            actingForUserId: $dispatch->acting_for_user_id,
            timeoutSeconds: $this->timeout,
        ));
        $runRecorder->attachDispatch($runId, $dispatch->id);
        $startedAt = now();

        try {
            $result = $executor->run($schedule);
            $latencyMs = max(0, (int) $startedAt->diffInMilliseconds(now()));
            $this->recordCall($runRecorder, $runId, $result, $latencyMs);

            $runtimeMeta = [
                'executor' => ScheduleDefinition::EXECUTOR_HEADLESS_CLI,
                'headless_provider' => $result->provider,
                'headless_model' => $result->model,
                'headless_identity_source' => $result->identitySource,
                'headless_exit_code' => $result->exitCode,
                'headless_cost_usd' => $result->costUsd,
                'headless_usage' => $result->usage,
            ];

            if ($result->succeeded()) {
                $runRecorder->complete($runId, [
                    'provider_name' => $result->provider,
                    'model' => $result->model,
                    'latency_ms' => $latencyMs,
                    'headless_cost_usd' => $result->costUsd,
                    'identity_source' => $result->identitySource,
                ]);

                $dispatch->markSucceeded(
                    runId: $runId,
                    resultSummary: $result->outputSummary(),
                    runtimeMeta: $runtimeMeta,
                );

                return;
            }

            $dispatch->update(['meta' => array_merge($dispatch->meta ?? [], $runtimeMeta)]);
            $failureSummary = $this->failureSummary($result->exitCode, $result->stdout, $result->stderr);
            $runRecorder->fail(
                $runId,
                AiRuntimeError::unexpected($failureSummary, $latencyMs),
                [
                    'provider_name' => $result->provider,
                    'model' => $result->model,
                    'latency_ms' => $latencyMs,
                ],
            );
            $dispatch->markFailed($failureSummary);
        } catch (\Throwable $e) {
            Log::error('Headless CLI task failed with exception.', [
                'dispatch_id' => $this->dispatchId,
                'schedule_id' => $schedule->id,
                'error' => $e->getMessage(),
            ]);

            $runRecorder->fail($runId, AiRuntimeError::unexpected($e->getMessage()));
            $dispatch->markFailed('Exception: '.$e->getMessage());

            throw $e;
        }
    }

    private function recordCall(
        RunRecorder $runRecorder,
        string $runId,
        HeadlessCliRunResult $result,
        int $latencyMs,
    ): void {
        $runRecorder->recordCall(
            runId: $runId,
            attemptIndex: 1,
            provider: $result->provider,
            model: $result->model,
            finishReason: $result->succeeded() ? 'stop' : 'error',
            latencyMs: $latencyMs,
            usage: CallUsage::fromProviderArray($result->usage),
        );
    }

    private function failureSummary(int $exitCode, string $stdout, string $stderr): string
    {
        $summary = 'Headless CLI exited with code '.$exitCode.'.';
        $error = trim($stderr) !== '' ? trim($stderr) : trim($stdout);

        if ($error !== '') {
            $summary .= "\n\n".$this->truncate($error);
        }

        return $summary;
    }

    private function truncate(string $output): string
    {
        return mb_strlen($output) > 10000
            ? mb_substr($output, 0, 10000)."\n\n[truncated]"
            : $output;
    }
}
