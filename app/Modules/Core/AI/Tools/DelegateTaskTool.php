<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolArgumentException;
use App\Base\AI\Tools\ToolResult;
use App\Modules\Core\AI\DTO\Orchestration\RoutingDecision;
use App\Modules\Core\AI\DTO\Orchestration\RoutingRequest;
use App\Modules\Core\AI\Enums\RoutingTarget;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\AgentExecutionContext;
use App\Modules\Core\AI\Services\LaraTaskDispatcher;
use App\Modules\Core\AI\Services\LaraTaskProfileSelector;
use App\Modules\Core\AI\Services\Orchestration\TaskRoutingService;
use App\Modules\Core\AI\Services\RuntimeSessionContext;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Task delegation tool for Agents.
 *
 * Dispatches a task to a specific Agent or auto-matches the best
 * available agent based on task description. Uses TaskRoutingService for
 * capability-based routing and LaraTaskDispatcher for dispatch execution.
 *
 * Returns a dispatch ID that can be used with delegation_status to poll
 * for results.
 *
 * Gated by `ai.tool_delegate.execute` authz capability.
 */
class DelegateTaskTool extends AbstractTool
{
    use ProvidesToolMetadata;

    private const CHARACTERS_SUFFIX = ' characters.';

    private const MAX_TASK_LENGTH = 5000;

    private const MAX_TASK_TYPE_LENGTH = 60;

    public function __construct(
        private readonly LaraTaskDispatcher $dispatcher,
        private readonly TaskRoutingService $router,
        private readonly AgentExecutionContext $executionContext,
        private readonly LaraTaskProfileSelector $taskProfileSelector,
        private readonly RuntimeSessionContext $sessionContext,
    ) {}

    public function name(): string
    {
        return 'delegate_task';
    }

    public function description(): string
    {
        return 'Dispatch a task to an Agent for execution. '
            .'Provide a task description and optionally a specific agent_id '
            .'(from agent_list). If no agent_id is given, the best available '
            .'agent is auto-selected based on the task description. When no '
            .'delegated agent matches, Lara may use a built-in task profile instead. '
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
            ->string(
                'task_type',
                'Task type discriminator (e.g., "resolve_ticket", "review_qac_case"). '
                    .'Classifies the kind of work being dispatched. Maximum '.self::MAX_TASK_TYPE_LENGTH.self::CHARACTERS_SUFFIX
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
            'summary' => 'Dispatch work to another Agent or Lara task profile.',
            'explanation' => 'Queues a task for asynchronous execution. Returns a dispatch ID '
                .'immediately. The work may go to another Agent the current user supervises, '
                .'or to one of Lara\'s built-in task profiles when no delegated Agent matches.',
            'setup_requirements' => [
                'At least one delegated Agent configured or a Lara task profile available',
                'Laravel queue worker running',
            ],
            'test_examples' => [
                [
                    'label' => 'Delegate a task',
                    'input' => [
                        'task' => 'Summarize today\'s activity',
                        'task_type' => 'general',
                    ],
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
        $taskType = $this->requireString($arguments, 'task_type');

        if (mb_strlen($task) > self::MAX_TASK_LENGTH) {
            throw new ToolArgumentException(
                'Task description exceeds maximum length of '.self::MAX_TASK_LENGTH.self::CHARACTERS_SUFFIX
            );
        }

        if (mb_strlen($taskType) > self::MAX_TASK_TYPE_LENGTH) {
            throw new ToolArgumentException(
                'task_type exceeds maximum length of '.self::MAX_TASK_TYPE_LENGTH.self::CHARACTERS_SUFFIX
            );
        }

        $decision = $this->routeTask($arguments, $task, $taskType);

        if ($decision->target !== RoutingTarget::Agent || $decision->agentEmployeeId === null) {
            return $this->dispatchTaskProfileFallback($task, $taskType, $decision->reasons);
        }

        try {
            $dispatchResult = $this->dispatcher->dispatchForCurrentUser(
                $decision->agentEmployeeId,
                $taskType,
                $task,
                $this->dispatchOptions(),
            );

            return ToolResult::success($this->formatDispatchResult($dispatchResult));
        } catch (AuthorizationException $e) {
            return ToolResult::error($e->getMessage(), 'authorization_error');
        } catch (\Throwable $e) {
            return ToolResult::error('Dispatching task failed: '.$e->getMessage(), 'dispatch_error');
        }
    }

    /**
     * Route the task through the orchestration routing service.
     *
     * Builds a RoutingRequest from tool arguments, using the agent
     * execution context for the requesting employee ID when available
     * (queued job context), falling back to Lara's well-known ID for
     * interactive chat.
     */
    private function routeTask(array $arguments, string $task, string $taskType): RoutingDecision
    {
        $agentId = $arguments['agent_id'] ?? null;
        $requestingEmployeeId = $this->executionContext->active()
            ? $this->executionContext->employeeId()
            : Employee::LARA_ID;

        $request = new RoutingRequest(
            task: $task,
            requestingEmployeeId: $requestingEmployeeId,
            actingForUserId: auth()->id(),
            preferredAgentId: is_int($agentId) ? $agentId : null,
            taskType: $taskType,
            sourceContext: 'delegate_task_tool',
        );

        return $this->router->route($request);
    }

    /**
     * Format the dispatch model as a readable status message.
     */
    private function formatDispatchResult(OperationDispatch $dispatch): string
    {
        $employeeName = data_get($dispatch->meta, 'task_profile_label')
            ? 'Lara '.data_get($dispatch->meta, 'task_profile_label')
            : (data_get($dispatch->meta, 'employee_name') ?? 'Agent #'.$dispatch->employee_id);

        return 'Task dispatched successfully.'
            ."\n\n".'**Dispatch ID:** '.$dispatch->id
            ."\n".'**Status:** '.$dispatch->status->value
            ."\n".'**Assigned to:** '.$employeeName.' (ID: '.$dispatch->employee_id.')'
            ."\n".'**Task:** '.$dispatch->task
            ."\n".'**Created:** '.$dispatch->created_at?->toIso8601String()
            ."\n\n".'Use delegation_status with this dispatch_id to check progress.';
    }

    /**
     * @param  list<string>  $routingReasons
     */
    private function dispatchTaskProfileFallback(string $task, string $taskType, array $routingReasons): ToolResult
    {
        $selection = $this->taskProfileSelector->select($task, $taskType);

        if ($selection === null) {
            return ToolResult::error(
                'No suitable Agent or Lara task profile found for this task. '
                    .'Use agent_list to see available agents, then specify an agent_id explicitly.'
                    .($routingReasons !== [] ? "\n\nRouting: ".implode(' ', $routingReasons) : ''),
                'no_agent_match',
            );
        }

        try {
            $dispatch = $this->dispatcher->dispatchTaskProfileForCurrentUser(
                $selection['definition']->key,
                $task,
                $this->dispatchOptions(),
            );
            $message = $this->formatDispatchResult($dispatch);
        } catch (AuthorizationException $e) {
            return ToolResult::error($e->getMessage(), 'authorization_error');
        } catch (\Throwable $e) {
            return ToolResult::error('Dispatching task failed: '.$e->getMessage(), 'dispatch_error');
        }

        if ($selection['reasons'] === []) {
            return ToolResult::success($message);
        }

        return ToolResult::success($message."\n\nRouting: ".implode(' ', [...$routingReasons, ...$selection['reasons']]));
    }

    /**
     * @return array{session_id?: string}
     */
    private function dispatchOptions(): array
    {
        $sessionId = $this->sessionContext->sessionId();

        return $sessionId !== null
            ? ['session_id' => $sessionId]
            : [];
    }
}
