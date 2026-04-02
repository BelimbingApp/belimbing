<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

/**
 * Scoped execution context for agent queue jobs.
 *
 * Holds the current agent identity, dispatch metadata, and orchestration
 * lineage during queued job execution. Tools like TicketUpdateTool read
 * this to attribute actions to the correct agent principal rather than
 * the authenticated user.
 *
 * Orchestration lineage fields (orchestrationSessionId, parentDispatchId)
 * link child executions back to their parent spawn chain.
 *
 * Registered as a singleton — cleared in job's finally block.
 */
final class AgentExecutionContext
{
    private ?int $employeeId = null;

    private ?int $actingForUserId = null;

    private ?string $entityType = null;

    private ?int $entityId = null;

    private ?string $dispatchId = null;

    private ?string $orchestrationSessionId = null;

    private ?string $parentDispatchId = null;

    /**
     * Set the execution context for an agent job.
     *
     * @param  string|null  $orchestrationSessionId  Orchestration session ID for child-session lineage
     * @param  string|null  $parentDispatchId  Parent dispatch ID that spawned this execution
     */
    public function set(
        int $employeeId,
        ?int $actingForUserId,
        ?string $entityType,
        ?int $entityId,
        string $dispatchId,
        ?string $orchestrationSessionId = null,
        ?string $parentDispatchId = null,
    ): void {
        $this->employeeId = $employeeId;
        $this->actingForUserId = $actingForUserId;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->dispatchId = $dispatchId;
        $this->orchestrationSessionId = $orchestrationSessionId;
        $this->parentDispatchId = $parentDispatchId;
    }

    /**
     * Clear the execution context after job completion.
     */
    public function clear(): void
    {
        $this->employeeId = null;
        $this->actingForUserId = null;
        $this->entityType = null;
        $this->entityId = null;
        $this->dispatchId = null;
        $this->orchestrationSessionId = null;
        $this->parentDispatchId = null;
    }

    /**
     * Whether an agent execution context is currently active.
     */
    public function active(): bool
    {
        return $this->employeeId !== null;
    }

    /**
     * Whether this execution is part of a child session lineage.
     */
    public function hasOrchestrationLineage(): bool
    {
        return $this->orchestrationSessionId !== null;
    }

    public function employeeId(): ?int
    {
        return $this->employeeId;
    }

    public function actingForUserId(): ?int
    {
        return $this->actingForUserId;
    }

    public function entityType(): ?string
    {
        return $this->entityType;
    }

    public function entityId(): ?int
    {
        return $this->entityId;
    }

    public function dispatchId(): ?string
    {
        return $this->dispatchId;
    }

    public function orchestrationSessionId(): ?string
    {
        return $this->orchestrationSessionId;
    }

    public function parentDispatchId(): ?string
    {
        return $this->parentDispatchId;
    }
}
