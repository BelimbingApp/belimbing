<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\Enums\OperationStatus;
use App\Modules\Core\AI\Enums\OperationType;
use App\Modules\Core\AI\Jobs\RunAgentTaskJob;
use App\Modules\Core\AI\Jobs\RunLaraTaskProfileJob;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\Orchestration\AgentCapabilityCatalog;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Illuminate\Support\Str;

/**
 * Dispatches tasks to AI agents via Laravel queues.
 *
 * Creates a durable dispatch record in the operations ledger, then queues
 * a RunAgentTaskJob for asynchronous execution. Uses the AgentCapabilityCatalog
 * for agent validation. Returns the dispatch model so callers can format
 * receipts or track status.
 */
class LaraTaskDispatcher
{
    public function __construct(
        private readonly AgentCapabilityCatalog $catalog,
        private readonly LaraTaskExecutionProfileRegistry $profileRegistry,
    ) {}

    /**
     * Dispatch a task to an accessible Agent on behalf of the current user.
     *
     * Creates a persisted dispatch record and queues the agent job.
     * Validates the target agent via the capability catalog rather than
     * the legacy matcher.
     *
     * @param  int  $employeeId  Target agent's employee ID
     * @param  string  $taskType  Task type discriminator (e.g., 'resolve_ticket')
     * @param  string  $task  Task description for the agent
     * @param  array{entity_type?: string, entity_id?: int, model_override?: string, session_id?: string, source?: string}  $options  Optional dispatch options
     *
     * @throws AuthorizationException When the target agent is not accessible
     */
    public function dispatchForCurrentUser(int $employeeId, string $taskType, string $task, array $options = []): OperationDispatch
    {
        $descriptor = $this->catalog->descriptorFor($employeeId);
        $actingForUserId = auth()->id();

        if ($descriptor === null || ! is_int($actingForUserId)) {
            throw new AuthorizationException(__('Unauthorized Agent dispatch target.'));
        }

        $dispatch = OperationDispatch::query()->create([
            'id' => OperationDispatch::ID_PREFIX.Str::random(12),
            'operation_type' => OperationType::AgentTask,
            'employee_id' => $descriptor->employeeId,
            'acting_for_user_id' => $actingForUserId,
            'task' => trim($task),
            'status' => OperationStatus::Queued,
            'meta' => [
                'task_type' => $taskType,
                'model_override' => $options['model_override'] ?? null,
                'session_id' => $options['session_id'] ?? null,
                'source' => $options['source'] ?? 'delegate_task',
                'employee_name' => $descriptor->name,
            ],
            'entity_type' => $options['entity_type'] ?? null,
            'entity_id' => $options['entity_id'] ?? null,
        ]);

        RunAgentTaskJob::dispatch($dispatch->id);

        return $dispatch;
    }

    /**
     * Dispatch a Lara task profile for asynchronous execution.
     *
     * @param  string  $taskKey  Registered Lara task profile key
     * @param  string  $task  Task description for the profile worker
     * @param  array{entity_type?: string, entity_id?: int, session_id?: string, source?: string}  $options
     *
     * @throws AuthorizationException When there is no authenticated user context
     * @throws InvalidArgumentException When the task profile is unknown
     */
    public function dispatchTaskProfileForCurrentUser(string $taskKey, string $task, array $options = []): OperationDispatch
    {
        $profile = $this->profileRegistry->find($taskKey);
        $actingForUserId = auth()->id();

        if ($profile === null) {
            throw new InvalidArgumentException('Unknown Lara task profile: '.$taskKey);
        }

        if (! is_int($actingForUserId)) {
            throw new AuthorizationException(__('Unauthenticated Lara task dispatch.'));
        }

        $dispatch = OperationDispatch::query()->create([
            'id' => OperationDispatch::ID_PREFIX.Str::random(12),
            'operation_type' => OperationType::AgentTask,
            'employee_id' => Employee::LARA_ID,
            'acting_for_user_id' => $actingForUserId,
            'task' => trim($task),
            'status' => OperationStatus::Queued,
            'meta' => [
                'task_type' => $taskKey,
                'task_profile' => $taskKey,
                'session_id' => $options['session_id'] ?? null,
                'source' => $options['source'] ?? 'lara_task_profile',
                'employee_name' => 'Lara',
                'task_profile_label' => $profile->label,
            ],
            'entity_type' => $options['entity_type'] ?? null,
            'entity_id' => $options['entity_id'] ?? null,
        ]);

        RunLaraTaskProfileJob::dispatch($dispatch->id);

        return $dispatch;
    }
}
