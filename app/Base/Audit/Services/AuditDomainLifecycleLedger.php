<?php

namespace App\Base\Audit\Services;

use App\Base\Audit\Models\AuditAction;
use App\Base\Foundation\Contracts\DomainLifecycleLedger;

final class AuditDomainLifecycleLedger implements DomainLifecycleLedger
{
    /**
     * @param  list<string>  $domains
     * @return array<string, array{occurred_at: string, actor_type: string, actor_id: int, actor_name: string|null, actor_email: string|null, status: string|null}>
     */
    public function latestInstallations(array $domains): array
    {
        $wanted = array_flip(array_values(array_filter($domains, fn (string $domain): bool => $domain !== '')));

        if ($wanted === []) {
            return [];
        }

        $installations = [];

        AuditAction::query()
            ->where('event', 'domain.install')
            ->where('is_retained', true)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->each(function (AuditAction $action) use (&$installations, $wanted): bool {
                $payload = is_array($action->payload) ? $action->payload : [];
                $domain = $payload['domain'] ?? null;

                if (! is_string($domain) || ! isset($wanted[$domain]) || isset($installations[$domain])) {
                    return true;
                }

                if (($payload['status'] ?? null) === 'clone_failed') {
                    return true;
                }

                $installations[$domain] = [
                    'occurred_at' => $action->occurred_at->toIso8601String(),
                    'actor_type' => $action->actor_type,
                    'actor_id' => (int) $action->actor_id,
                    'actor_name' => $this->stringOrNull($payload['actor_name'] ?? null),
                    'actor_email' => $this->stringOrNull($payload['actor_email'] ?? null),
                    'status' => $this->stringOrNull($payload['status'] ?? null),
                ];

                return count($installations) < count($wanted);
            });

        return $installations;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
