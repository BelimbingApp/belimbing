<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Orchestration;

use App\Modules\Core\AI\DTO\Orchestration\SpawnEnvelope;
use App\Modules\Core\AI\Enums\OrchestrationSessionStatus;
use App\Modules\Core\AI\Exceptions\SpawnDepthExceededException;
use App\Modules\Core\AI\Exceptions\SpawnPolicyViolationException;
use App\Modules\Core\AI\Jobs\SpawnAgentSessionJob;
use App\Modules\Core\AI\Models\OrchestrationSession;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Creates and manages child agent sessions with explicit parent lineage.
 *
 * The spawn manager is the single entry point for creating child sessions.
 * It validates policy constraints, enforces depth limits, creates the
 * OrchestrationSession record, and dispatches the execution job.
 *
 * Responsibilities:
 * - create child sessions with explicit parent run/session lineage
 * - set scope, prompt context, and execution boundaries for child work
 * - enforce depth limits via OrchestrationPolicyService
 * - support one-shot bounded task execution (interactive follow-up deferred)
 */
class SessionSpawnManager
{
    public function __construct(
        private readonly OrchestrationPolicyService $policy,
    ) {}

    /**
     * Spawn a bounded child agent session.
     *
     * Creates an OrchestrationSession record with lineage, validates policy
     * constraints, and dispatches a SpawnAgentSessionJob for execution.
     *
     * @throws SpawnPolicyViolationException When policy denies the spawn
     * @throws SpawnDepthExceededException When spawn depth exceeds the limit
     */
    public function spawn(SpawnEnvelope $envelope): OrchestrationSession
    {
        $this->validatePolicy($envelope);
        $depth = $this->resolveDepth($envelope);
        $this->validateDepth($depth);

        $session = $this->createSession($envelope, $depth);

        SpawnAgentSessionJob::dispatch($session->id);

        return $session;
    }

    /**
     * Look up an orchestration session by ID.
     */
    public function find(string $sessionId): ?OrchestrationSession
    {
        return OrchestrationSession::query()->find($sessionId);
    }

    /**
     * Look up all child sessions spawned by a parent session.
     *
     * @return Collection<int, OrchestrationSession>
     */
    public function childrenOf(string $parentSessionId): Collection
    {
        return OrchestrationSession::query()
            ->where('parent_session_id', $parentSessionId)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Validate spawn policy constraints.
     *
     * @throws SpawnPolicyViolationException
     */
    private function validatePolicy(SpawnEnvelope $envelope): void
    {
        if (! $this->policy->canSpawn($envelope)) {
            throw new SpawnPolicyViolationException(
                $envelope->parentEmployeeId,
                $envelope->childEmployeeId,
            );
        }
    }

    /**
     * Resolve the depth for the new child session.
     *
     * If the envelope references a parent session, the child's depth is
     * parent depth + 1. Otherwise, this is a root spawn at depth 1.
     */
    private function resolveDepth(SpawnEnvelope $envelope): int
    {
        if ($envelope->parentSessionId === null) {
            return 1;
        }

        $parentSession = OrchestrationSession::query()->find($envelope->parentSessionId);

        if ($parentSession === null) {
            return 1;
        }

        return $parentSession->depth + 1;
    }

    /**
     * Validate spawn depth does not exceed the configured limit.
     *
     * @throws SpawnDepthExceededException
     */
    private function validateDepth(int $depth): void
    {
        $maxDepth = $this->policy->maxSpawnDepth();

        if ($depth > $maxDepth) {
            throw new SpawnDepthExceededException($depth, $maxDepth);
        }
    }

    /**
     * Create the OrchestrationSession record.
     */
    private function createSession(SpawnEnvelope $envelope, int $depth): OrchestrationSession
    {
        /** @var OrchestrationSession */
        return OrchestrationSession::query()->create([
            'id' => OrchestrationSession::ID_PREFIX.Str::random(12),
            'parent_session_id' => $envelope->parentSessionId,
            'parent_run_id' => $envelope->parentRunId,
            'parent_dispatch_id' => $envelope->parentDispatchId,
            'parent_employee_id' => $envelope->parentEmployeeId,
            'child_employee_id' => $envelope->childEmployeeId,
            'acting_for_user_id' => $envelope->actingForUserId,
            'task' => $envelope->task,
            'task_type' => $envelope->taskType,
            'status' => OrchestrationSessionStatus::Pending,
            'spawn_envelope' => $envelope->toArray(),
            'depth' => $depth,
        ]);
    }
}
