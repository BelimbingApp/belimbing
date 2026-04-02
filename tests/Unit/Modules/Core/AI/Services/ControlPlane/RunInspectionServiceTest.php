<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\DTO\ControlPlane\RunInspection;
use App\Modules\Core\AI\DTO\Session;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\ControlPlane\RunInspectionService;
use App\Modules\Core\AI\Services\SessionManager;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

const RIS_EMPLOYEE_ID = 1;
const RIS_SESSION_ID = 'sess_test_001';
const RIS_RUN_ID = 'run_test_001';
const RIS_RUN_ID_2 = 'run_test_002';
const RIS_DISPATCH_ID = 'op_test_001';
const RIS_TIMESTAMP = '2026-04-02T10:00:00+00:00';
const RIS_TIMESTAMP_2 = '2026-04-02T10:05:00+00:00';
const RIS_PROVIDER = 'anthropic';
const RIS_MODEL = 'claude-opus-4';

function risRunMeta(string $provider = RIS_PROVIDER, string $model = RIS_MODEL, ?string $error = null): array
{
    $meta = [
        'llm' => ['provider' => $provider, 'model' => $model],
        'latency_ms' => 500,
        'tokens' => ['prompt' => 100, 'completion' => 50],
        'tool_actions' => [],
        'fallback_attempts' => [],
        'retry_attempts' => 0,
    ];

    if ($error !== null) {
        $meta['error'] = $error;
        $meta['error_type'] = 'runtime';
    }

    return $meta;
}

function risSessionData(array $runs = []): Session
{
    return new Session(
        id: RIS_SESSION_ID,
        employeeId: RIS_EMPLOYEE_ID,
        channelType: 'web',
        title: 'Test Session',
        createdAt: new DateTimeImmutable(RIS_TIMESTAMP),
        lastActivityAt: new DateTimeImmutable(RIS_TIMESTAMP_2),
        runs: $runs,
    );
}

function makeRunInspectionService(?SessionManager $sessionManager = null): RunInspectionService
{
    return new RunInspectionService(
        $sessionManager ?? Mockery::mock(SessionManager::class),
    );
}

// ------------------------------------------------------------------
// inspectRun
// ------------------------------------------------------------------

describe('inspectRun', function () {
    it('returns null when run ID is not found in session metadata', function () {
        $sessionManager = Mockery::mock(SessionManager::class);
        $sessionManager->shouldReceive('runMetadata')
            ->with(RIS_EMPLOYEE_ID, RIS_SESSION_ID)
            ->andReturn([]);

        $service = makeRunInspectionService($sessionManager);
        $result = $service->inspectRun(RIS_EMPLOYEE_ID, RIS_SESSION_ID, RIS_RUN_ID);

        expect($result)->toBeNull();
    });

    it('returns a RunInspection for a known run', function () {
        $runData = [
            RIS_RUN_ID => [
                'meta' => risRunMeta(),
                'recorded_at' => RIS_TIMESTAMP,
            ],
        ];

        $sessionManager = Mockery::mock(SessionManager::class);
        $sessionManager->shouldReceive('runMetadata')
            ->with(RIS_EMPLOYEE_ID, RIS_SESSION_ID)
            ->andReturn($runData);

        $service = makeRunInspectionService($sessionManager);
        $result = $service->inspectRun(RIS_EMPLOYEE_ID, RIS_SESSION_ID, RIS_RUN_ID);

        expect($result)->toBeInstanceOf(RunInspection::class)
            ->and($result->runId)->toBe(RIS_RUN_ID)
            ->and($result->employeeId)->toBe(RIS_EMPLOYEE_ID)
            ->and($result->sessionId)->toBe(RIS_SESSION_ID)
            ->and($result->provider)->toBe(RIS_PROVIDER)
            ->and($result->model)->toBe(RIS_MODEL)
            ->and($result->outcome)->toBe('success');
    });

    it('links a dispatch record when one exists for the run', function () {
        $runData = [
            RIS_RUN_ID => [
                'meta' => risRunMeta(),
                'recorded_at' => RIS_TIMESTAMP,
            ],
        ];

        $sessionManager = Mockery::mock(SessionManager::class);
        $sessionManager->shouldReceive('runMetadata')
            ->with(RIS_EMPLOYEE_ID, RIS_SESSION_ID)
            ->andReturn($runData);

        OperationDispatch::unguarded(fn () => OperationDispatch::query()->create([
            'id' => RIS_DISPATCH_ID,
            'operation_type' => 'agent_task',
            'employee_id' => RIS_EMPLOYEE_ID,
            'task' => 'Test task',
            'status' => 'succeeded',
            'run_id' => RIS_RUN_ID,
        ]));

        $service = makeRunInspectionService($sessionManager);
        $result = $service->inspectRun(RIS_EMPLOYEE_ID, RIS_SESSION_ID, RIS_RUN_ID);

        expect($result->dispatchId)->toBe(RIS_DISPATCH_ID);
    });
});

// ------------------------------------------------------------------
// inspectSession
// ------------------------------------------------------------------

describe('inspectSession', function () {
    it('returns empty array when session does not exist', function () {
        $sessionManager = Mockery::mock(SessionManager::class);
        $sessionManager->shouldReceive('get')
            ->with(RIS_EMPLOYEE_ID, RIS_SESSION_ID)
            ->andReturn(null);

        $service = makeRunInspectionService($sessionManager);
        $result = $service->inspectSession(RIS_EMPLOYEE_ID, RIS_SESSION_ID);

        expect($result)->toBe([]);
    });

    it('returns empty array when session has no runs', function () {
        $session = risSessionData([]);

        $sessionManager = Mockery::mock(SessionManager::class);
        $sessionManager->shouldReceive('get')
            ->with(RIS_EMPLOYEE_ID, RIS_SESSION_ID)
            ->andReturn($session);

        $service = makeRunInspectionService($sessionManager);
        $result = $service->inspectSession(RIS_EMPLOYEE_ID, RIS_SESSION_ID);

        expect($result)->toBe([]);
    });

    it('returns inspections sorted by recordedAt for multi-run sessions', function () {
        $runs = [
            RIS_RUN_ID_2 => [
                'meta' => risRunMeta(),
                'recorded_at' => RIS_TIMESTAMP_2,
            ],
            RIS_RUN_ID => [
                'meta' => risRunMeta(),
                'recorded_at' => RIS_TIMESTAMP,
            ],
        ];

        $session = risSessionData($runs);

        $sessionManager = Mockery::mock(SessionManager::class);
        $sessionManager->shouldReceive('get')
            ->with(RIS_EMPLOYEE_ID, RIS_SESSION_ID)
            ->andReturn($session);

        $service = makeRunInspectionService($sessionManager);
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
    it('returns null when dispatch does not exist', function () {
        $service = makeRunInspectionService();
        $result = $service->inspectDispatchRun('op_nonexistent');

        expect($result)->toBeNull();
    });

    it('returns null when dispatch has no run_id', function () {
        OperationDispatch::unguarded(fn () => OperationDispatch::query()->create([
            'id' => 'op_no_run_id',
            'operation_type' => 'agent_task',
            'employee_id' => RIS_EMPLOYEE_ID,
            'task' => 'Test',
            'status' => 'succeeded',
            'run_id' => null,
        ]));

        $service = makeRunInspectionService();
        $result = $service->inspectDispatchRun('op_no_run_id');

        expect($result)->toBeNull();
    });

    it('locates a run through dispatch context and session search', function () {
        $dispatchId = 'op_dispatch_lookup';
        OperationDispatch::unguarded(fn () => OperationDispatch::query()->create([
            'id' => $dispatchId,
            'operation_type' => 'agent_task',
            'employee_id' => RIS_EMPLOYEE_ID,
            'task' => 'Test',
            'status' => 'succeeded',
            'run_id' => RIS_RUN_ID,
        ]));

        $runs = [
            RIS_RUN_ID => [
                'meta' => risRunMeta(),
                'recorded_at' => RIS_TIMESTAMP,
            ],
        ];

        $session = risSessionData($runs);

        $sessionManager = Mockery::mock(SessionManager::class);
        $sessionManager->shouldReceive('list')
            ->with(RIS_EMPLOYEE_ID)
            ->andReturn([$session]);
        $sessionManager->shouldReceive('runMetadata')
            ->with(RIS_EMPLOYEE_ID, RIS_SESSION_ID)
            ->andReturn($runs);

        $service = makeRunInspectionService($sessionManager);
        $result = $service->inspectDispatchRun($dispatchId);

        expect($result)->toBeInstanceOf(RunInspection::class)
            ->and($result->runId)->toBe(RIS_RUN_ID)
            ->and($result->dispatchId)->toBe($dispatchId);
    });
});
