<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\DTO\ControlPlane\RunInspection;
use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\ControlPlane\RunInspectionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

const RIS_EMPLOYEE_ID = 1;
const RIS_SESSION_ID = 'sess_test_001';
const RIS_RUN_ID = 'run_test_001';
const RIS_RUN_ID_2 = 'run_test_002';
const RIS_DISPATCH_ID = 'op_test_001';
const RIS_PROVIDER = 'anthropic';
const RIS_MODEL = 'claude-opus-4';

function risCreateAiRun(string $runId, array $overrides = []): AiRun
{
    return AiRun::unguarded(fn () => AiRun::query()->create(array_merge([
        'id' => $runId,
        'employee_id' => RIS_EMPLOYEE_ID,
        'session_id' => RIS_SESSION_ID,
        'source' => 'chat',
        'execution_mode' => 'streaming',
        'status' => AiRunStatus::Succeeded,
        'provider_name' => RIS_PROVIDER,
        'model' => RIS_MODEL,
        'latency_ms' => 500,
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'started_at' => now(),
        'finished_at' => now(),
    ], $overrides)));
}

function makeRunInspectionService(): RunInspectionService
{
    return new RunInspectionService;
}

// ------------------------------------------------------------------
// inspectRun
// ------------------------------------------------------------------

describe('inspectRun', function () {
    it('returns null when run ID is not found', function () {
        $service = makeRunInspectionService();
        $result = $service->inspectRun('nonexistent_run');

        expect($result)->toBeNull();
    });

    it('returns a RunInspection for a known run', function () {
        risCreateAiRun(RIS_RUN_ID);

        $service = makeRunInspectionService();
        $result = $service->inspectRun(RIS_RUN_ID);

        expect($result)->toBeInstanceOf(RunInspection::class)
            ->and($result->runId)->toBe(RIS_RUN_ID)
            ->and($result->employeeId)->toBe(RIS_EMPLOYEE_ID)
            ->and($result->sessionId)->toBe(RIS_SESSION_ID)
            ->and($result->provider)->toBe(RIS_PROVIDER)
            ->and($result->model)->toBe(RIS_MODEL)
            ->and($result->outcome)->toBe('success');
    });

    it('returns error outcome for a failed run', function () {
        risCreateAiRun(RIS_RUN_ID, [
            'status' => AiRunStatus::Failed,
            'error_type' => 'runtime',
            'error_message' => 'Connection refused',
        ]);

        $service = makeRunInspectionService();
        $result = $service->inspectRun(RIS_RUN_ID);

        expect($result->outcome)->toBe('error')
            ->and($result->errorType)->toBe('runtime')
            ->and($result->errorMessage)->toBe('Connection refused');
    });
});

// ------------------------------------------------------------------
// inspectSession
// ------------------------------------------------------------------

describe('inspectSession', function () {
    it('returns empty array when session has no runs', function () {
        $service = makeRunInspectionService();
        $result = $service->inspectSession(RIS_EMPLOYEE_ID, RIS_SESSION_ID);

        expect($result)->toBe([]);
    });

    it('returns inspections sorted by started_at for multi-run sessions', function () {
        risCreateAiRun(RIS_RUN_ID, ['started_at' => now()->subMinutes(5)]);
        risCreateAiRun(RIS_RUN_ID_2, ['started_at' => now()]);

        $service = makeRunInspectionService();
        $result = $service->inspectSession(RIS_EMPLOYEE_ID, RIS_SESSION_ID);

        expect($result)->toHaveCount(2)
            ->and($result[0]->runId)->toBe(RIS_RUN_ID)
            ->and($result[1]->runId)->toBe(RIS_RUN_ID_2);
    });
});

// ------------------------------------------------------------------
// inspectDispatchRun
// ------------------------------------------------------------------

describe('inspectDispatchRun', function () {
    it('returns empty array when dispatch has no linked runs', function () {
        $service = makeRunInspectionService();
        $result = $service->inspectDispatchRun('op_nonexistent');

        expect($result)->toBe([]);
    });

    it('returns inspections for runs linked to a dispatch', function () {
        OperationDispatch::unguarded(fn () => OperationDispatch::query()->create([
            'id' => RIS_DISPATCH_ID,
            'operation_type' => 'agent_task',
            'employee_id' => RIS_EMPLOYEE_ID,
            'task' => 'Test task',
            'status' => 'succeeded',
            'run_id' => RIS_RUN_ID,
        ]));

        risCreateAiRun(RIS_RUN_ID, ['dispatch_id' => RIS_DISPATCH_ID]);

        $service = makeRunInspectionService();
        $result = $service->inspectDispatchRun(RIS_DISPATCH_ID);

        expect($result)->toHaveCount(1)
            ->and($result[0]->runId)->toBe(RIS_RUN_ID)
            ->and($result[0]->dispatchId)->toBe(RIS_DISPATCH_ID);
    });
});
