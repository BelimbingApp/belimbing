<?php

namespace App\Base\Audit\Services;

use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Models\AuditMutation;
use App\Base\Authz\Enums\PrincipalType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

final class AuditTraceTimeline
{
    public function __construct(
        private readonly AuditLogPresenter $presenter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forTrace(string $traceId): array
    {
        $traceId = $this->presenter->normalizeTrace($traceId);

        if ($traceId === '') {
            return $this->emptyTimeline($traceId);
        }

        $actions = $this->actions($traceId);
        $mutations = $this->mutations($traceId);
        $entries = [];
        $actors = [];

        foreach ($actions as $action) {
            $summary = $this->presenter->actionSummary($action);
            $actor = $this->presenter->actorLabel($action);
            $actors[$actor] = true;

            $entries[] = [
                'kind' => 'action',
                'id' => $action->id,
                'sort_at' => $this->sortTimestamp($action->occurred_at),
                'occurred_at' => $this->serializeTime($action->occurred_at),
                'actor' => $actor,
                'actor_role' => $action->actor_role,
                'source' => $summary['source'],
                'summary' => $summary['summary'],
                'context' => $summary['context'],
                'result' => $summary['result'],
                'variant' => $summary['variant'],
                'event' => $action->event,
                'payload_json' => $this->presenter->payloadJson($action),
                'url' => $action->url,
                'ip_address' => $action->ip_address,
                'user_agent' => $action->user_agent,
            ];
        }

        foreach ($mutations as $mutation) {
            $actor = $this->presenter->actorLabel($mutation);
            $actors[$actor] = true;

            $entries[] = [
                'kind' => 'mutation',
                'id' => $mutation->id,
                'sort_at' => $this->sortTimestamp($mutation->occurred_at),
                'occurred_at' => $this->serializeTime($mutation->occurred_at),
                'actor' => $actor,
                'actor_role' => $mutation->actor_role,
                'source' => __('Mutation'),
                'summary' => $this->presenter->mutationLabel($mutation),
                'context' => $mutation->auditable_type,
                'result' => $this->presenter->mutationEventLabel($mutation->event),
                'variant' => $this->presenter->mutationEventVariant($mutation->event),
                'event' => $mutation->event,
                'diffs' => $this->presenter->mutationDiffs($mutation),
                'source_row' => $mutation->source,
            ];
        }

        usort($entries, function (array $left, array $right): int {
            $time = $left['sort_at'] <=> $right['sort_at'];

            if ($time !== 0) {
                return $time;
            }

            $kind = $left['kind'] <=> $right['kind'];

            return $kind !== 0 ? $kind : ($left['id'] <=> $right['id']);
        });

        $first = $entries[0]['occurred_at'] ?? null;
        $lastKey = array_key_last($entries);
        $last = $lastKey !== null ? $entries[$lastKey]['occurred_at'] : null;

        return [
            'trace_id' => $traceId,
            'formatted_trace_id' => $this->presenter->formatTrace($traceId),
            'entries' => array_map(function (array $entry): array {
                unset($entry['sort_at']);

                return $entry;
            }, $entries),
            'action_count' => $actions->count(),
            'mutation_count' => $mutations->count(),
            'actor_labels' => array_keys($actors),
            'first_at' => $first,
            'last_at' => $last,
        ];
    }

    /** @return Collection<int, AuditAction> */
    private function actions(string $traceId): Collection
    {
        return AuditAction::query()
            ->leftJoin('users', function ($join): void {
                $join->on('base_audit_actions.actor_id', '=', 'users.id')
                    ->where('base_audit_actions.actor_type', '=', PrincipalType::USER->value);
            })
            ->select('base_audit_actions.*', 'users.name as actor_name')
            ->where('base_audit_actions.trace_id', $traceId)
            ->orderBy('base_audit_actions.occurred_at')
            ->orderBy('base_audit_actions.id')
            ->get();
    }

    /** @return Collection<int, AuditMutation> */
    private function mutations(string $traceId): Collection
    {
        return AuditMutation::query()
            ->leftJoin('users', function ($join): void {
                $join->on('base_audit_mutations.actor_id', '=', 'users.id')
                    ->where('base_audit_mutations.actor_type', '=', PrincipalType::USER->value);
            })
            ->select('base_audit_mutations.*', 'users.name as actor_name')
            ->where('base_audit_mutations.trace_id', $traceId)
            ->orderBy('base_audit_mutations.occurred_at')
            ->orderBy('base_audit_mutations.id')
            ->get();
    }

    private function sortTimestamp(mixed $time): int
    {
        if ($time instanceof CarbonInterface) {
            return $time->getTimestamp();
        }

        return $time !== null ? strtotime((string) $time) ?: 0 : 0;
    }

    private function serializeTime(mixed $time): ?string
    {
        if ($time instanceof CarbonInterface) {
            return $time->toIso8601String();
        }

        return $time !== null ? (string) $time : null;
    }

    /** @return array<string, mixed> */
    private function emptyTimeline(string $traceId): array
    {
        return [
            'trace_id' => $traceId,
            'formatted_trace_id' => $this->presenter->formatTrace($traceId),
            'entries' => [],
            'action_count' => 0,
            'mutation_count' => 0,
            'actor_labels' => [],
            'first_at' => null,
            'last_at' => null,
        ];
    }
}
