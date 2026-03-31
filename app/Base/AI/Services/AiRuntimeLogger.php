<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services;

use App\Base\AI\DTO\AiRuntimeError;
use Psr\Log\LoggerInterface;

/**
 * Logs AI runtime events to the dedicated `ai` channel.
 *
 * Stateless service — all context is passed explicitly per call.
 * Never logs API keys, auth tokens, full prompts, full response
 * bodies, or user content.
 */
class AiRuntimeLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Log a failed run with structured error details.
     *
     * @param  string  $runId  Unique run identifier
     * @param  AiRuntimeError  $error  Structured error data
     * @param  array<string, mixed>  $context  Additional context (employee_id, session_id, provider_name, model, streaming, iteration)
     */
    public function runFailed(string $runId, AiRuntimeError $error, array $context = []): void
    {
        $this->logger->warning('AI run failed', array_merge(
            ['run_id' => $runId],
            $error->toLogContext(),
            $context,
        ));
    }

    /**
     * Log an unhandled exception during a run.
     *
     * Includes exception class, message, and file:line — but NOT
     * the full stack trace (too verbose for structured logs).
     *
     * @param  string  $runId  Unique run identifier
     * @param  \Throwable  $exception  The unhandled exception
     * @param  array<string, mixed>  $context  Additional context
     */
    public function unhandledException(string $runId, \Throwable $exception, array $context = []): void
    {
        $this->logger->error('AI run unhandled exception', array_merge(
            [
                'run_id' => $runId,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'exception_location' => $exception->getFile().':'.$exception->getLine(),
            ],
            $context,
        ));
    }

    /**
     * Log a provider response that returned 200 but contained invalid payload.
     *
     * @param  string  $runId  Unique run identifier
     * @param  string  $model  Model identifier
     * @param  string  $providerName  Provider name
     * @param  string  $detail  Sanitized diagnostic excerpt (no secrets or user content)
     * @param  int  $latencyMs  Request latency in milliseconds
     */
    public function providerPayloadInvalid(string $runId, string $model, string $providerName, string $detail, int $latencyMs): void
    {
        $this->logger->warning('AI provider returned invalid payload', [
            'run_id' => $runId,
            'model' => $model,
            'provider_name' => $providerName,
            'detail' => $detail,
            'latency_ms' => $latencyMs,
        ]);
    }

    /**
     * Log a streaming-specific failure.
     *
     * @param  string  $runId  Unique run identifier
     * @param  string  $reason  Failure reason (e.g. 'stream ended without done', 'connection dropped')
     * @param  array<string, mixed>  $context  Additional context
     */
    public function streamFailed(string $runId, string $reason, array $context = []): void
    {
        $this->logger->warning('AI stream failed', array_merge(
            [
                'run_id' => $runId,
                'reason' => $reason,
            ],
            $context,
        ));
    }
}
