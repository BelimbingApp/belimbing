<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO\ControlPlane;

use App\Modules\Core\AI\Enums\ControlPlaneTarget;
use App\Modules\Core\AI\Enums\PresenceState;
use App\Modules\Core\AI\Enums\ToolHealthState;
use App\Modules\Core\AI\Enums\ToolReadiness;

/**
 * Unified snapshot of readiness, health, and presence for a control-plane target.
 *
 * These three dimensions are intentionally separate:
 * - Readiness: can this thing be used in principle?
 * - Health: is it behaving correctly right now?
 * - Presence: is it live/active/reachable at the moment?
 */
final readonly class HealthSnapshot
{
    /**
     * @param  ControlPlaneTarget  $targetType  What kind of entity this describes
     * @param  string  $targetId  Identifier for the entity (tool name, agent ID, provider name, etc.)
     * @param  ToolReadiness  $readiness  Configuration/authorization readiness
     * @param  ToolHealthState  $health  Behavioral health based on recent verification
     * @param  PresenceState  $presence  Live activity/reachability
     * @param  string  $explanation  Human-readable summary of the current state
     * @param  string  $measuredAt  ISO 8601 timestamp when this snapshot was taken
     */
    public function __construct(
        public ControlPlaneTarget $targetType,
        public string $targetId,
        public ToolReadiness $readiness,
        public ToolHealthState $health,
        public PresenceState $presence,
        public string $explanation,
        public string $measuredAt,
    ) {}

    /**
     * @return array{target_type: string, target_id: string, readiness: string, health: string, presence: string, explanation: string, measured_at: string}
     */
    public function toArray(): array
    {
        return [
            'target_type' => $this->targetType->value,
            'target_id' => $this->targetId,
            'readiness' => $this->readiness->value,
            'health' => $this->health->value,
            'presence' => $this->presence->value,
            'explanation' => $this->explanation,
            'measured_at' => $this->measuredAt,
        ];
    }
}
