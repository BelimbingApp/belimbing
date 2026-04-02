<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO\ControlPlane;

use App\Modules\Core\AI\Enums\ControlPlaneTarget;
use App\Modules\Core\AI\Enums\TelemetryEventType;

/**
 * Normalized operational telemetry event.
 *
 * Ties together runs, sessions, dispatches, and subsystem actions
 * through shared identifiers so events can be correlated across
 * the AI control plane.
 */
final readonly class TelemetryEvent
{
    /**
     * @param  string  $eventId  Unique event identifier
     * @param  TelemetryEventType  $eventType  Classification of this event
     * @param  string|null  $runId  Correlated run identifier
     * @param  string|null  $sessionId  Correlated session identifier
     * @param  string|null  $dispatchId  Correlated dispatch identifier
     * @param  int|null  $employeeId  Agent employee ID
     * @param  ControlPlaneTarget|null  $targetType  Target subsystem type
     * @param  string|null  $targetId  Target subsystem identifier
     * @param  array<string, mixed>  $payload  Structured event data (no secrets)
     * @param  string  $occurredAt  ISO 8601 timestamp
     */
    public function __construct(
        public string $eventId,
        public TelemetryEventType $eventType,
        public ?string $runId,
        public ?string $sessionId,
        public ?string $dispatchId,
        public ?int $employeeId,
        public ?ControlPlaneTarget $targetType,
        public ?string $targetId,
        public array $payload,
        public string $occurredAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType->value,
            'run_id' => $this->runId,
            'session_id' => $this->sessionId,
            'dispatch_id' => $this->dispatchId,
            'employee_id' => $this->employeeId,
            'target_type' => $this->targetType?->value,
            'target_id' => $this->targetId,
            'payload' => $this->payload,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
