<?php

use App\Modules\Core\AI\Enums\OperationStatus;
use App\Modules\Core\AI\Enums\OperationType;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\OperationsDispatchService;
use App\Modules\Core\AI\Tools\DelegationStatusTool;
use Illuminate\Database\Eloquent\Collection;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, AssertsToolBehavior::class);

const DELEGATION_STATUS_TASK = 'Test task';
const DELEGATION_STATUS_OP_ID = 'op_abc123xyz';
const DELEGATION_STATUS_KODI = 'Kodi';

function makeDispatchServiceMock(): OperationsDispatchService
{
    return Mockery::mock(OperationsDispatchService::class);
}

function makeOperationDispatchStub(array $overrides = []): OperationDispatch
{
    $defaults = [
        'id' => DELEGATION_STATUS_OP_ID,
        'operation_type' => OperationType::AgentTask,
        'employee_id' => 1,
        'acting_for_user_id' => 1,
        'task' => DELEGATION_STATUS_TASK,
        'status' => OperationStatus::Queued,
        'meta' => ['employee_name' => DELEGATION_STATUS_KODI],
    ];

    return new OperationDispatch(array_merge($defaults, $overrides));
}

beforeEach(function () {
    $this->service = makeDispatchServiceMock();
    $this->tool = new DelegationStatusTool($this->service);
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'delegation_status',
            'ai.tool_delegation_status.execute',
            ['action', 'dispatch_id', 'type', 'status', 'limit'],
            ['action'],
        );
    });
});

describe('input validation', function () {
    it('rejects missing action', function () {
        $this->assertToolError([]);
    });

    it('rejects invalid action', function () {
        $this->assertToolError(['action' => 'bogus'], 'must be one of');
    });
});

describe('get action', function () {
    it('rejects missing or empty dispatch_id', function () {
        $this->assertRejectsMissingAndEmptyStringArgument(
            'dispatch_id',
            ['action' => 'get'],
        );
    });

    it('rejects invalid dispatch_id format', function () {
        $result = (string) $this->tool->execute(['action' => 'get', 'dispatch_id' => 'invalid_id']);
        expect($result)->toContain('Error')
            ->and($result)->toContain('Invalid dispatch_id format');
    });

    it('rejects dispatch_id with prefix only', function () {
        $result = (string) $this->tool->execute(['action' => 'get', 'dispatch_id' => 'op_']);
        expect($result)->toContain('Error')
            ->and($result)->toContain('Invalid dispatch_id format');
    });

    it('returns not_found for unknown dispatch_id', function () {
        $this->service->shouldReceive('find')
            ->with('op_unknown123')
            ->andReturn(null);

        $result = (string) $this->tool->execute(['action' => 'get', 'dispatch_id' => 'op_unknown123']);
        $data = json_decode($result, true);

        expect($data)->not->toBeNull()
            ->and($data['dispatch_id'])->toBe('op_unknown123')
            ->and($data['status'])->toBe('not_found')
            ->and($data)->toHaveKey('checked_at');
    });

    it('returns full status for a found dispatch', function () {
        $dispatch = makeOperationDispatchStub();
        $this->service->shouldReceive('find')
            ->with(DELEGATION_STATUS_OP_ID)
            ->andReturn($dispatch);

        $result = (string) $this->tool->execute(['action' => 'get', 'dispatch_id' => DELEGATION_STATUS_OP_ID]);
        $data = json_decode($result, true);

        expect($data)->not->toBeNull()
            ->and($data['dispatch_id'])->toBe(DELEGATION_STATUS_OP_ID)
            ->and($data['status'])->toBe('queued')
            ->and($data['employee_id'])->toBe(1)
            ->and($data['task'])->toBe(DELEGATION_STATUS_TASK)
            ->and($data['employee_name'])->toBe(DELEGATION_STATUS_KODI)
            ->and($data)->toHaveKey('checked_at');
    });

    it('returns null employee_name when dispatch meta is absent', function () {
        $dispatch = makeOperationDispatchStub(['meta' => null]);
        $this->service->shouldReceive('find')
            ->with(DELEGATION_STATUS_OP_ID)
            ->andReturn($dispatch);

        $result = (string) $this->tool->execute(['action' => 'get', 'dispatch_id' => DELEGATION_STATUS_OP_ID]);
        $data = json_decode($result, true);

        expect($data)->not->toBeNull()
            ->and($data['employee_name'])->toBeNull();
    });

    it('includes result_summary for succeeded dispatch', function () {
        $dispatch = makeOperationDispatchStub([
            'id' => 'op_success',
            'status' => OperationStatus::Succeeded,
            'result_summary' => 'Feature built successfully.',
        ]);
        $this->service->shouldReceive('find')
            ->with('op_success')
            ->andReturn($dispatch);

        $result = (string) $this->tool->execute(['action' => 'get', 'dispatch_id' => 'op_success']);
        $data = json_decode($result, true);

        expect($data['status'])->toBe('succeeded')
            ->and($data['result_summary'])->toBe('Feature built successfully.');
    });

    it('includes error_message for failed dispatch', function () {
        $dispatch = makeOperationDispatchStub([
            'id' => 'op_fail',
            'status' => OperationStatus::Failed,
            'error_message' => 'LLM timeout.',
            'meta' => [],
        ]);
        $this->service->shouldReceive('find')
            ->with('op_fail')
            ->andReturn($dispatch);

        $result = (string) $this->tool->execute(['action' => 'get', 'dispatch_id' => 'op_fail']);
        $data = json_decode($result, true);

        expect($data['status'])->toBe('failed')
            ->and($data['error_message'])->toBe('LLM timeout.');
    });

    it('returns valid pretty-printed JSON', function () {
        $this->service->shouldReceive('find')
            ->with('op_test123')
            ->andReturn(null);

        $result = (string) $this->tool->execute(['action' => 'get', 'dispatch_id' => 'op_test123']);

        expect(json_decode($result, true))->not->toBeNull()
            ->and($result)->toContain("\n");
    });
});

describe('list action', function () {
    it('returns empty operations list', function () {
        $this->service->shouldReceive('recent')
            ->with(null, null, 10)
            ->andReturn(new Collection);

        $result = (string) $this->tool->execute(['action' => 'list']);
        $data = json_decode($result, true);

        expect($data)->not->toBeNull()
            ->and($data['operations'])->toBe([])
            ->and($data['total'])->toBe(0)
            ->and($data)->toHaveKey('checked_at');
    });

    it('returns operations with type filter', function () {
        $this->service->shouldReceive('recent')
            ->with(OperationType::AgentTask, null, 10)
            ->andReturn(new Collection([makeOperationDispatchStub()]));

        $result = (string) $this->tool->execute(['action' => 'list', 'type' => 'agent_task']);
        $data = json_decode($result, true);

        expect($data['total'])->toBe(1)
            ->and($data['filters']['type'])->toBe('agent_task')
            ->and($data['operations'][0]['dispatch_id'])->toBe(DELEGATION_STATUS_OP_ID);
    });

    it('returns operations with status filter', function () {
        $this->service->shouldReceive('recent')
            ->with(null, OperationStatus::Failed, 10)
            ->andReturn(new Collection);

        $result = (string) $this->tool->execute(['action' => 'list', 'status' => 'failed']);
        $data = json_decode($result, true);

        expect($data['total'])->toBe(0)
            ->and($data['filters']['status'])->toBe('failed');
    });

    it('respects custom limit', function () {
        $this->service->shouldReceive('recent')
            ->with(null, null, 5)
            ->andReturn(new Collection);

        $result = (string) $this->tool->execute(['action' => 'list', 'limit' => 5]);
        $data = json_decode($result, true);

        expect($data['total'])->toBe(0);
    });
});
