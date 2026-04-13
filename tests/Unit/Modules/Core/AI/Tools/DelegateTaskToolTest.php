<?php

use App\Modules\Core\AI\DTO\LaraTaskDefinition;
use App\Modules\Core\AI\DTO\Orchestration\RoutingDecision;
use App\Modules\Core\AI\Enums\LaraTaskType;
use App\Modules\Core\AI\Enums\OperationStatus;
use App\Modules\Core\AI\Enums\OperationType;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\AgentExecutionContext;
use App\Modules\Core\AI\Services\LaraTaskDispatcher;
use App\Modules\Core\AI\Services\LaraTaskProfileSelector;
use App\Modules\Core\AI\Services\Orchestration\TaskRoutingService;
use App\Modules\Core\AI\Tools\DelegateTaskTool;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, AssertsToolBehavior::class);

const DELEGATE_DISPATCH_SUCCESS = 'dispatched successfully';
const DELEGATE_ANALYZE_SALES_DATA = 'Analyze sales data';
const DELEGATE_GENERATE_MONTHLY_REPORT = 'Generate monthly report';
const DELEGATE_REPORT_GENERATOR = 'Report Generator';
const DELEGATE_DATA_ANALYST = 'Data Analyst';

function makeOperationDispatch(array $overrides = []): OperationDispatch
{
    return new OperationDispatch(array_merge([
        'id' => 'op_test123abc',
        'operation_type' => OperationType::AgentTask,
        'status' => OperationStatus::Queued,
        'employee_id' => 1,
        'acting_for_user_id' => 10,
        'task' => 'Test task',
        'meta' => ['employee_name' => 'Worker', 'task_type' => 'general'],
    ], $overrides));
}

function makeDelegateAgentDecision(int $agentId, string $agentName, int $confidence = 80): RoutingDecision
{
    return RoutingDecision::agent(
        agentEmployeeId: $agentId,
        agentName: $agentName,
        confidenceScore: $confidence,
        reasons: ['Matched by test.'],
        meta: ['routing_method' => 'test'],
    );
}

beforeEach(function () {
    $this->dispatcher = Mockery::mock(LaraTaskDispatcher::class);
    $this->router = Mockery::mock(TaskRoutingService::class);
    $this->executionContext = new AgentExecutionContext;
    $this->taskProfileSelector = Mockery::mock(LaraTaskProfileSelector::class);
    $this->tool = new DelegateTaskTool($this->dispatcher, $this->router, $this->executionContext, $this->taskProfileSelector);
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'delegate_task',
            'ai.tool_delegate.execute',
            ['task', 'task_type', 'agent_id'],
            ['task', 'task_type'],
        );
    });
});

describe('input validation', function () {
    it('rejects missing or empty task', function () {
        $this->assertRejectsMissingAndEmptyStringArgument('task');
    });

    it('rejects task exceeding max length', function () {
        $result = $this->tool->execute(['task' => str_repeat('x', 5001), 'task_type' => 'general']);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('maximum length');
    });

    it('rejects task_type exceeding max length', function () {
        $result = $this->tool->execute([
            'task' => 'Do work',
            'task_type' => str_repeat('t', 61),
        ]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('task_type');
    });

    it('accepts task at max length', function () {
        $dispatch = makeOperationDispatch([
            'task' => str_repeat('x', 5000),
        ]);

        $this->router->shouldReceive('route')
            ->once()
            ->andReturn(makeDelegateAgentDecision(1, 'Worker'));

        $this->dispatcher->shouldReceive('dispatchForCurrentUser')
            ->once()
            ->andReturn($dispatch);

        $result = $this->tool->execute([
            'task' => str_repeat('x', 5000),
            'task_type' => 'general',
            'agent_id' => 1,
        ]);

        expect((string) $result)->toContain(DELEGATE_DISPATCH_SUCCESS);
    });
});

describe('dispatch with explicit agent_id', function () {
    it('dispatches to specified agent via routing', function () {
        $dispatch = makeOperationDispatch([
            'id' => 'op_test123abc',
            'employee_id' => 42,
            'task' => DELEGATE_ANALYZE_SALES_DATA,
            'meta' => ['employee_name' => DELEGATE_DATA_ANALYST, 'task_type' => 'general'],
        ]);

        $this->router->shouldReceive('route')
            ->once()
            ->withArgs(function ($request) {
                return $request->preferredAgentId === 42
                    && $request->task === DELEGATE_ANALYZE_SALES_DATA;
            })
            ->andReturn(makeDelegateAgentDecision(42, DELEGATE_DATA_ANALYST, 100));

        $this->dispatcher->shouldReceive('dispatchForCurrentUser')
            ->once()
            ->with(42, 'general', DELEGATE_ANALYZE_SALES_DATA)
            ->andReturn($dispatch);

        $result = $this->tool->execute(['task' => DELEGATE_ANALYZE_SALES_DATA, 'task_type' => 'general', 'agent_id' => 42]);

        expect((string) $result)->toContain(DELEGATE_DISPATCH_SUCCESS)
            ->and((string) $result)->toContain('op_test123abc')
            ->and((string) $result)->toContain(DELEGATE_DATA_ANALYST)
            ->and((string) $result)->toContain('ID: 42')
            ->and((string) $result)->toContain(DELEGATE_ANALYZE_SALES_DATA)
            ->and((string) $result)->toContain('delegation_status');
    });

    it('returns error when dispatcher throws authorization exception', function () {
        $this->router->shouldReceive('route')
            ->once()
            ->andReturn(makeDelegateAgentDecision(999, 'Unknown'));

        $this->dispatcher->shouldReceive('dispatchForCurrentUser')
            ->once()
            ->andThrow(new AuthorizationException('Unauthorized Agent dispatch target.'));

        $result = $this->tool->execute(['task' => 'Test task', 'task_type' => 'general', 'agent_id' => 999]);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('Unauthorized');
    });
});

describe('dispatch with auto-matching', function () {
    it('auto-routes best agent when no agent_id given', function () {
        $this->router->shouldReceive('route')
            ->once()
            ->withArgs(function ($request) {
                return $request->preferredAgentId === null
                    && $request->task === DELEGATE_GENERATE_MONTHLY_REPORT
                    && $request->taskType === 'generate_report';
            })
            ->andReturn(makeDelegateAgentDecision(7, DELEGATE_REPORT_GENERATOR));

        $dispatch = makeOperationDispatch([
            'id' => 'op_auto456xyz',
            'employee_id' => 7,
            'task' => DELEGATE_GENERATE_MONTHLY_REPORT,
            'meta' => ['employee_name' => DELEGATE_REPORT_GENERATOR, 'task_type' => 'generate_report'],
        ]);

        $this->dispatcher->shouldReceive('dispatchForCurrentUser')
            ->once()
            ->with(7, 'generate_report', DELEGATE_GENERATE_MONTHLY_REPORT)
            ->andReturn($dispatch);

        $result = $this->tool->execute(['task' => DELEGATE_GENERATE_MONTHLY_REPORT, 'task_type' => 'generate_report']);

        expect((string) $result)->toContain(DELEGATE_DISPATCH_SUCCESS)
            ->and((string) $result)->toContain(DELEGATE_REPORT_GENERATOR);
    });

    it('returns error when routing falls back to local', function () {
        $this->router->shouldReceive('route')
            ->once()
            ->andReturn(RoutingDecision::local(['No agent matched.']));
        $this->taskProfileSelector->shouldReceive('select')
            ->once()
            ->with('Something obscure', 'general')
            ->andReturnNull();

        $result = $this->tool->execute(['task' => 'Something obscure', 'task_type' => 'general']);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('No suitable Agent or Lara task profile');
    });

    it('includes routing reasons in error message', function () {
        $this->router->shouldReceive('route')
            ->once()
            ->andReturn(RoutingDecision::local(['No delegable agents available for the current user.']));
        $this->taskProfileSelector->shouldReceive('select')
            ->once()
            ->with('Impossible task', 'general')
            ->andReturnNull();

        $result = $this->tool->execute(['task' => 'Impossible task', 'task_type' => 'general']);

        expect((string) $result)->toContain('No suitable Agent or Lara task profile')
            ->and((string) $result)->toContain('No delegable agents');
    });

    it('falls back to a Lara research task profile when no agent matches', function () {
        $researchTask = new LaraTaskDefinition(
            key: 'research',
            label: 'Research',
            type: LaraTaskType::Agentic,
            description: 'Research tasks',
            workloadDescription: 'Research workload',
            runtimeReady: true,
        );

        $this->router->shouldReceive('route')
            ->once()
            ->andReturn(RoutingDecision::local(['No delegable agents available for the current user.']));
        $this->taskProfileSelector->shouldReceive('select')
            ->once()
            ->with('Investigate the latest OpenAI docs changes', 'research')
            ->andReturn([
                'definition' => $researchTask,
                'reasons' => ['Explicit task type matched Research.'],
                'confidence' => 120,
            ]);

        $dispatch = makeOperationDispatch([
            'employee_id' => 1,
            'task' => 'Investigate the latest OpenAI docs changes',
            'meta' => [
                'employee_name' => 'Lara',
                'task_type' => 'research',
                'task_profile' => 'research',
                'task_profile_label' => 'Research',
            ],
        ]);

        $this->dispatcher->shouldReceive('dispatchTaskProfileForCurrentUser')
            ->once()
            ->with('research', 'Investigate the latest OpenAI docs changes')
            ->andReturn($dispatch);

        $result = $this->tool->execute([
            'task' => 'Investigate the latest OpenAI docs changes',
            'task_type' => 'research',
        ]);

        expect((string) $result)->toContain(DELEGATE_DISPATCH_SUCCESS)
            ->and((string) $result)->toContain('Lara Research')
            ->and((string) $result)->toContain('Explicit task type matched Research.');
    });
});

describe('execution context', function () {
    it('uses agent execution context employee ID when active', function () {
        $this->executionContext->set(
            employeeId: 5,
            actingForUserId: 10,
            entityType: null,
            entityId: null,
            dispatchId: 'op_test',
        );

        $this->router->shouldReceive('route')
            ->once()
            ->withArgs(function ($request) {
                return $request->requestingEmployeeId === 5;
            })
            ->andReturn(makeDelegateAgentDecision(7, 'Target Agent'));

        $this->dispatcher->shouldReceive('dispatchForCurrentUser')
            ->once()
            ->andReturn(makeOperationDispatch());

        $this->tool->execute(['task' => 'From queued job', 'task_type' => 'general']);

        $this->executionContext->clear();
    });

    it('falls back to Lara ID when no execution context active', function () {
        $this->router->shouldReceive('route')
            ->once()
            ->withArgs(function ($request) {
                return $request->requestingEmployeeId === 1; // Employee::LARA_ID
            })
            ->andReturn(makeDelegateAgentDecision(7, 'Target Agent'));

        $this->dispatcher->shouldReceive('dispatchForCurrentUser')
            ->once()
            ->andReturn(makeOperationDispatch());

        $this->tool->execute(['task' => 'From chat', 'task_type' => 'general']);
    });
});

describe('output format', function () {
    it('includes dispatch_id in result', function () {
        $dispatch = makeOperationDispatch([
            'id' => 'op_xyz789abc',
            'task' => 'Do something',
        ]);

        $this->router->shouldReceive('route')
            ->once()
            ->andReturn(makeDelegateAgentDecision(1, 'Worker'));

        $this->dispatcher->shouldReceive('dispatchForCurrentUser')
            ->once()
            ->andReturn($dispatch);

        $result = $this->tool->execute(['task' => 'Do something', 'task_type' => 'general', 'agent_id' => 1]);

        expect((string) $result)->toContain('**Dispatch ID:**')
            ->and((string) $result)->toContain('**Status:**')
            ->and((string) $result)->toContain('**Assigned to:**')
            ->and((string) $result)->toContain('**Task:**')
            ->and((string) $result)->toContain('**Created:**');
    });

    it('falls back to agent id when dispatch meta is absent', function () {
        $dispatch = makeOperationDispatch([
            'id' => 'op_null_meta',
            'employee_id' => 9,
            'task' => 'Do something else',
            'meta' => null,
        ]);

        $this->router->shouldReceive('route')
            ->once()
            ->andReturn(makeDelegateAgentDecision(9, 'Agent Nine'));

        $this->dispatcher->shouldReceive('dispatchForCurrentUser')
            ->once()
            ->andReturn($dispatch);

        $result = $this->tool->execute(['task' => 'Do something else', 'task_type' => 'general', 'agent_id' => 9]);

        expect((string) $result)->toContain('Agent #9');
    });
});
