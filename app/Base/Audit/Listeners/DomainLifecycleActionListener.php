<?php

namespace App\Base\Audit\Listeners;

use App\Base\Audit\DTO\RequestContext;
use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Services\AuditActorResolver;
use App\Base\Foundation\Events\DomainLifecycleAction;

class DomainLifecycleActionListener
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly AuditActorResolver $actors,
    ) {}

    public function handle(DomainLifecycleAction $event): void
    {
        $actor = $this->actors->currentActor();

        // Domain lifecycle actions can immediately reload long-lived workers;
        // persist this retained row before the reload request is issued.
        AuditAction::query()->insert([
            'company_id' => $actor['company_id'],
            'actor_type' => $actor['type'],
            'actor_id' => $actor['id'],
            'actor_role' => $this->context->actorRole,
            'ip_address' => $this->context->ipAddress,
            'url' => $this->context->url,
            'user_agent' => $this->context->userAgent,
            'event' => 'domain.'.$event->action,
            'payload' => json_encode([
                'domain' => $event->domain,
                'status' => $event->status,
                'actor_name' => $actor['name'],
                'actor_email' => $actor['email'],
                ...$event->details,
            ]),
            'trace_id' => $this->context->traceId,
            'is_retained' => true,
            'occurred_at' => now(),
        ]);
    }
}
