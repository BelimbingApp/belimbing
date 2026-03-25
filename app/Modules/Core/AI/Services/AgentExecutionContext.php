<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

/**
 * Scoped execution context for agent queue jobs.
 *
 * Holds the current agent identity and dispatch metadata during
 * queued job execution. Tools like TicketUpdateTool read this to
 * attribute actions to the correct agent principal rather than
 * the authenticated user.
 *
 * Registered as a singleton — cleared in job's finally block.
 */
final class AgentExecutionContext
{
    private ?int $employeeId = null;

    private ?int $actingForUserId = null;

    private ?int $ticketId = null;

    private ?string $dispatchId = null;

    /**
     * Set the execution context for an agent job.
     */
    public function set(int $employeeId, int $actingForUserId, ?int $ticketId, string $dispatchId): void
    {
        $this->employeeId = $employeeId;
        $this->actingForUserId = $actingForUserId;
        $this->ticketId = $ticketId;
        $this->dispatchId = $dispatchId;
    }

    /**
     * Clear the execution context after job completion.
     */
    public function clear(): void
    {
        $this->employeeId = null;
        $this->actingForUserId = null;
        $this->ticketId = null;
        $this->dispatchId = null;
    }

    /**
     * Whether an agent execution context is currently active.
     */
    public function active(): bool
    {
        return $this->employeeId !== null;
    }

    public function employeeId(): ?int
    {
        return $this->employeeId;
    }

    public function actingForUserId(): ?int
    {
        return $this->actingForUserId;
    }

    public function ticketId(): ?int
    {
        return $this->ticketId;
    }

    public function dispatchId(): ?string
    {
        return $this->dispatchId;
    }
}
