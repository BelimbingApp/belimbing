<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\Foundation\Contracts\CompanyScoped;
use App\Base\AI\Tools\AbstractActionTool;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolArgumentException;
use App\Base\AI\Tools\ToolResult;
use App\Modules\Core\AI\Models\ScheduleDefinition;
use App\Modules\Core\AI\Services\AgentExecutionContext;
use App\Modules\Core\AI\Services\Scheduling\ScheduleDefinitionService;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Support\Facades\Auth;

/**
 * Scheduled task management tool for Agents.
 *
 * Provides CRUD operations for schedule definitions stored in the
 * database. Each schedule defines a cron expression, target agent,
 * execution payload, and enabled state. Schedules are dispatched by
 * the SchedulePlanner via the operations ledger.
 *
 * Thin wrapper over ScheduleDefinitionService — the tool owns
 * argument validation and response formatting; the service owns
 * persistence and business rules.
 *
 * Gated by `ai.tool_schedule.execute` authz capability.
 */
class ScheduleTaskTool extends AbstractActionTool
{
    use ProvidesToolMetadata;

    /**
     * Valid actions for schedule management.
     *
     * @var list<string>
     */
    private const ACTIONS = [
        'list',
        'add',
        'update',
        'remove',
        'status',
    ];

    private readonly ScheduleTaskToolSupport $support;

    public function __construct(
        private readonly ScheduleDefinitionService $scheduleService,
        private readonly AgentExecutionContext $executionContext,
    ) {
        $this->support = new ScheduleTaskToolSupport;
    }

    public function name(): string
    {
        return 'schedule_task';
    }

    public function description(): string
    {
        return 'Manage scheduled tasks for Agents. '
            .'Supports listing, adding, updating, removing, and checking status of '
            .'scheduled tasks. Each task defines a cron expression, target agent, '
            .'description, and enabled state. Tasks execute via the schedule planner '
            .'and operations dispatch ledger.';
    }

    public function category(): ToolCategory
    {
        return ToolCategory::AUTOMATION;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::HIGH_IMPACT;
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_schedule.execute';
    }

    protected function toolMetadata(): array
    {
        return [
            'displayName' => 'Schedule Task',
            'summary' => 'Create and manage scheduled tasks for Agents.',
            'explanation' => 'CRUD operations for schedule definitions stored in the database. '
                .'Each schedule defines a cron expression, target agent, and execution payload. '
                .'Schedules fire through the operations dispatch ledger.',
            'setupRequirements' => [
                'Laravel scheduler running',
                'blb:ai:schedules:tick registered in scheduler',
            ],
            'testExamples' => [
                [
                    'label' => 'List schedules',
                    'input' => ['action' => 'list'],
                ],
            ],
            'healthChecks' => [
                'Scheduler active',
            ],
            'limits' => [
                'Company-scoped schedule isolation',
            ],
        ];
    }

    protected function actions(): array
    {
        return self::ACTIONS;
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->integer('task_id', 'The schedule definition ID. Required for update, remove, and status actions.')
            ->string('description', 'Description of what the scheduled task should do. Required for add, optional for update.')
            ->string('execution_payload', 'The task instruction or command that will be executed. Required for add, optional for update. For agent-targeted schedules, this is the task prompt sent to the agent.')
            ->string('cron_expression', 'Standard 5-field cron expression (minute hour day month weekday). Required for add, optional for update. Example: "0 9 * * 1" for every Monday at 9am.')
            ->integer('agent_id', 'Employee ID of the target Agent to execute the task. Optional; use agent_list to discover available agents.')
            ->string('timezone', 'IANA timezone for the cron expression (default: UTC). Example: "Asia/Kuala_Lumpur".')
            ->boolean('enabled', 'Whether the scheduled task is enabled. Defaults to true for add. Optional for update.');
    }

    protected function handleAction(string $action, array $arguments): ToolResult
    {
        return match ($action) {
            'list' => $this->handleList(),
            'add' => $this->handleAdd($arguments),
            'update' => $this->handleUpdate($arguments),
            'remove' => $this->handleRemove($arguments),
            'status' => $this->handleStatus($arguments),
        };
    }

    /**
     * Handle the "list" action.
     *
     * Returns all schedule definitions for the current company.
     */
    private function handleList(): ToolResult
    {
        $companyId = $this->resolveCompanyId();

        if ($companyId === null) {
            return ToolResult::error(
                'Cannot determine company context for schedule listing.',
                'no_company_context',
            );
        }

        $schedules = $this->scheduleService->list($companyId);

        $tasks = $schedules->map(fn (ScheduleDefinition $s) => $this->support->formatSchedule($s))->all();

        return $this->support->encodeResponse([
            'tasks' => $tasks,
            'total' => count($tasks),
        ]);
    }

    /**
     * Handle the "add" action.
     *
     * Creates a new schedule definition through the service.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleAdd(array $arguments): ToolResult
    {
        $description = $this->requireString($arguments, 'description');
        $cronExpression = $this->requireString($arguments, 'cron_expression');
        $executionPayload = $this->requireString($arguments, 'execution_payload');

        $companyId = $this->resolveCompanyId();

        if ($companyId === null) {
            return ToolResult::error(
                'Cannot determine company context for schedule creation.',
                'no_company_context',
            );
        }

        $agentId = $arguments['agent_id'] ?? null;
        $enabled = $this->optionalBool($arguments, 'enabled', true);
        $timezone = $this->optionalString($arguments, 'timezone') ?? 'UTC';

        try {
            $schedule = $this->scheduleService->create($companyId, [
                'description' => $description,
                'execution_payload' => $executionPayload,
                'cron_expression' => $cronExpression,
                'employee_id' => is_int($agentId) ? $agentId : null,
                'timezone' => $timezone,
                'is_enabled' => $enabled,
                'created_by_user_id' => $this->resolveUserId(),
            ]);
        } catch (\InvalidArgumentException $e) {
            throw new ToolArgumentException($e->getMessage());
        }

        return $this->support->encodeResponse([
            'status' => 'created',
            ...$this->support->formatSchedule($schedule),
        ]);
    }

    /**
     * Handle the "update" action.
     *
     * Updates an existing schedule definition through the service.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleUpdate(array $arguments): ToolResult
    {
        $scheduleId = $this->requireScheduleId($arguments);

        $companyId = $this->resolveCompanyId();

        if ($companyId === null) {
            return ToolResult::error(
                'Cannot determine company context for schedule update.',
                'no_company_context',
            );
        }

        $updateData = $this->buildUpdateData($arguments);

        if ($updateData === []) {
            throw new ToolArgumentException(
                'No update fields provided. Provide at least one of: '
                .'description, execution_payload, cron_expression, agent_id, timezone, enabled.',
            );
        }

        try {
            $schedule = $this->scheduleService->update($scheduleId, $companyId, $updateData);
        } catch (\InvalidArgumentException $e) {
            throw new ToolArgumentException($e->getMessage());
        }

        if ($schedule === null) {
            return $this->support->scheduleNotFoundResult($scheduleId);
        }

        return $this->support->encodeResponse([
            'status' => 'updated',
            ...$this->support->formatSchedule($schedule),
        ]);
    }

    /**
     * Handle the "remove" action.
     *
     * Deletes a schedule definition through the service.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleRemove(array $arguments): ToolResult
    {
        $scheduleId = $this->requireScheduleId($arguments);

        $companyId = $this->resolveCompanyId();

        if ($companyId === null) {
            return ToolResult::error(
                'Cannot determine company context for schedule removal.',
                'no_company_context',
            );
        }

        $removed = $this->scheduleService->remove($scheduleId, $companyId);

        if (! $removed) {
            return $this->support->scheduleNotFoundResult($scheduleId);
        }

        return $this->support->encodeResponse([
            'task_id' => $scheduleId,
            'status' => 'removed',
            'removed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Handle the "status" action.
     *
     * Retrieves a single schedule definition with its current state.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleStatus(array $arguments): ToolResult
    {
        $scheduleId = $this->requireScheduleId($arguments);

        $companyId = $this->resolveCompanyId();

        if ($companyId === null) {
            return ToolResult::error(
                'Cannot determine company context for schedule status.',
                'no_company_context',
            );
        }

        $schedule = $this->scheduleService->find($scheduleId, $companyId);

        if ($schedule === null) {
            return $this->support->scheduleNotFoundResult($scheduleId);
        }

        return $this->support->encodeResponse([
            'status' => 'found',
            ...$this->support->formatSchedule($schedule),
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Extract and validate the task_id argument as an integer schedule ID.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     *
     * @throws ToolArgumentException If task_id is missing or invalid
     */
    private function requireScheduleId(array $arguments): int
    {
        $taskId = $arguments['task_id'] ?? null;

        if ($taskId === null) {
            throw new ToolArgumentException(
                'Missing required argument: task_id. '
                .'Provide the schedule definition ID as returned by the add or list action.',
            );
        }

        if (! is_int($taskId) && ! (is_string($taskId) && ctype_digit($taskId))) {
            throw new ToolArgumentException(
                'Invalid task_id: expected an integer schedule ID as returned by the add or list action.',
            );
        }

        return (int) $taskId;
    }

    /**
     * Build the update data array from optional arguments.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     * @return array<string, mixed>
     */
    private function buildUpdateData(array $arguments): array
    {
        $data = [];

        $description = $this->optionalString($arguments, 'description');

        if ($description !== null) {
            $data['description'] = $description;
        }

        $executionPayload = $this->optionalString($arguments, 'execution_payload');

        if ($executionPayload !== null) {
            $data['execution_payload'] = $executionPayload;
        }

        $cronExpression = $this->optionalString($arguments, 'cron_expression');

        if ($cronExpression !== null) {
            $data['cron_expression'] = $cronExpression;
        }

        $agentId = $arguments['agent_id'] ?? null;

        if (is_int($agentId)) {
            $data['employee_id'] = $agentId;
        }

        $timezone = $this->optionalString($arguments, 'timezone');

        if ($timezone !== null) {
            $data['timezone'] = $timezone;
        }

        if (array_key_exists('enabled', $arguments)) {
            $data['is_enabled'] = (bool) $arguments['enabled'];
        }

        return $data;
    }

    /**
     * Resolve the company ID from agent execution context or auth.
     */
    private function resolveCompanyId(): ?int
    {
        if ($this->executionContext->active()) {
            $employee = Employee::query()->find($this->executionContext->employeeId());

            if ($employee !== null) {
                return $employee->company_id;
            }
        }

        $user = Auth::user();

        return $user instanceof CompanyScoped
            ? $user->getCompanyId()
            : null;
    }

    /**
     * Resolve the acting user ID from agent execution context or auth.
     */
    private function resolveUserId(): ?int
    {
        if ($this->executionContext->active()) {
            return $this->executionContext->actingForUserId();
        }

        return Auth::id();
    }
}

final class ScheduleTaskToolSupport
{
    private const SCHEDULE_NOT_FOUND_PREFIX = 'Schedule #';

    private const SCHEDULE_NOT_FOUND_SUFFIX = ' not found or does not belong to your company.';

    /**
     * Format a ScheduleDefinition as a tool-friendly array.
     *
     * @return array<string, mixed>
     */
    public function formatSchedule(ScheduleDefinition $schedule): array
    {
        return [
            'task_id' => $schedule->id,
            'description' => $schedule->description,
            'execution_payload' => $schedule->execution_payload,
            'cron_expression' => $schedule->cron_expression,
            'timezone' => $schedule->timezone,
            'agent_id' => $schedule->employee_id,
            'enabled' => $schedule->is_enabled,
            'concurrency_policy' => $schedule->concurrency_policy,
            'last_fired_at' => $schedule->last_fired_at?->toIso8601String(),
            'next_due_at' => $schedule->next_due_at?->toIso8601String(),
            'created_at' => $schedule->created_at?->toIso8601String(),
            'updated_at' => $schedule->updated_at?->toIso8601String(),
        ];
    }

    public function scheduleNotFoundResult(int $scheduleId): ToolResult
    {
        return ToolResult::error(
            self::SCHEDULE_NOT_FOUND_PREFIX.$scheduleId.self::SCHEDULE_NOT_FOUND_SUFFIX,
            'not_found',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function encodeResponse(array $payload): ToolResult
    {
        return ToolResult::success(
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}
