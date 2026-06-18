<?php

namespace App\Base\Audit\Services;

use App\Base\Audit\Models\AuditMutation;
use App\Base\Authz\Enums\PrincipalType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final class AuditSourceHistory
{
    public function __construct(
        private readonly AuditLogPresenter $presenter,
    ) {}

    /**
     * @param  list<array{name: string, id: int|string, identifier?: string|null}>  $subjects
     * @return array{entries: list<array<string, mixed>>, has_more: bool, limit: int}
     */
    public function forRecord(
        array $subjects,
        ?string $auditableType,
        int|string|null $auditableId,
        int $limit = 25,
    ): array {
        if ($subjects === [] && ($auditableType === null || $auditableId === null || $auditableId === '')) {
            return $this->emptyHistory($limit);
        }

        $rows = AuditMutation::query()
            ->leftJoin('users', function ($join): void {
                $join->on('base_audit_mutations.actor_id', '=', 'users.id')
                    ->where('base_audit_mutations.actor_type', '=', PrincipalType::USER->value);
            })
            ->select('base_audit_mutations.*', 'users.name as actor_name')
            ->where(function (Builder $query) use ($subjects, $auditableType, $auditableId): void {
                if ($auditableType !== null && $auditableId !== null && $auditableId !== '') {
                    $query->orWhere(function (Builder $direct) use ($auditableType, $auditableId): void {
                        $direct->where('base_audit_mutations.auditable_type', $auditableType)
                            ->where('base_audit_mutations.auditable_id', (int) $auditableId);
                    });
                }

                foreach ($subjects as $subject) {
                    $name = $this->stringOrNull($subject['name'] ?? null);
                    $id = $subject['id'] ?? null;

                    if ($name === null || $id === null || $id === '') {
                        continue;
                    }

                    $query->orWhere(function (Builder $subjectQuery) use ($name, $id, $subject): void {
                        $subjectQuery->where('base_audit_mutations.subject_name', $name)
                            ->where('base_audit_mutations.subject_id', (int) $id);

                        $identifier = $this->stringOrNull($subject['identifier'] ?? null);
                        if ($identifier !== null) {
                            $subjectQuery->where('base_audit_mutations.subject_identifier', $identifier);
                        }
                    });
                }
            })
            ->orderByDesc('base_audit_mutations.occurred_at')
            ->orderByDesc('base_audit_mutations.id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;

        return [
            'entries' => $rows->take($limit)->map(fn (AuditMutation $mutation): array => $this->entry($mutation))->values()->all(),
            'has_more' => $hasMore,
            'limit' => $limit,
        ];
    }

    /** @return array<string, mixed> */
    private function entry(AuditMutation $mutation): array
    {
        return [
            'id' => $mutation->id,
            'occurred_at' => $this->serializeTime($mutation->occurred_at),
            'actor' => $this->presenter->actorLabel($mutation),
            'actor_role' => $mutation->actor_role,
            'event' => $mutation->event,
            'event_label' => $this->presenter->mutationEventLabel($mutation->event),
            'event_variant' => $this->presenter->mutationEventVariant($mutation->event),
            'summary' => $this->presenter->mutationLabel($mutation),
            'auditable' => class_basename((string) $mutation->auditable_type).'#'.$mutation->auditable_id,
            'source' => $mutation->source,
            'diffs' => $this->presenter->mutationDiffs($mutation),
            'trace_id' => $mutation->trace_id,
            'formatted_trace_id' => $this->presenter->formatTrace($mutation->trace_id),
        ];
    }

    private function serializeTime(mixed $time): ?string
    {
        if ($time instanceof CarbonInterface) {
            return $time->toIso8601String();
        }

        return $time !== null ? (string) $time : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array{entries: list<array<string, mixed>>, has_more: bool, limit: int} */
    private function emptyHistory(int $limit): array
    {
        return [
            'entries' => [],
            'has_more' => false,
            'limit' => $limit,
        ];
    }
}
