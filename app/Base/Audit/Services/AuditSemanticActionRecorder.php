<?php

namespace App\Base\Audit\Services;

use App\Base\Audit\DTO\RequestContext;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Foundation\Contracts\SemanticActionRecorder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

class AuditSemanticActionRecorder implements SemanticActionRecorder
{
    public function __construct(
        private readonly AuditBuffer $buffer,
        private readonly RequestContext $context,
    ) {}

    public function record(
        string $event,
        string $summary,
        ?string $source = null,
        array $subject = [],
        ?string $surface = null,
        ?string $uiElement = null,
        array $context = [],
        string $result = 'succeeded',
        bool $retain = true,
    ): void {
        $event = strtolower(trim($event));
        $summary = trim($summary);

        if ($event === '' || $summary === '') {
            return;
        }

        $actor = $this->resolveActor();
        $normalizedSubject = $this->normalizeSubject($subject);

        $this->buffer->bufferAction([
            'company_id' => $actor['company_id'],
            'actor_type' => $actor['type'],
            'actor_id' => $actor['id'],
            'actor_role' => $this->context->actorRole,
            'ip_address' => $this->context->ipAddress,
            'url' => $this->context->url,
            'user_agent' => $this->context->userAgent,
            'event' => $event,
            'payload' => json_encode([
                'semantic' => true,
                'source' => $source,
                'summary' => $summary,
                'surface' => $surface,
                'ui_element' => $uiElement,
                'subject' => $normalizedSubject,
                'context' => $context,
                'result' => $result,
            ], JSON_UNESCAPED_SLASHES),
            'trace_id' => $this->context->traceId,
            'is_retained' => $retain,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @return array{type: string, id: int, company_id: int|null}
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
            ];
        }

        return [
            'type' => $this->context->actorType ?? PrincipalType::GUEST->value,
            'id' => $this->context->actorId ?? 0,
            'company_id' => $this->context->companyId,
        ];
    }

    /**
     * @param  array{name?: string, id?: int|string, identifier?: string|null}  $subject
     * @return array{name: string, id: int|string, identifier: string|null, label: string}|null
     */
    private function normalizeSubject(array $subject): ?array
    {
        $name = $this->stringOrNull($subject['name'] ?? null);
        $id = $subject['id'] ?? null;

        if ($name === null || $id === null || $id === '') {
            return null;
        }

        $identifier = $this->stringOrNull($subject['identifier'] ?? null);
        $label = Str::headline($name).'#'.$id;

        if ($identifier !== null) {
            $label .= ' · '.$identifier;
        }

        return [
            'name' => $name,
            'id' => is_int($id) ? $id : (string) $id,
            'identifier' => $identifier,
            'label' => $label,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
