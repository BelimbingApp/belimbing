<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolArgumentException;
use App\Modules\Core\AI\Services\LaraCapabilityMatcher;
use App\Modules\Core\AI\Services\LaraTaskDispatcher;

/**
 * Task delegation tool for Digital Workers.
 *
 * Dispatches a task to a specific Digital Worker or auto-matches the best
 * available worker based on task description. Uses LaraTaskDispatcher for
 * dispatch orchestration and LaraCapabilityMatcher for auto-matching.
 *
 * Returns a dispatch ID that can be used with delegation_status to poll
 * for results.
 *
 * Gated by `ai.tool_delegate.execute` authz capability.
 */
class DelegateTaskTool extends AbstractTool
{
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
        return 'Dispatch a task to a Digital Worker for execution. '
            .'Provide a task description and optionally a specific worker_id '
            .'(from worker_list). If no worker_id is given, the best available '
            .'worker is auto-selected based on the task description. '
            .'Returns a dispatch_id for tracking status via delegation_status.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string(
                'task',
                'Description of the task to delegate. Be specific about '
                    .'what the worker should accomplish.'
            )->required()
            ->integer(
                'worker_id',
                'Employee ID of the target Digital Worker. '
                    .'Use worker_list to discover available workers and their IDs. '
                    .'If omitted, the best-matching worker is auto-selected.'
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

    protected function handle(array $arguments): string
    {
        $task = $this->requireString($arguments, 'task');

        if (mb_strlen($task) > self::MAX_TASK_LENGTH) {
            throw new ToolArgumentException(
                'Task description exceeds maximum length of '.self::MAX_TASK_LENGTH.' characters.'
            );
        }

        $workerId = $this->resolveWorkerId($arguments, $task);

        $response = 'Error: No suitable Digital Worker found for this task. '
            .'Use worker_list to see available workers, then specify a worker_id explicitly.';

        if ($workerId === null) {
            return $response;
        }

        try {
            $result = $this->dispatcher->dispatchForCurrentUser($workerId, $task);
            $response = $this->formatDispatchResult($result);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            $response = 'Error: '.$e->getMessage();
        } catch (\Throwable $e) {
            $response = 'Error dispatching task: '.$e->getMessage();
        }

        return $response;
    }

    /**
     * Resolve the target worker ID from explicit argument or auto-matching.
     */
    private function resolveWorkerId(array $arguments, string $task): ?int
    {
        $workerId = $arguments['worker_id'] ?? null;

        if (is_int($workerId)) {
            return $workerId;
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
