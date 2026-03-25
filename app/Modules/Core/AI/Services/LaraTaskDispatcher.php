<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\Jobs\RunAgentTaskJob;
use App\Modules\Core\AI\Models\AgentTaskDispatch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * Dispatches tasks to AI agents via Laravel queues.
 *
 * Creates a durable dispatch record in the database, then queues
 * a RunAgentTaskJob for asynchronous execution. Returns the dispatch
 * model so callers can format receipts or track status.
 */
class LaraTaskDispatcher
{
    public function __construct(
        private readonly LaraCapabilityMatcher $capabilityMatcher,
    ) {}

    /**
     * Dispatch a task to an accessible Agent on behalf of the current user.
     *
     * Creates a persisted dispatch record and queues the agent job.
     *
     * @param  int  $employeeId  Target agent's employee ID
     * @param  string  $task  Task description for the agent
     * @param  array{ticket_id?: int, model_override?: string, source?: string}  $options  Optional dispatch options
     *
     * @throws AuthorizationException When the target agent is not accessible
     */
    public function dispatchForCurrentUser(int $employeeId, string $task, array $options = []): AgentTaskDispatch
    {
        $agent = $this->capabilityMatcher->findAccessibleAgentById($employeeId);
        $actingForUserId = auth()->id();

        if ($agent === null || ! is_int($actingForUserId)) {
            throw new AuthorizationException(__('Unauthorized Agent dispatch target.'));
        }

        $dispatch = AgentTaskDispatch::query()->create([
            'id' => 'agent_dispatch_'.Str::random(12),
            'employee_id' => $agent['employee_id'],
            'acting_for_user_id' => $actingForUserId,
            'ticket_id' => $options['ticket_id'] ?? null,
            'task' => trim($task),
            'status' => 'queued',
            'meta' => [
                'model_override' => $options['model_override'] ?? null,
                'source' => $options['source'] ?? 'delegate_task',
                'employee_name' => $agent['name'],
            ],
        ]);

        RunAgentTaskJob::dispatch($dispatch->id);

        return $dispatch;
    }
}
