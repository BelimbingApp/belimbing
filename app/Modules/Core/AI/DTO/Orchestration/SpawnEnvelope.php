<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO\Orchestration;

/**
 * Envelope defining the bounded scope for a child agent session.
 *
 * Carries everything the spawn manager needs to create a child session:
 * who owns it, what task to perform, what context to inject, and what
 * execution boundaries apply.
 */
final readonly class SpawnEnvelope
{
    /**
     * @param  int  $parentEmployeeId  Agent spawning the child session
     * @param  int  $childEmployeeId  Agent that will execute the child session
     * @param  string  $task  Task description for the child
     * @param  string|null  $parentSessionId  Parent session ID for lineage
     * @param  string|null  $parentRunId  Parent run ID for lineage
     * @param  string|null  $parentDispatchId  Parent dispatch ID (if routed through dispatch)
     * @param  string|null  $taskType  Task type discriminator
     * @param  array<string, mixed>  $contextPayload  Additional context to inject into child prompt
     * @param  int  $maxIterations  Maximum tool-calling iterations for the child
     * @param  string|null  $modelOverride  Optional model override for the child session
     * @param  int|null  $actingForUserId  Human user on whose behalf the child acts
     */
    public function __construct(
        public int $parentEmployeeId,
        public int $childEmployeeId,
        public string $task,
        public ?string $parentSessionId = null,
        public ?string $parentRunId = null,
        public ?string $parentDispatchId = null,
        public ?string $taskType = null,
        public array $contextPayload = [],
        public int $maxIterations = 10,
        public ?string $modelOverride = null,
        public ?int $actingForUserId = null,
    ) {}

    /**
     * Whether the spawn has explicit parent session lineage.
     */
    public function hasSessionLineage(): bool
    {
        return $this->parentSessionId !== null;
    }

    /**
     * Serialize for persistence in orchestration session records.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'parent_employee_id' => $this->parentEmployeeId,
            'child_employee_id' => $this->childEmployeeId,
            'task' => $this->task,
            'parent_session_id' => $this->parentSessionId,
            'parent_run_id' => $this->parentRunId,
            'parent_dispatch_id' => $this->parentDispatchId,
            'task_type' => $this->taskType,
            'context_payload' => $this->contextPayload !== [] ? $this->contextPayload : null,
            'max_iterations' => $this->maxIterations,
            'model_override' => $this->modelOverride,
            'acting_for_user_id' => $this->actingForUserId,
        ], fn (mixed $v): bool => $v !== null);
    }
}
