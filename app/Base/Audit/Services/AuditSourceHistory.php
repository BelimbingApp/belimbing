<?php

namespace App\Base\Audit\Services;

use App\Base\Audit\Models\AuditMutation;
use App\Base\Authz\Enums\PrincipalType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final class AuditSourceHistory
{
    private const LIKE_PLACEHOLDER = ' like ?';

    private const SORTABLE = [
        'occurred_at' => 'base_audit_mutations.occurred_at',
        'actor' => 'users.name',
        'event' => 'base_audit_mutations.event',
        'trace_id' => 'base_audit_mutations.trace_id',
    ];

    private const SEARCH_TEXT_COLUMNS = [
        'users.name',
        'base_audit_mutations.actor_role',
        'base_audit_mutations.actor_type',
        'base_audit_mutations.auditable_type',
        'base_audit_mutations.event',
        'base_audit_mutations.subject_name',
        'base_audit_mutations.subject_identifier',
    ];

    private const SEARCH_CAST_COLUMNS = [
        'base_audit_mutations.auditable_id',
        'base_audit_mutations.subject_id',
        'base_audit_mutations.old_values',
        'base_audit_mutations.new_values',
    ];

    public function __construct(
        private readonly AuditLogPresenter $presenter,
    ) {}

    /**
     * @param  list<array{name: string, id: int|string, identifier?: string|null}>  $subjects
     * @return array{entries: list<array<string, mixed>>, has_more: bool, limit: int, total: int}
     */
    public function forRecord(
        array $subjects,
        ?string $auditableType,
        int|string|null $auditableId,
        int $limit = 25,
        string $search = '',
        string $sortBy = 'occurred_at',
        string $sortDir = 'desc',
    ): array {
        $normalizedAuditableId = $this->idOrNull($auditableId);

        if ($subjects === [] && ($auditableType === null || $normalizedAuditableId === null)) {
            return $this->emptyHistory($limit);
        }

        $sortBy = array_key_exists($sortBy, self::SORTABLE) ? $sortBy : 'occurred_at';
        $sortDir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        $query = AuditMutation::query()
            ->leftJoin('users', function ($join): void {
                $join->on('base_audit_mutations.actor_id', '=', 'users.id')
                    ->where('base_audit_mutations.actor_type', '=', PrincipalType::USER->value);
            })
            ->select('base_audit_mutations.*', 'users.name as actor_name')
            ->where(fn (Builder $query): Builder => $this->applyRecordScope($query, $subjects, $auditableType, $normalizedAuditableId))
            ->tap(fn (Builder $query): Builder => $this->applySearch($query, $search));

        $total = (clone $query)->count('base_audit_mutations.id');

        $rows = $query
            ->orderBy(self::SORTABLE[$sortBy], $sortDir)
            ->when($sortBy === 'occurred_at', function (Builder $query) use ($sortDir): void {
                $query->orderBy('base_audit_mutations.id', $sortDir);
            }, function (Builder $query): void {
                $query->orderByDesc('base_audit_mutations.occurred_at')
                    ->orderByDesc('base_audit_mutations.id');
            })
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;

        return [
            'entries' => $rows->take($limit)->map(fn (AuditMutation $mutation): array => $this->entry($mutation, $subjects, $auditableType, $normalizedAuditableId))->values()->all(),
            'has_more' => $hasMore,
            'limit' => $limit,
            'total' => $total,
        ];
    }

    /**
     * @param  list<array{name: string, id: int|string, identifier?: string|null}>  $subjects
     */
    private function applyRecordScope(Builder $query, array $subjects, ?string $auditableType, ?string $auditableId): Builder
    {
        if ($auditableType !== null && $auditableId !== null) {
            $query->orWhere(function (Builder $direct) use ($auditableType, $auditableId): void {
                $direct->where('base_audit_mutations.auditable_type', $auditableType)
                    ->where('base_audit_mutations.auditable_id', $auditableId)
                    ->where('base_audit_mutations.source', '!=', 'expanded');
            });
        }

        $this->applySubjectScopes($query, $subjects);

        return $query;
    }

    /**
     * @param  list<array{name: string, id: int|string, identifier?: string|null}>  $subjects
     */
    private function applySubjectScopes(Builder $query, array $subjects): void
    {
        foreach ($subjects as $subject) {
            $name = $this->stringOrNull($subject['name'] ?? null);
            $id = $this->idOrNull($subject['id'] ?? null);

            if ($name === null || $id === null) {
                continue;
            }

            $query->orWhere(function (Builder $subjectQuery) use ($name, $id, $subject): void {
                $subjectQuery->where('base_audit_mutations.subject_name', $name)
                    ->where('base_audit_mutations.subject_id', $id);

                $identifier = $this->stringOrNull($subject['identifier'] ?? null);
                if ($identifier !== null) {
                    $subjectQuery->where('base_audit_mutations.subject_identifier', $identifier);
                }
            });
        }
    }

    /** @return array<string, mixed> */
    private function entry(AuditMutation $mutation, array $subjects, ?string $auditableType, ?string $auditableId): array
    {
        $auditable = class_basename((string) $mutation->auditable_type).'#'.$mutation->auditable_id;
        $summary = $this->presenter->mutationLabel($mutation);

        return [
            'id' => $mutation->id,
            'occurred_at' => $this->serializeTime($mutation->occurred_at),
            'actor' => $this->presenter->actorLabel($mutation),
            'actor_role' => $mutation->actor_role,
            'event' => $mutation->event,
            'event_label' => $this->presenter->mutationEventLabel($mutation->event),
            'event_variant' => $this->presenter->mutationEventVariant($mutation->event),
            'summary' => $summary,
            'auditable' => $auditable,
            'target' => $this->targetLabel($mutation, $subjects, $auditableType, $auditableId, $summary, $auditable),
            'source' => $mutation->source,
            'diffs' => $this->presenter->mutationDiffs($mutation),
            'trace_id' => $mutation->trace_id,
            'formatted_trace_id' => $this->presenter->formatTrace($mutation->trace_id),
        ];
    }

    private function applySearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        $like = '%'.strtolower($search).'%';
        $trace = $this->presenter->normalizeTrace($search);
        $subjectHandle = $this->parseSubjectHandle($search);

        return $query->where(function (Builder $searchQuery) use ($like, $trace, $subjectHandle): void {
            $this->applyTextSearch($searchQuery, $like);

            if ($trace !== '') {
                $searchQuery->orWhereRaw('base_audit_mutations.trace_id'.self::LIKE_PLACEHOLDER, ['%'.$trace.'%']);
            }

            if ($subjectHandle !== null) {
                $searchQuery->orWhere(function (Builder $handle) use ($subjectHandle): void {
                    $handle->whereRaw('lower(base_audit_mutations.auditable_type)'.self::LIKE_PLACEHOLDER, ['%'.$subjectHandle['name'].'%'])
                        ->where('base_audit_mutations.auditable_id', $subjectHandle['id']);
                })->orWhere(function (Builder $handle) use ($subjectHandle): void {
                    $handle->whereRaw($this->lowerCoalescedExpression('base_audit_mutations.subject_name').' = ?', [$subjectHandle['name']])
                        ->where('base_audit_mutations.subject_id', $subjectHandle['id']);
                });
            }
        });
    }

    private function applyTextSearch(Builder $query, string $like): void
    {
        $first = true;

        foreach ($this->searchExpressions() as $expression) {
            $method = $first ? 'whereRaw' : 'orWhereRaw';
            $query->{$method}($expression.self::LIKE_PLACEHOLDER, [$like]);
            $first = false;
        }
    }

    /**
     * @return list<string>
     */
    private function searchExpressions(): array
    {
        return [
            ...array_map($this->lowerCoalescedExpression(...), self::SEARCH_TEXT_COLUMNS),
            ...array_map($this->lowerTextExpression(...), self::SEARCH_CAST_COLUMNS),
        ];
    }

    /**
     * @param  list<array{name: string, id: int|string, identifier?: string|null}>  $subjects
     */
    private function targetLabel(AuditMutation $mutation, array $subjects, ?string $auditableType, ?string $auditableId, string $summary, string $auditable): ?string
    {
        if ($auditableType !== null
            && $auditableId !== null
            && $mutation->auditable_type === $auditableType
            && (string) $mutation->auditable_id === $auditableId) {
            return null;
        }

        if ($this->matchesCurrentSubject($mutation, $subjects)) {
            return $auditable;
        }

        return $summary !== $auditable ? $summary.' · '.$auditable : $auditable;
    }

    /**
     * @param  list<array{name: string, id: int|string, identifier?: string|null}>  $subjects
     */
    private function matchesCurrentSubject(AuditMutation $mutation, array $subjects): bool
    {
        foreach ($subjects as $subject) {
            $name = $this->stringOrNull($subject['name'] ?? null);
            $id = $this->idOrNull($subject['id'] ?? null);

            if ($name === null || $id === null) {
                continue;
            }

            if ($mutation->subject_name === $name && (string) $mutation->subject_id === $id) {
                return true;
            }
        }

        return false;
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

    private function idOrNull(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' ? $value : null;
        }

        return null;
    }

    /** @return array{name: string, id: string}|null */
    private function parseSubjectHandle(string $search): ?array
    {
        if (! str_contains($search, '#')) {
            return null;
        }

        [$name, $id] = array_pad(explode('#', $search, 2), 2, '');
        $name = strtolower(trim($name));
        $id = trim($id);

        if ($name === '' || $id === '') {
            return null;
        }

        return ['name' => $name, 'id' => $id];
    }

    private function lowerTextExpression(string $column): string
    {
        return match (config('database.default')) {
            'mysql', 'mariadb' => 'lower(cast('.$column.' as char))',
            default => 'lower(cast('.$column.' as text))',
        };
    }

    private function lowerCoalescedExpression(string $column): string
    {
        return 'lower(coalesce('.$column.', \'\'))';
    }

    /** @return array{entries: list<array<string, mixed>>, has_more: bool, limit: int, total: int} */
    private function emptyHistory(int $limit): array
    {
        return [
            'entries' => [],
            'has_more' => false,
            'limit' => $limit,
            'total' => 0,
        ];
    }
}
