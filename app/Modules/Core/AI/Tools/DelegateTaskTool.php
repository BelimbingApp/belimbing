<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\HasToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolArgumentException;
use App\Base\AI\Tools\ToolResult;
use App\Modules\Core\AI\Services\LaraCapabilityMatcher;
use App\Modules\Core\AI\Services\LaraTaskDispatcher;

/**
 * Task delegation tool for Agents.
 *
 * Dispatches a task to a specific Agent or auto-matches the best
 * available agent based on task description. Uses LaraTaskDispatcher for
 * dispatch orchestration and LaraCapabilityMatcher for auto-matching.
 *
 * Returns a dispatch ID that can be used with delegation_status to poll
 * for results.
 *
 * Gated by `ai.tool_delegate.execute` authz capability.
 */
class DelegateTaskTool extends AbstractTool
{
    use HasToolMetadata;

    private const MAX_TASK_LENGTH = 5000;

    public function __construct(
        private readonly LaraTaskDispatcher $dispatcher,
        private readonly LaraCapabilityMatcher $capabilityMatcher,
    ) {}

    public function name(): string
    {
        return 'delegate_task';
    }

    public function description(): string
    {
        return 'Dispatch a task to a Agent for execution. '
            .'Provide a task description and optionally a specific agent_id '
            .'(from agent_list). If no agent_id is given, the best available '
            .'agent is auto-selected based on the task description. '
            .'Returns a dispatch_id for tracking status via delegation_status.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string(
                'task',
                'Description of the task to delegate. Be specific about '
                    .'what the agent should accomplish.'
            )->required()
            ->integer(
                'agent_id',
                'Employee ID of the target Agent. '
                    .'Use agent_list to discover available agents and their IDs. '
                    .'If omitted, the best-matching agent is auto-selected.'
            );
    }

    public function category(): ToolCategory
    {
        return ToolCategory::DELEGATION;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::INTERNAL;
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_delegate.execute';
    }

    protected function metadata(): array
    {
        return [
            'display_name' => 'Delegate Task',
            'summary' => 'Dispatch work to another Agent.',
            'explanation' => 'Queues a task for execution by another Agent. Returns a dispatch ID '
                .'immediately. The dispatched agent runs asynchronously via Laravel queues. '
                .'This tool can only delegate to agents the current user supervises.',
            'setup_requirements' => [
                'At least one other Agent configured',
                'Laravel queue worker running',
            ],
            'test_examples' => [
                [
                    'label' => 'Delegate a task',
                    'input' => ['task' => 'Summarize today\'s activity'],
                ],
            ],
            'health_checks' => [
                'Queue connection active',
                'Delegable agents available',
            ],
            'limits' => [
                'Default 300-second timeout per delegation',
                'Scoped to supervised agents',
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        $task = $this->requireString($arguments, 'task');

        if (mb_strlen($task) > self::MAX_TASK_LENGTH) {
            throw new ToolArgumentException(
                'Task description exceeds maximum length of '.self::MAX_TASK_LENGTH.' characters.'
            );
        }

        $agentId = $this->resolveAgentId($arguments, $task);
        $result = null;

        if ($agentId === null) {
            $result = ToolResult::error(
                'No suitable Agent found for this task. '
                    .'Use agent_list to see available agents, then specify an agent_id explicitly.',
                'no_agent_match',
            );
        } else {
            try {
                $dispatchResult = $this->dispatcher->dispatchForCurrentUser($agentId, $task);
                $result = ToolResult::success($this->formatDispatchResult($dispatchResult));
            } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
                $result = ToolResult::error($e->getMessage(), 'authorization_error');
            } catch (\Throwable $e) {
                $result = ToolResult::error('Dispatching task failed: '.$e->getMessage(), 'dispatch_error');
            }
        }

        return $result;
    }

    /**
     * Resolve the target agent ID from explicit argument or auto-matching.
     */
    private function resolveAgentId(array $arguments, string $task): ?int
    {
        $agentId = $arguments['agent_id'] ?? null;

        if (is_int($agentId)) {
            return $agentId;
        }

        $match = $this->capabilityMatcher->matchBestForTask($task);

        return $match !== null ? $match['employee_id'] : null;
    }

    /**
     * Format the dispatch result as a readable status message.
     *
     * @param  array{dispatch_id: string, status: string, employee_id: int, employee_name: string, task: string, acting_for_user_id: int, created_at: string}  $result
     */
    private function formatDispatchResult(array $result): string
    {
        return 'Task dispatched successfully.'
            ."\n\n".'**Dispatch ID:** '.$result['dispatch_id']
            ."\n".'**Status:** '.$result['status']
            ."\n".'**Assigned to:** '.$result['employee_name'].' (ID: '.$result['employee_id'].')'
            ."\n".'**Task:** '.$result['task']
            ."\n".'**Created:** '.$result['created_at']
            ."\n\n".'Use delegation_status with this dispatch_id to check progress.';
    }
}
