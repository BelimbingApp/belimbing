<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane;

use App\Modules\Core\AI\DTO\ControlPlane\TelemetryEvent;
use App\Modules\Core\AI\Enums\ControlPlaneTarget;
use App\Modules\Core\AI\Enums\TelemetryEventType;
use App\Modules\Core\AI\Models\TelemetryEvent as TelemetryEventModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Records and queries normalized operational telemetry for the AI control plane.
 *
 * Events are correlated through shared identifiers (run_id, session_id,
 * dispatch_id, employee_id) so operators can trace actions across
 * subsystem boundaries. Secrets are never stored in telemetry payloads.
 */
class OperationalTelemetryService
{
    /**
     * Record a telemetry event.
     *
     * @param  TelemetryEventType  $eventType  Event classification
     * @param  array<string, mixed>  $payload  Structured event data (no secrets)
     * @param  string|null  $runId  Correlated run identifier
     * @param  string|null  $sessionId  Correlated session identifier
     * @param  string|null  $dispatchId  Correlated dispatch identifier
     * @param  int|null  $employeeId  Agent employee ID
     * @param  ControlPlaneTarget|null  $targetType  Target subsystem type
     * @param  string|null  $targetId  Target subsystem identifier
     */
    public function record(
        TelemetryEventType $eventType,
        array $payload = [],
        ?string $runId = null,
        ?string $sessionId = null,
        ?string $dispatchId = null,
        ?int $employeeId = null,
        ?ControlPlaneTarget $targetType = null,
        ?string $targetId = null,
    ): TelemetryEvent {
        $eventId = TelemetryEventModel::ID_PREFIX.Str::ulid()->toBase32();

        $model = TelemetryEventModel::query()->create([
            'id' => $eventId,
            'event_type' => $eventType,
            'run_id' => $runId,
            'session_id' => $sessionId,
            'dispatch_id' => $dispatchId,
            'employee_id' => $employeeId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);

        return new TelemetryEvent(
            eventId: $model->id,
            eventType: $eventType,
            runId: $runId,
            sessionId: $sessionId,
            dispatchId: $dispatchId,
            employeeId: $employeeId,
            targetType: $targetType,
            targetId: $targetId,
            payload: $payload,
            occurredAt: $model->occurred_at->toIso8601String(),
        );
    }

    /**
     * Query events for a specific run.
     *
     * @return list<TelemetryEvent>
     */
    public function forRun(string $runId): array
    {
        return $this->toEvents(
            TelemetryEventModel::query()
                ->where('run_id', $runId)
                ->orderBy('occurred_at')
                ->get()
        );
    }

    /**
     * Query events for a specific session.
     *
     * @return list<TelemetryEvent>
     */
    public function forSession(string $sessionId): array
    {
        return $this->toEvents(
            TelemetryEventModel::query()
                ->where('session_id', $sessionId)
                ->orderBy('occurred_at')
                ->get()
        );
    }

    /**
     * Query events for a specific agent.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  int  $limit  Maximum events to return
     * @return list<TelemetryEvent>
     */
    public function forAgent(int $employeeId, int $limit = 100): array
    {
        return $this->toEvents(
            TelemetryEventModel::query()
                ->where('employee_id', $employeeId)
                ->orderByDesc('occurred_at')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Query events by type within a time window.
     *
     * @param  TelemetryEventType  $eventType  Event type filter
     * @param  int  $minutesBack  How far back to look
     * @param  int  $limit  Maximum events to return
     * @return list<TelemetryEvent>
     */
    public function byType(TelemetryEventType $eventType, int $minutesBack = 60, int $limit = 100): array
    {
        return $this->toEvents(
            TelemetryEventModel::query()
                ->where('event_type', $eventType)
                ->where('occurred_at', '>=', now()->subMinutes($minutesBack))
                ->orderByDesc('occurred_at')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Count events by type within a time window.
     *
     * @return array<string, int>
     */
    public function countByType(int $minutesBack = 60): array
    {
        $counts = TelemetryEventModel::query()
            ->where('occurred_at', '>=', now()->subMinutes($minutesBack))
            ->selectRaw('event_type, count(*) as total')
            ->groupBy('event_type')
            ->pluck('total', 'event_type')
            ->all();

        // Ensure all types are represented
        foreach (TelemetryEventType::cases() as $type) {
            if (! isset($counts[$type->value])) {
                $counts[$type->value] = 0;
            }
        }

        return $counts;
    }

    /**
     * Convert a collection of models to DTOs.
     *
     * @param  Collection<int, TelemetryEventModel>  $models
     * @return list<TelemetryEvent>
     */
    private function toEvents(Collection $models): array
    {
        return $models->map(fn (TelemetryEventModel $m): TelemetryEvent => new TelemetryEvent(
            eventId: $m->id,
            eventType: $m->event_type,
            runId: $m->run_id,
            sessionId: $m->session_id,
            dispatchId: $m->dispatch_id,
            employeeId: $m->employee_id,
            targetType: $m->target_type,
            targetId: $m->target_id,
            payload: $m->payload ?? [],
            occurredAt: $m->occurred_at->toIso8601String(),
        ))->values()->all();
    }
}
