<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\DTO\ControlPlane\TelemetryEvent;
use App\Modules\Core\AI\Enums\ControlPlaneTarget;
use App\Modules\Core\AI\Enums\TelemetryEventType;
use App\Modules\Core\AI\Models\TelemetryEvent as TelemetryEventModel;
use App\Modules\Core\AI\Services\ControlPlane\OperationalTelemetryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

const OTS_RUN_ID = 'run_ots_001';
const OTS_SESSION_ID = 'sess_ots_001';
const OTS_DISPATCH_ID = 'op_ots_001';
const OTS_EMPLOYEE_ID = 1;

function makeOTService(): OperationalTelemetryService
{
    return new OperationalTelemetryService;
}

// ------------------------------------------------------------------
// record
// ------------------------------------------------------------------

describe('record', function () {
    it('persists a telemetry event and returns a DTO', function () {
        $service = makeOTService();

        $dto = $service->record(
            eventType: TelemetryEventType::RunStarted,
            payload: ['model' => 'claude-opus-4'],
            runId: OTS_RUN_ID,
            sessionId: OTS_SESSION_ID,
            employeeId: OTS_EMPLOYEE_ID,
        );

        expect($dto)->toBeInstanceOf(TelemetryEvent::class)
            ->and($dto->eventType)->toBe(TelemetryEventType::RunStarted)
            ->and($dto->runId)->toBe(OTS_RUN_ID)
            ->and($dto->sessionId)->toBe(OTS_SESSION_ID)
            ->and($dto->employeeId)->toBe(OTS_EMPLOYEE_ID)
            ->and($dto->payload)->toBe(['model' => 'claude-opus-4'])
            ->and(str_starts_with($dto->eventId, TelemetryEventModel::ID_PREFIX))->toBeTrue();

        // Verify persisted
        $model = TelemetryEventModel::query()->find($dto->eventId);
        expect($model)->not()->toBeNull()
            ->and($model->event_type)->toBe(TelemetryEventType::RunStarted);
    });

    it('stores target type and target ID when provided', function () {
        $service = makeOTService();

        $dto = $service->record(
            eventType: TelemetryEventType::HealthCheck,
            payload: [],
            targetType: ControlPlaneTarget::Tool,
            targetId: 'bash',
        );

        expect($dto->targetType)->toBe(ControlPlaneTarget::Tool)
            ->and($dto->targetId)->toBe('bash');
    });
});

// ------------------------------------------------------------------
// Query methods
// ------------------------------------------------------------------

describe('query methods', function () {
    beforeEach(function () {
        $this->service = makeOTService();

        // Seed several events
        $this->service->record(
            eventType: TelemetryEventType::RunStarted,
            runId: OTS_RUN_ID,
            sessionId: OTS_SESSION_ID,
            employeeId: OTS_EMPLOYEE_ID,
        );

        $this->service->record(
            eventType: TelemetryEventType::RunCompleted,
            runId: OTS_RUN_ID,
            sessionId: OTS_SESSION_ID,
            employeeId: OTS_EMPLOYEE_ID,
        );

        $this->service->record(
            eventType: TelemetryEventType::ToolInvoked,
            payload: ['tool' => 'bash'],
            runId: OTS_RUN_ID,
            sessionId: OTS_SESSION_ID,
            employeeId: OTS_EMPLOYEE_ID,
        );

        $this->service->record(
            eventType: TelemetryEventType::HealthCheck,
            dispatchId: OTS_DISPATCH_ID,
        );
    });

    it('queries events by run ID', function () {
        $events = $this->service->forRun(OTS_RUN_ID);

        expect($events)->toHaveCount(3)
            ->and($events[0])->toBeInstanceOf(TelemetryEvent::class)
            ->and($events[0]->runId)->toBe(OTS_RUN_ID);
    });

    it('queries events by session ID', function () {
        $events = $this->service->forSession(OTS_SESSION_ID);

        expect($events)->toHaveCount(3);
    });

    it('queries events by agent employee ID', function () {
        $events = $this->service->forAgent(OTS_EMPLOYEE_ID);

        expect($events)->toHaveCount(3)
            ->and($events[0]->employeeId)->toBe(OTS_EMPLOYEE_ID);
    });

    it('queries events by type within time window', function () {
        $events = $this->service->byType(TelemetryEventType::RunStarted, minutesBack: 60);

        expect($events)->toHaveCount(1)
            ->and($events[0]->eventType)->toBe(TelemetryEventType::RunStarted);
    });

    it('counts events by type within time window', function () {
        $counts = $this->service->countByType(minutesBack: 60);

        expect($counts)->toHaveKey('run_started')
            ->and($counts['run_started'])->toBe(1)
            ->and($counts['run_completed'])->toBe(1)
            ->and($counts['tool_invoked'])->toBe(1)
            ->and($counts['health_check'])->toBe(1)
            ->and($counts['run_failed'])->toBe(0);

        // All TelemetryEventType cases should be represented
        foreach (TelemetryEventType::cases() as $type) {
            expect($counts)->toHaveKey($type->value);
        }
    });

    it('returns empty array for unmatched run ID', function () {
        $events = $this->service->forRun('run_nonexistent');

        expect($events)->toBe([]);
    });
});
