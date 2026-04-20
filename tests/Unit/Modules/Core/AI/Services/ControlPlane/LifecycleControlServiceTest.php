<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\DTO\ControlPlane\LifecyclePreview;
use App\Modules\Core\AI\DTO\ControlPlane\LifecycleRequest as LifecycleRequestDTO;
use App\Modules\Core\AI\DTO\ControlPlane\TelemetryEvent as TelemetryEventDTO;
use App\Modules\Core\AI\DTO\Session;
use App\Modules\Core\AI\Enums\LifecycleAction;
use App\Modules\Core\AI\Enums\LifecycleActionStatus;
use App\Modules\Core\AI\Enums\OperationType;
use App\Modules\Core\AI\Enums\TelemetryEventType;
use App\Modules\Core\AI\Models\LifecycleRequest;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\Browser\BrowserArtifactStore;
use App\Modules\Core\AI\Services\Browser\BrowserSessionManager;
use App\Modules\Core\AI\Services\ControlPlane\LifecycleControlService;
use App\Modules\Core\AI\Services\ControlPlane\OperationalTelemetryService;
use App\Modules\Core\AI\Services\ControlPlane\WireLogger;
use App\Modules\Core\AI\Services\Memory\MemoryCompactor;
use App\Modules\Core\AI\Services\OperationsDispatchService;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

const LCS_EMPLOYEE_ID = 1;
const LCS_SESSION_ID = 'sess_lcs_001';

function createLcsTestUser(): User
{
    $company = Company::factory()->create();

    return User::factory()->create(['company_id' => $company->id]);
}

function makeLcsMocks(): array
{
    $telemetry = Mockery::mock(OperationalTelemetryService::class);
    $telemetry->shouldReceive('record')->andReturnUsing(
        fn () => new TelemetryEventDTO(
            eventId: 'te_stub',
            eventType: TelemetryEventType::LifecycleAction,
            runId: null,
            sessionId: null,
            dispatchId: null,
            employeeId: null,
            targetType: null,
            targetId: null,
            payload: [],
            occurredAt: now()->toIso8601String(),
        ),
    )->byDefault();

    return [
        'memoryCompactor' => Mockery::mock(MemoryCompactor::class),
        'browserSessionManager' => Mockery::mock(BrowserSessionManager::class),
        'browserArtifactStore' => Mockery::mock(BrowserArtifactStore::class),
        'operationsDispatch' => Mockery::mock(OperationsDispatchService::class),
        'sessionManager' => Mockery::mock(SessionManager::class),
        'telemetry' => $telemetry,
        'wireLogger' => Mockery::mock(WireLogger::class),
    ];
}

function makeLcsService(array $mocks): LifecycleControlService
{
    return new LifecycleControlService(
        $mocks['memoryCompactor'],
        $mocks['browserSessionManager'],
        $mocks['browserArtifactStore'],
        $mocks['operationsDispatch'],
        $mocks['sessionManager'],
        $mocks['telemetry'],
        $mocks['wireLogger'],
    );
}

// ------------------------------------------------------------------
// preview
// ------------------------------------------------------------------

describe('preview', function () {
    it('previews prune sessions with stale sessions found', function () {
        $staleActivity = new DateTimeImmutable('-60 days');
        $staleSession = new Session(
            id: LCS_SESSION_ID,
            employeeId: LCS_EMPLOYEE_ID,
            channelType: 'web',
            title: 'Old session',
            createdAt: $staleActivity,
            lastActivityAt: $staleActivity,
        );

        $mocks = makeLcsMocks();
        $mocks['sessionManager']->shouldReceive('list')
            ->with(LCS_EMPLOYEE_ID)
            ->andReturn([$staleSession]);

        $service = makeLcsService($mocks);
        $preview = $service->preview(
            LifecycleAction::PruneSessions,
            ['employee_id' => LCS_EMPLOYEE_ID, 'retention_days' => 30],
        );

        expect($preview)->toBeInstanceOf(LifecyclePreview::class)
            ->and($preview->action)->toBe(LifecycleAction::PruneSessions)
            ->and($preview->affectedCount)->toBe(1)
            ->and($preview->isDestructive)->toBeTrue()
            ->and($preview->affectedSummary[0])->toContain(LCS_SESSION_ID);
    });

    it('previews prune sessions with no stale sessions', function () {
        $recentActivity = new DateTimeImmutable('-2 days');
        $recentSession = new Session(
            id: LCS_SESSION_ID,
            employeeId: LCS_EMPLOYEE_ID,
            channelType: 'web',
            title: 'Recent session',
            createdAt: $recentActivity,
            lastActivityAt: $recentActivity,
        );

        $mocks = makeLcsMocks();
        $mocks['sessionManager']->shouldReceive('list')
            ->with(LCS_EMPLOYEE_ID)
            ->andReturn([$recentSession]);

        $service = makeLcsService($mocks);
        $preview = $service->preview(
            LifecycleAction::PruneSessions,
            ['employee_id' => LCS_EMPLOYEE_ID, 'retention_days' => 30],
        );

        expect($preview->affectedCount)->toBe(0)
            ->and($preview->affectedSummary[0])->toContain('No sessions older than 30 days');
    });

    it('previews sweep browser sessions when available', function () {
        $mocks = makeLcsMocks();
        $mocks['browserSessionManager']->shouldReceive('isAvailable')
            ->andReturn(true);

        $service = makeLcsService($mocks);
        $preview = $service->preview(LifecycleAction::SweepBrowserSessions);

        expect($preview->action)->toBe(LifecycleAction::SweepBrowserSessions)
            ->and($preview->isDestructive)->toBeFalse()
            ->and($preview->affectedSummary[0])->toContain('TTL');
    });

    it('previews sweep browser sessions when unavailable', function () {
        $mocks = makeLcsMocks();
        $mocks['browserSessionManager']->shouldReceive('isAvailable')
            ->andReturn(false);

        $service = makeLcsService($mocks);
        $preview = $service->preview(LifecycleAction::SweepBrowserSessions);

        expect($preview->affectedSummary[0])->toContain('not available');
    });

    it('previews sweep operations with stale dispatches', function () {
        $staleDispatch = new OperationDispatch;
        $staleDispatch->id = 'op_stale_001';
        $staleDispatch->operation_type = OperationType::AgentTask;
        $staleDispatch->started_at = now()->subHours(1);

        $mocks = makeLcsMocks();
        $mocks['operationsDispatch']->shouldReceive('findStale')
            ->with(30)
            ->andReturn(new EloquentCollection([$staleDispatch]));

        $service = makeLcsService($mocks);
        $preview = $service->preview(
            LifecycleAction::SweepOperations,
            ['stale_minutes' => 30],
        );

        expect($preview->affectedCount)->toBe(1)
            ->and($preview->affectedSummary[0])->toContain('op_stale_001');
    });

    it('previews prune artifacts with a session scope', function () {
        $mocks = makeLcsMocks();
        $mocks['browserArtifactStore']->shouldReceive('listForSession')
            ->with(LCS_SESSION_ID)
            ->andReturn(['artifact_1', 'artifact_2']);

        $service = makeLcsService($mocks);
        $preview = $service->preview(
            LifecycleAction::PruneArtifacts,
            ['session_id' => LCS_SESSION_ID],
        );

        expect($preview->action)->toBe(LifecycleAction::PruneArtifacts)
            ->and($preview->affectedCount)->toBe(2)
            ->and($preview->isDestructive)->toBeTrue();
    });

    it('previews prune artifacts without session scope', function () {
        $mocks = makeLcsMocks();
        $service = makeLcsService($mocks);
        $preview = $service->preview(LifecycleAction::PruneArtifacts);

        expect($preview->affectedCount)->toBe(0)
            ->and($preview->affectedSummary[0])->toContain('Specify a session_id');
    });

    it('previews prune wire logs with retention context', function () {
        $mocks = makeLcsMocks();
        $mocks['wireLogger']->shouldReceive('totalBytes')->once()->andReturn(4096);

        $service = makeLcsService($mocks);
        $preview = $service->preview(
            LifecycleAction::PruneWireLogs,
            ['retention_days' => 14],
        );

        expect($preview->action)->toBe(LifecycleAction::PruneWireLogs)
            ->and($preview->isDestructive)->toBeTrue()
            ->and($preview->affectedSummary[0])->toContain('14')
            ->and($preview->affectedSummary[0])->toContain('4');
    });
});

// ------------------------------------------------------------------
// execute
// ------------------------------------------------------------------

describe('execute', function () {
    it('executes sweep operations and records lifecycle request', function () {
        $user = createLcsTestUser();

        $mocks = makeLcsMocks();
        $mocks['operationsDispatch']->shouldReceive('findStale')
            ->with(30)
            ->andReturn(new EloquentCollection);
        $mocks['operationsDispatch']->shouldReceive('sweepStale')
            ->with(30)
            ->andReturn(0);

        $service = makeLcsService($mocks);
        $result = $service->execute(
            LifecycleAction::SweepOperations,
            ['stale_minutes' => 30],
            requestedBy: $user->id,
        );

        expect($result)->toBeInstanceOf(LifecycleRequestDTO::class)
            ->and($result->action)->toBe(LifecycleAction::SweepOperations)
            ->and($result->status)->toBe(LifecycleActionStatus::Completed)
            ->and($result->requestedBy)->toBe($user->id)
            ->and($result->result['swept_operations'])->toBe(0)
            ->and($result->requestId)->toStartWith('lc_');
    });

    it('executes prune sessions and removes stale sessions', function () {
        $staleActivity = new DateTimeImmutable('-60 days');
        $staleSession = new Session(
            id: LCS_SESSION_ID,
            employeeId: LCS_EMPLOYEE_ID,
            channelType: 'web',
            title: 'Old session',
            createdAt: $staleActivity,
            lastActivityAt: $staleActivity,
        );

        $mocks = makeLcsMocks();
        // preview call + execute call
        $mocks['sessionManager']->shouldReceive('list')
            ->with(LCS_EMPLOYEE_ID)
            ->andReturn([$staleSession]);
        $mocks['sessionManager']->shouldReceive('delete')
            ->with(LCS_EMPLOYEE_ID, LCS_SESSION_ID)
            ->once();

        $service = makeLcsService($mocks);
        $result = $service->execute(
            LifecycleAction::PruneSessions,
            ['employee_id' => LCS_EMPLOYEE_ID, 'retention_days' => 30],
        );

        expect($result->status)->toBe(LifecycleActionStatus::Completed)
            ->and($result->result['pruned_sessions'])->toBe(1);
    });

    it('executes prune wire logs and records the deleted count', function () {
        $mocks = makeLcsMocks();
        $mocks['wireLogger']->shouldReceive('totalBytes')->once()->andReturn(2048);
        $mocks['wireLogger']->shouldReceive('pruneOlderThan')
            ->once()
            ->with(7)
            ->andReturn(3);

        $service = makeLcsService($mocks);
        $result = $service->execute(
            LifecycleAction::PruneWireLogs,
            ['retention_days' => 7],
        );

        expect($result->status)->toBe(LifecycleActionStatus::Completed)
            ->and($result->result['pruned_wire_logs'])->toBe(3)
            ->and($result->result['retention_days'])->toBe(7);
    });

    it('executes sweep browser sessions', function () {
        $mocks = makeLcsMocks();
        $mocks['browserSessionManager']->shouldReceive('isAvailable')
            ->andReturn(true);
        $mocks['browserSessionManager']->shouldReceive('sweepStaleSessions')
            ->andReturn(3);

        $service = makeLcsService($mocks);
        $result = $service->execute(LifecycleAction::SweepBrowserSessions);

        expect($result->status)->toBe(LifecycleActionStatus::Completed)
            ->and($result->result['swept_sessions'])->toBe(3);
    });

    it('records a failed lifecycle request when execution throws', function () {
        $mocks = makeLcsMocks();
        $mocks['operationsDispatch']->shouldReceive('findStale')
            ->andReturn(new EloquentCollection);
        $mocks['operationsDispatch']->shouldReceive('sweepStale')
            ->andThrow(new RuntimeException('Database connection lost'));

        $service = makeLcsService($mocks);
        $result = $service->execute(LifecycleAction::SweepOperations);

        expect($result->status)->toBe(LifecycleActionStatus::Failed)
            ->and($result->errorMessage)->toBe('Database connection lost');
    });

    it('persists lifecycle request in the database', function () {
        $mocks = makeLcsMocks();
        $mocks['operationsDispatch']->shouldReceive('findStale')
            ->andReturn(new EloquentCollection);
        $mocks['operationsDispatch']->shouldReceive('sweepStale')
            ->andReturn(0);

        $service = makeLcsService($mocks);
        $result = $service->execute(LifecycleAction::SweepOperations);

        $dbRecord = LifecycleRequest::query()->find($result->requestId);
        expect($dbRecord)->not->toBeNull()
            ->and($dbRecord->action)->toBe(LifecycleAction::SweepOperations)
            ->and($dbRecord->status)->toBe(LifecycleActionStatus::Completed);
    });
});

// ------------------------------------------------------------------
// recent
// ------------------------------------------------------------------

describe('recent', function () {
    it('returns recent lifecycle requests ordered by creation time', function () {
        // Create two lifecycle requests with different timestamps
        $mocks = makeLcsMocks();
        $mocks['operationsDispatch']->shouldReceive('findStale')
            ->andReturn(new EloquentCollection);
        $mocks['operationsDispatch']->shouldReceive('sweepStale')
            ->andReturn(0);
        $mocks['browserSessionManager']->shouldReceive('isAvailable')
            ->andReturn(true);
        $mocks['browserSessionManager']->shouldReceive('sweepStaleSessions')
            ->andReturn(0);

        $service = makeLcsService($mocks);

        $service->execute(LifecycleAction::SweepOperations);
        $service->execute(LifecycleAction::SweepBrowserSessions);

        $recent = $service->recent(10);

        expect($recent)->toHaveCount(2)
            ->and($recent[0])->toBeInstanceOf(LifecycleRequestDTO::class)
            // Most recent first
            ->and($recent[0]->action)->toBe(LifecycleAction::SweepBrowserSessions)
            ->and($recent[1]->action)->toBe(LifecycleAction::SweepOperations);
    });

    it('returns empty array when no requests exist', function () {
        $mocks = makeLcsMocks();
        $service = makeLcsService($mocks);

        $recent = $service->recent();

        expect($recent)->toBe([]);
    });
});
