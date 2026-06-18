<?php

namespace App\Base\Audit\Listeners;

use App\Base\Audit\DTO\RequestContext;
use App\Base\Audit\Models\AuditAction;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Foundation\Events\DomainLifecycleAction;
use Illuminate\Contracts\Auth\Authenticatable;

class DomainLifecycleActionListener
{
    public function __construct(
        private readonly RequestContext $context,
    ) {}

    public function handle(DomainLifecycleAction $event): void
    {
        $actor = $this->resolveActor();

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

    /**
     * @return array{type: string, id: int, company_id: int|null, name: string|null, email: string|null}
     */
    private function resolveActor(): array
    {
        $user = auth()->user();

        if ($user instanceof Authenticatable) {
            $companyId = $this->context->companyId;
            if ($companyId === null && method_exists($user, 'getCompanyId')) {
                $companyId = $user->getCompanyId();
            }

            return [
                'type' => method_exists($user, 'principalType')
                    ? $user->principalType()->value
                    : PrincipalType::USER->value,
                'id' => (int) $user->getAuthIdentifier(),
                'company_id' => $companyId,
                'name' => $this->stringOrNull(data_get($user, 'name')),
                'email' => $this->stringOrNull(data_get($user, 'email')),
            ];
        }

        $actorType = $this->context->actorType;
        $actorId = $this->context->actorId;
        $companyId = $this->context->companyId;

        if ($actorType === null) {
            $actorType = PrincipalType::GUEST->value;
        }

        if ($actorId === null) {
            $actorId = 0;
        }

        return [
            'type' => $actorType,
            'id' => $actorId,
            'company_id' => $companyId,
            'name' => null,
            'email' => null,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
