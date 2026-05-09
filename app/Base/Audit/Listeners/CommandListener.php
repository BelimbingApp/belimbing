<?php
namespace App\Base\Audit\Listeners;

use App\Base\Audit\DTO\RequestContext;
use App\Base\Audit\Services\AuditBuffer;
use Illuminate\Console\Events\CommandFinished;

/**
 * Captures completed artisan commands and buffers them
 * as audit action entries. Noisy commands are excluded via config.
 */
class CommandListener
{
    public function __construct(
        private readonly AuditBuffer $buffer,
        private readonly RequestContext $context,
    ) {}

    /**
     * Handle a CommandFinished event.
     */
    public function handle(CommandFinished $event): void
    {
        $command = $event->command ?? 'unknown';

        $excludedCommands = config('audit.exclude_commands', []);
        if (in_array($command, $excludedCommands, true)) {
            return;
        }

        $now = now();

        $this->buffer->bufferAction([
            'company_id' => $this->context->companyId,
            'actor_type' => $this->context->actorType,
            'actor_id' => $this->context->actorId,
            'actor_role' => $this->context->actorRole,
            'ip_address' => $this->context->ipAddress,
            'url' => $this->context->url,
            'user_agent' => $this->context->userAgent,
            'event' => 'console.command',
            'payload' => json_encode([
                'command' => $command,
                'arguments' => $event->input->getArguments(),
                'exit_code' => $event->exitCode,
            ]),
            'correlation_id' => $this->context->correlationId,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);
    }
}
