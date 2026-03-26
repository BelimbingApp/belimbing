<?php

use App\Modules\Core\AI\Models\AgentTaskDispatch;
use App\Modules\Core\AI\Tools\DelegationStatusTool;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

const DELEGATION_STATUS_TOOL_TEST_TASK = 'Test task';

uses(TestCase::class, LazilyRefreshDatabase::class, AssertsToolBehavior::class);

beforeEach(function () {
    $this->tool = new DelegationStatusTool;
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'delegation_status',
            'ai.tool_delegation_status.execute',
            ['dispatch_id'],
            ['dispatch_id'],
        );
    });
});

describe('input validation', function () {
    it('rejects missing or empty dispatch_id', function () {
        $this->assertRejectsMissingAndEmptyStringArgument('dispatch_id');
    });

    it('rejects invalid dispatch_id format', function () {
        $result = (string) $this->tool->execute(['dispatch_id' => 'invalid_id']);
        expect($result)->toContain('Error')
            ->and($result)->toContain('Invalid dispatch_id format');
    });

    it('rejects dispatch_id with prefix only', function () {
        $result = (string) $this->tool->execute(['dispatch_id' => 'agent_dispatch_']);
        expect($result)->toContain('Error')
            ->and($result)->toContain('Invalid dispatch_id format');
    });
});

describe('status lookup', function () {
    it('returns not_found for unknown dispatch_id', function () {
        $result = (string) $this->tool->execute(['dispatch_id' => 'agent_dispatch_unknown123']);
        $data = json_decode($result, true);

        expect($data)->not->toBeNull()
            ->and($data['dispatch_id'])->toBe('agent_dispatch_unknown123')
            ->and($data['status'])->toBe('not_found')
            ->and($data)->toHaveKey('checked_at');
    });

    it('returns status for a persisted dispatch', function () {
        AgentTaskDispatch::unguarded(fn () => AgentTaskDispatch::query()->create([
            'id' => 'agent_dispatch_abc123xyz',
            'employee_id' => 1,
            'acting_for_user_id' => 1,
            'task_type' => 'general',
            'task' => DELEGATION_STATUS_TOOL_TEST_TASK,
            'status' => 'queued',
            'meta' => ['employee_name' => 'Kodi'],
        ]));

        $result = (string) $this->tool->execute(['dispatch_id' => 'agent_dispatch_abc123xyz']);
        $data = json_decode($result, true);

        expect($data)->not->toBeNull()
            ->and($data['dispatch_id'])->toBe('agent_dispatch_abc123xyz')
            ->and($data['status'])->toBe('queued')
            ->and($data['employee_id'])->toBe(1)
            ->and($data['task'])->toBe(DELEGATION_STATUS_TOOL_TEST_TASK)
            ->and($data)->toHaveKey('checked_at');
    });

    it('returns a null employee name when dispatch meta is absent', function () {
        AgentTaskDispatch::unguarded(fn () => AgentTaskDispatch::query()->create([
            'id' => 'agent_dispatch_no_meta',
            'employee_id' => 1,
            'acting_for_user_id' => 1,
            'task_type' => 'general',
            'task' => DELEGATION_STATUS_TOOL_TEST_TASK,
            'status' => 'queued',
            'meta' => null,
        ]));

        $result = (string) $this->tool->execute(['dispatch_id' => 'agent_dispatch_no_meta']);
        $data = json_decode($result, true);

        expect($data)->not->toBeNull()
            ->and($data['employee_name'])->toBeNull();
    });

    it('includes result_summary for succeeded dispatch', function () {
        AgentTaskDispatch::unguarded(fn () => AgentTaskDispatch::query()->create([
            'id' => 'agent_dispatch_success',
            'employee_id' => 1,
            'acting_for_user_id' => 1,
            'task_type' => 'general',
            'task' => 'Build feature',
            'status' => 'succeeded',
            'result_summary' => 'Feature built successfully.',
            'meta' => ['employee_name' => 'Kodi'],
        ]));

        $result = (string) $this->tool->execute(['dispatch_id' => 'agent_dispatch_success']);
        $data = json_decode($result, true);

        expect($data['status'])->toBe('succeeded')
            ->and($data['result_summary'])->toBe('Feature built successfully.');
    });

    it('includes error_message for failed dispatch', function () {
        AgentTaskDispatch::unguarded(fn () => AgentTaskDispatch::query()->create([
            'id' => 'agent_dispatch_fail',
            'employee_id' => 1,
            'acting_for_user_id' => 1,
            'task_type' => 'general',
            'task' => 'Broken task',
            'status' => 'failed',
            'error_message' => 'LLM timeout.',
            'meta' => [],
        ]));

        $result = (string) $this->tool->execute(['dispatch_id' => 'agent_dispatch_fail']);
        $data = json_decode($result, true);

        expect($data['status'])->toBe('failed')
            ->and($data['error_message'])->toBe('LLM timeout.');
    });

    it('returns valid pretty-printed JSON', function () {
        $result = (string) $this->tool->execute(['dispatch_id' => 'agent_dispatch_test123']);

        expect(json_decode($result, true))->not->toBeNull()
            ->and($result)->toContain("\n");
    });
});
