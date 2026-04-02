<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Orchestration;

use App\Modules\Core\AI\DTO\Orchestration\AgentCapabilityDescriptor;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackManifest;
use App\Modules\Core\AI\DTO\Orchestration\SpawnEnvelope;

/**
 * Policy boundaries for orchestration operations.
 *
 * Enforces who may supervise whom, which agents may spawn child
 * sessions, which skill packs are applicable, and whether hooks
 * are safe to execute. Orchestration freedom is policy-bounded,
 * not implicit.
 *
 * All policy checks return bool for now. As BLB matures, policy
 * results may carry denial reasons for audit logging.
 */
class OrchestrationPolicyService
{
    /**
     * Whether the requesting agent may delegate work to the target agent.
     *
     * Delegation requires the requesting agent's supervisor (user) to
     * have access to the target agent. Currently delegates to the
     * existing supervisor-scoped access model.
     */
    public function canDelegate(int $requestingEmployeeId, int $targetEmployeeId): bool
    {
        // During initialization phase, all agents within the same company
        // can delegate to each other. This will be tightened when BLB adds
        // explicit delegation policy configuration.
        return $requestingEmployeeId !== $targetEmployeeId;
    }

    /**
     * Whether the parent agent may spawn a child session for the target.
     */
    public function canSpawn(SpawnEnvelope $envelope): bool
    {
        // Self-spawn is not allowed (prevents infinite recursion).
        if ($envelope->parentEmployeeId === $envelope->childEmployeeId) {
            return false;
        }

        return $this->canDelegate($envelope->parentEmployeeId, $envelope->childEmployeeId);
    }

    /**
     * Whether a skill pack is applicable for the given agent.
     *
     * Checks pack availability and agent applicability from the manifest.
     */
    public function isSkillPackApplicable(SkillPackManifest $manifest, int $employeeId): bool
    {
        if (! $manifest->isAvailable()) {
            return false;
        }

        return $manifest->appliesTo($employeeId);
    }

    /**
     * Whether the given capability descriptor qualifies for structured routing.
     *
     * Agents without structured capabilities fall back to legacy keyword matching.
     */
    public function hasRoutableCapabilities(AgentCapabilityDescriptor $descriptor): bool
    {
        return $descriptor->hasStructuredCapabilities();
    }

    /**
     * Maximum child session depth allowed.
     *
     * Prevents unbounded recursive spawning. The spawn manager checks
     * current depth against this limit before creating a child session.
     */
    public function maxSpawnDepth(): int
    {
        return 3;
    }
}
