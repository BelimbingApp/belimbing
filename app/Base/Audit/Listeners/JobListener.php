<?php

namespace App\Base\Audit\Listeners;

use App\Base\Audit\DTO\RequestContext;
use App\Base\Audit\Services\AuditBuffer;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;

/**
 * Captures queue job lifecycle events (processed, failed)
 * and buffers them as audit action entries.
 */
class JobListener
{
    public function __construct(
        private readonly AuditBuffer $buffer,
        private readonly RequestContext $context,
    ) {}

    /**
     * Handle a successfully processed job event.
     */
    public function handleProcessed(JobProcessed $event): void
    {
        $this->bufferJobAction('queue.job.processed', $event->job);
    }

    /**
     * Handle a failed job event.
     */
    public function handleFailed(JobFailed $event): void
    {
        $this->bufferJobAction('queue.job.failed', $event->job);
    }

    /**
     * Buffer a job action entry.
     */
    private function bufferJobAction(string $eventName, mixed $job): void
    {
        $now = now();

        $this->buffer->bufferAction([
            'company_id' => $this->context->companyId,
            'actor_type' => $this->context->actorType,
            'actor_id' => $this->context->actorId,
            'actor_role' => $this->context->actorRole,
            'ip_address' => $this->context->ipAddress,
            'url' => $this->context->url,
            'user_agent' => $this->context->userAgent,
            'event' => $eventName,
            'payload' => json_encode([
                'job' => $job->resolveName(),
                'queue' => $job->getQueue(),
                'connection' => $job->getConnectionName(),
            ]),
            'trace_id' => $this->context->traceId,
            'occurred_at' => $now,
        ]);
    }
}
