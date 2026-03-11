<?php

use App\Modules\Core\AI\Services\LaraCapabilityMatcher;
use App\Modules\Core\AI\Services\LaraTaskDispatcher;
use App\Modules\Core\AI\Tools\DelegateTaskTool;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, AssertsToolBehavior::class);

const DISPATCH_SUCCESS = 'dispatched successfully';
const ANALYZE_SALES_DATA = 'Analyze sales data';
const REPORT_TIMESTAMP = '2026-03-08T12:00:00+00:00';
const GENERATE_MONTHLY_REPORT = 'Generate monthly report';
const REPORT_GENERATOR = 'Report Generator';

beforeEach(function () {
    $this->dispatcher = Mockery::mock(LaraTaskDispatcher::class);
    $this->matcher = Mockery::mock(LaraCapabilityMatcher::class);
    $this->tool = new DelegateTaskTool($this->dispatcher, $this->matcher);
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'delegate_task',
            'ai.tool_delegate.execute',
            ['task', 'worker_id'],
            ['task'],
        );
    });
});

describe('input validation', function () {
    it('rejects missing or empty task', function () {
        $this->assertRejectsMissingAndEmptyStringArgument('task');
    });

    it('rejects task exceeding max length', function () {
        $result = $this->tool->execute(['task' => str_repeat('x', 5001)]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('maximum length');
    });

    it('accepts task at max length', function () {
        $this->dispatcher->shouldReceive('dispatchForCurrentUser')
            ->once()
            ->andReturn([
                'dispatch_id' => 'dw_dispatch_abc123',
                'status' => 'queued',
                'employee_id' => 1,
                'employee_name' => 'Worker',
                'task' => str_repeat('x', 5000),
                'acting_for_user_id' => 10,
                'created_at' => '2026-03-08T00:00:00+00:00',
            ]);

        $result = $this->tool->execute([
            'task' => str_repeat('x', 5000),
            'worker_id' => 1,
        ]);

        expect((string) $result)->toContain(DISPATCH_SUCCESS);
    });
});

describe('dispatch with explicit worker_id', function () {
    it('dispatches to specified worker', function () {
        $this->dispatcher->shouldReceive('dispatchForCurrentUser')
            ->once()
            ->with(42, ANALYZE_SALES_DATA)
            ->andReturn([
                'dispatch_id' => 'dw_dispatch_test123',
                'status' => 'queued',
                'employee_id' => 42,
                'employee_name' => 'Data Analyst',
                'task' => ANALYZE_SALES_DATA,
                'acting_for_user_id' => 10,
                'created_at' => REPORT_TIMESTAMP,
            ]);

        $result = $this->tool->execute(['task' => ANALYZE_SALES_DATA, 'worker_id' => 42]);

        expect((string) $result)->toContain(DISPATCH_SUCCESS)
            ->and((string) $result)->toContain('dw_dispatch_test123')
            ->and((string) $result)->toContain('Data Analyst')
            ->and((string) $result)->toContain('ID: 42')
            ->and((string) $result)->toContain(ANALYZE_SALES_DATA)
            ->and((string) $result)->toContain('delegation_status');
    });

    it('returns error when dispatcher throws authorization exception', function () {
        $this->dispatcher->shouldReceive('dispatchForCurrentUser')
            ->once()
            ->andThrow(new AuthorizationException('Unauthorized Digital Worker dispatch target.'));

        $result = $this->tool->execute(['task' => 'Test task', 'worker_id' => 999]);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('Unauthorized');
    });
});

describe('dispatch with auto-matching', function () {
    it('auto-matches best worker when no worker_id given', function () {
        $this->matcher->shouldReceive('matchBestForTask')
            ->once()
            ->with(GENERATE_MONTHLY_REPORT)
            ->andReturn([
                'employee_id' => 7,
                'name' => REPORT_GENERATOR,
                'capability_summary' => 'Generates reports and summaries',
                'match_score' => 3,
            ]);

        $this->dispatcher->shouldReceive('dispatchForCurrentUser')
            ->once()
            ->with(7, GENERATE_MONTHLY_REPORT)
            ->andReturn([
                'dispatch_id' => 'dw_dispatch_auto456',
                'status' => 'queued',
                'employee_id' => 7,
                'employee_name' => REPORT_GENERATOR,
                'task' => GENERATE_MONTHLY_REPORT,
                'acting_for_user_id' => 10,
                'created_at' => REPORT_TIMESTAMP,
            ]);

        $result = $this->tool->execute(['task' => GENERATE_MONTHLY_REPORT]);

        expect((string) $result)->toContain(DISPATCH_SUCCESS)
            ->and((string) $result)->toContain(REPORT_GENERATOR);
    });

    it('returns error when no worker matches the task', function () {
        $this->matcher->shouldReceive('matchBestForTask')
            ->once()
            ->andReturn(null);

        $result = $this->tool->execute(['task' => 'Something obscure']);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('No suitable Digital Worker');
    });
});

describe('output format', function () {
    it('includes dispatch_id in result', function () {
        $this->dispatcher->shouldReceive('dispatchForCurrentUser')
            ->once()
            ->andReturn([
                'dispatch_id' => 'dw_dispatch_xyz789',
                'status' => 'queued',
                'employee_id' => 1,
                'employee_name' => 'Worker',
                'task' => 'Do something',
                'acting_for_user_id' => 10,
                'created_at' => '2026-03-08T12:00:00+00:00',
            ]);

        $result = $this->tool->execute(['task' => 'Do something', 'worker_id' => 1]);

        expect((string) $result)->toContain('**Dispatch ID:**')
            ->and((string) $result)->toContain('**Status:**')
            ->and((string) $result)->toContain('**Assigned to:**')
            ->and((string) $result)->toContain('**Task:**')
            ->and((string) $result)->toContain('**Created:**');
    });
});
