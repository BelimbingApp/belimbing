<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO\Orchestration;

use App\Modules\Core\AI\Enums\RoutingTarget;

/**
 * Deterministic routing decision returned by the routing engine.
 *
 * Always includes the chosen target type and reasons. When the target
 * is an agent, includes the resolved agent ID. When a skill pack
 * is chosen, includes the pack identifier.
 */
final readonly class RoutingDecision
{
    /**
     * @param  RoutingTarget  $target  Where the work is routed
     * @param  int|null  $agentEmployeeId  Resolved agent (when target is Agent)
     * @param  string|null  $agentName  Display name of the resolved agent
     * @param  string|null  $skillPackId  Resolved skill pack (when target is SkillPack)
     * @param  int  $confidenceScore  Routing confidence (0 = no signal, higher = more confident)
     * @param  list<string>  $reasons  Human-readable explanation of why this target was chosen
     * @param  array<string, mixed>  $meta  Additional routing metadata for audit/debug
     */
    public function __construct(
        public RoutingTarget $target,
        public ?int $agentEmployeeId = null,
        public ?string $agentName = null,
        public ?string $skillPackId = null,
        public int $confidenceScore = 0,
        public array $reasons = [],
        public array $meta = [],
    ) {}

    /**
     * Create a decision for local execution.
     *
     * @param  list<string>  $reasons
     */
    public static function local(array $reasons = []): self
    {
        return new self(
            target: RoutingTarget::Local,
            reasons: $reasons !== [] ? $reasons : ['No delegation target matched; executing locally.'],
        );
    }

    /**
     * Create a decision for agent delegation.
     *
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $meta
     */
    public static function agent(int $agentEmployeeId, string $agentName, int $confidenceScore, array $reasons = [], array $meta = []): self
    {
        return new self(
            target: RoutingTarget::Agent,
            agentEmployeeId: $agentEmployeeId,
            agentName: $agentName,
            confidenceScore: $confidenceScore,
            reasons: $reasons,
            meta: $meta,
        );
    }

    /**
     * Create a decision for skill-pack execution.
     *
     * @param  list<string>  $reasons
     */
    public static function skillPack(string $skillPackId, array $reasons = []): self
    {
        return new self(
            target: RoutingTarget::SkillPack,
            skillPackId: $skillPackId,
            reasons: $reasons,
        );
    }

    /**
     * Serialize for persistence and audit logging.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'target' => $this->target->value,
            'agent_employee_id' => $this->agentEmployeeId,
            'agent_name' => $this->agentName,
            'skill_pack_id' => $this->skillPackId,
            'confidence_score' => $this->confidenceScore,
            'reasons' => $this->reasons,
            'meta' => $this->meta !== [] ? $this->meta : null,
        ], fn (mixed $v): bool => $v !== null);
    }
}
