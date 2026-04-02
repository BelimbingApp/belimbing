<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\DTO\ControlPlane\HealthSnapshot;
use App\Modules\Core\AI\DTO\ControlPlane\LifecyclePreview;
use App\Modules\Core\AI\DTO\ControlPlane\LifecycleRequest;
use App\Modules\Core\AI\DTO\ControlPlane\PolicyDecision;
use App\Modules\Core\AI\DTO\ControlPlane\RunInspection;
use App\Modules\Core\AI\DTO\ControlPlane\TelemetryEvent;
use App\Modules\Core\AI\Enums\ControlPlaneTarget;
use App\Modules\Core\AI\Enums\LifecycleAction;
use App\Modules\Core\AI\Enums\LifecycleActionStatus;
use App\Modules\Core\AI\Enums\PolicyLayer;
use App\Modules\Core\AI\Enums\PolicyVerdict;
use App\Modules\Core\AI\Enums\PresenceState;
use App\Modules\Core\AI\Enums\TelemetryEventType;
use App\Modules\Core\AI\Enums\ToolHealthState;
use App\Modules\Core\AI\Enums\ToolReadiness;
use Tests\TestCase;

uses(TestCase::class);

const CP_DTO_RUN_ID = 'run_01JTEST000000000001';
const CP_DTO_SESSION_ID = 'sess_01JTEST000000000001';
const CP_DTO_DISPATCH_ID = 'op_01JTEST000000000001';
const CP_DTO_EVENT_ID = 'te_01JTEST000000000001';
const CP_DTO_REQUEST_ID = 'lc_01JTEST000000000001';
const CP_DTO_TIMESTAMP = '2026-04-02T10:00:00+00:00';
const CP_DTO_EMPLOYEE_ID = 1;
const CP_DTO_PROVIDER = 'anthropic';
const CP_DTO_MODEL = 'claude-opus-4';

// ------------------------------------------------------------------
// RunInspection
// ------------------------------------------------------------------

describe('RunInspection', function () {
    it('constructs and serializes to array with snake_case keys', function () {
        $dto = new RunInspection(
            runId: CP_DTO_RUN_ID,
            employeeId: CP_DTO_EMPLOYEE_ID,
            sessionId: CP_DTO_SESSION_ID,
            dispatchId: CP_DTO_DISPATCH_ID,
            provider: CP_DTO_PROVIDER,
            model: CP_DTO_MODEL,
            outcome: 'success',
            latencyMs: 1500,
            tokens: ['prompt' => 100, 'completion' => 50],
            toolActions: [['tool' => 'bash', 'result_length' => 42]],
            fallbackAttempts: [],
            retryAttempts: 0,
            errorType: null,
            errorMessage: null,
            recordedAt: CP_DTO_TIMESTAMP,
        );

        $array = $dto->toArray();

        expect($array)->toHaveKeys([
            'run_id', 'employee_id', 'session_id', 'dispatch_id',
            'provider', 'model', 'outcome', 'latency_ms', 'tokens',
            'tool_actions', 'fallback_attempts', 'retry_attempts',
            'error_type', 'error_message', 'recorded_at',
        ])
            ->and($array['run_id'])->toBe(CP_DTO_RUN_ID)
            ->and($array['provider'])->toBe(CP_DTO_PROVIDER)
            ->and($array['outcome'])->toBe('success')
            ->and($array['latency_ms'])->toBe(1500)
            ->and($array['tokens']['prompt'])->toBe(100)
            ->and($array['tool_actions'])->toHaveCount(1);
    });

    it('builds from run metadata via fromRunMeta', function () {
        $meta = [
            'llm' => ['provider' => CP_DTO_PROVIDER, 'model' => CP_DTO_MODEL],
            'latency_ms' => 800,
            'tokens' => ['prompt' => 200, 'completion' => 100],
            'tool_actions' => [
                ['tool' => 'query_data', 'result_length' => 256],
                ['name' => 'bash'],
            ],
            'fallback_attempts' => [],
            'retry_attempts' => 1,
        ];

        $dto = RunInspection::fromRunMeta(
            runId: CP_DTO_RUN_ID,
            employeeId: CP_DTO_EMPLOYEE_ID,
            sessionId: CP_DTO_SESSION_ID,
            meta: $meta,
            recordedAt: CP_DTO_TIMESTAMP,
            dispatchId: CP_DTO_DISPATCH_ID,
        );

        expect($dto->provider)->toBe(CP_DTO_PROVIDER)
            ->and($dto->model)->toBe(CP_DTO_MODEL)
            ->and($dto->outcome)->toBe('success')
            ->and($dto->latencyMs)->toBe(800)
            ->and($dto->retryAttempts)->toBe(1)
            ->and($dto->toolActions)->toHaveCount(2)
            ->and($dto->toolActions[0]['tool'])->toBe('query_data')
            ->and($dto->toolActions[1]['tool'])->toBe('bash');
    });

    it('detects error outcome from meta error field', function () {
        $meta = [
            'provider_name' => CP_DTO_PROVIDER,
            'model' => CP_DTO_MODEL,
            'error' => 'Connection timeout',
            'error_type' => 'timeout',
        ];

        $dto = RunInspection::fromRunMeta(
            runId: CP_DTO_RUN_ID,
            employeeId: CP_DTO_EMPLOYEE_ID,
            sessionId: CP_DTO_SESSION_ID,
            meta: $meta,
            recordedAt: CP_DTO_TIMESTAMP,
        );

        expect($dto->outcome)->toBe('error')
            ->and($dto->errorType)->toBe('timeout')
            ->and($dto->errorMessage)->toBe('Connection timeout')
            ->and($dto->dispatchId)->toBeNull();
    });

    it('handles empty meta gracefully', function () {
        $dto = RunInspection::fromRunMeta(
            runId: CP_DTO_RUN_ID,
            employeeId: CP_DTO_EMPLOYEE_ID,
            sessionId: CP_DTO_SESSION_ID,
            meta: [],
            recordedAt: CP_DTO_TIMESTAMP,
        );

        expect($dto->provider)->toBe('unknown')
            ->and($dto->model)->toBe('unknown')
            ->and($dto->outcome)->toBe('success')
            ->and($dto->latencyMs)->toBeNull()
            ->and($dto->tokens)->toBe(['prompt' => null, 'completion' => null])
            ->and($dto->toolActions)->toBe([])
            ->and($dto->retryAttempts)->toBe(0);
    });
});

// ------------------------------------------------------------------
// HealthSnapshot
// ------------------------------------------------------------------

describe('HealthSnapshot', function () {
    it('constructs and serializes with enum values', function () {
        $dto = new HealthSnapshot(
            targetType: ControlPlaneTarget::Tool,
            targetId: 'bash',
            readiness: ToolReadiness::READY,
            health: ToolHealthState::HEALTHY,
            presence: PresenceState::Active,
            explanation: 'Tool is ready and healthy.',
            measuredAt: CP_DTO_TIMESTAMP,
        );

        $array = $dto->toArray();

        expect($array)->toHaveKeys([
            'target_type', 'target_id', 'readiness', 'health',
            'presence', 'explanation', 'measured_at',
        ])
            ->and($array['target_type'])->toBe('tool')
            ->and($array['readiness'])->toBe('ready')
            ->and($array['health'])->toBe('healthy')
            ->and($array['presence'])->toBe('active');
    });
});

// ------------------------------------------------------------------
// LifecyclePreview
// ------------------------------------------------------------------

describe('LifecyclePreview', function () {
    it('constructs and serializes correctly', function () {
        $dto = new LifecyclePreview(
            action: LifecycleAction::PruneSessions,
            scope: ['employee_id' => CP_DTO_EMPLOYEE_ID, 'retention_days' => 30],
            affectedCount: 3,
            affectedSummary: ['Session A', 'Session B', 'Session C'],
            isDestructive: true,
            generatedAt: CP_DTO_TIMESTAMP,
        );

        $array = $dto->toArray();

        expect($array['action'])->toBe('prune_sessions')
            ->and($array['affected_count'])->toBe(3)
            ->and($array['is_destructive'])->toBeTrue()
            ->and($array['affected_summary'])->toHaveCount(3)
            ->and($array['scope']['retention_days'])->toBe(30);
    });
});

// ------------------------------------------------------------------
// LifecycleRequest (DTO)
// ------------------------------------------------------------------

describe('LifecycleRequest DTO', function () {
    it('constructs and serializes with nested preview', function () {
        $preview = new LifecyclePreview(
            action: LifecycleAction::CompactMemory,
            scope: ['employee_id' => CP_DTO_EMPLOYEE_ID],
            affectedCount: 5,
            affectedSummary: ['5 daily notes to compact.'],
            isDestructive: false,
            generatedAt: CP_DTO_TIMESTAMP,
        );

        $dto = new LifecycleRequest(
            requestId: CP_DTO_REQUEST_ID,
            action: LifecycleAction::CompactMemory,
            scope: ['employee_id' => CP_DTO_EMPLOYEE_ID],
            status: LifecycleActionStatus::Completed,
            preview: $preview,
            result: ['compacted_files' => 5],
            errorMessage: null,
            requestedBy: 42,
            createdAt: CP_DTO_TIMESTAMP,
            executedAt: CP_DTO_TIMESTAMP,
        );

        $array = $dto->toArray();

        expect($array['request_id'])->toBe(CP_DTO_REQUEST_ID)
            ->and($array['action'])->toBe('compact_memory')
            ->and($array['status'])->toBe('completed')
            ->and($array['preview'])->toBeArray()
            ->and($array['preview']['affected_count'])->toBe(5)
            ->and($array['result']['compacted_files'])->toBe(5)
            ->and($array['requested_by'])->toBe(42)
            ->and($array['error_message'])->toBeNull();
    });

    it('serializes null preview when not previewed', function () {
        $dto = new LifecycleRequest(
            requestId: CP_DTO_REQUEST_ID,
            action: LifecycleAction::SweepOperations,
            scope: [],
            status: LifecycleActionStatus::Executing,
            preview: null,
            result: null,
            errorMessage: null,
            requestedBy: null,
            createdAt: CP_DTO_TIMESTAMP,
            executedAt: null,
        );

        $array = $dto->toArray();

        expect($array['preview'])->toBeNull()
            ->and($array['result'])->toBeNull()
            ->and($array['executed_at'])->toBeNull()
            ->and($array['requested_by'])->toBeNull();
    });
});

// ------------------------------------------------------------------
// PolicyDecision
// ------------------------------------------------------------------

describe('PolicyDecision', function () {
    it('creates allow decisions via factory', function () {
        $decision = PolicyDecision::allow(
            subject: 'agent:1',
            action: 'use_tool:bash',
            context: ['foo' => 'bar'],
            layerResults: [
                ['layer' => 'capability', 'verdict' => 'allow', 'reason' => 'OK'],
            ],
        );

        expect($decision->verdict)->toBe(PolicyVerdict::Allow)
            ->and($decision->decidingLayer)->toBe(PolicyLayer::Operator)
            ->and($decision->isAllowed())->toBeTrue()
            ->and($decision->reason)->toBe('All policy layers passed.');
    });

    it('creates deny decisions via factory', function () {
        $decision = PolicyDecision::deny(
            layer: PolicyLayer::Capability,
            reason: 'Unauthorized for tool.',
            subject: 'agent:1',
            action: 'use_tool:bash',
        );

        expect($decision->verdict)->toBe(PolicyVerdict::Deny)
            ->and($decision->decidingLayer)->toBe(PolicyLayer::Capability)
            ->and($decision->isAllowed())->toBeFalse()
            ->and($decision->reason)->toBe('Unauthorized for tool.');
    });

    it('creates degrade decisions via factory', function () {
        $decision = PolicyDecision::degrade(
            layer: PolicyLayer::Readiness,
            reason: 'Tool needs attention.',
            subject: 'agent:2',
            action: 'use_tool:web_search',
        );

        expect($decision->verdict)->toBe(PolicyVerdict::Degrade)
            ->and($decision->decidingLayer)->toBe(PolicyLayer::Readiness)
            ->and($decision->isAllowed())->toBeTrue()
            ->and($decision->reason)->toBe('Tool needs attention.');
    });

    it('serializes to array with snake_case keys', function () {
        $decision = PolicyDecision::deny(
            layer: PolicyLayer::DataNetwork,
            reason: 'SSRF blocked.',
            subject: 'agent:1',
            action: 'network_access:http://169.254.169.254',
            context: ['url' => 'http://169.254.169.254'],
            layerResults: [
                ['layer' => 'data_network', 'verdict' => 'deny', 'reason' => 'SSRF blocked.'],
            ],
        );

        $array = $decision->toArray();

        expect($array)->toHaveKeys([
            'verdict', 'deciding_layer', 'reason', 'subject',
            'action', 'context', 'layer_results',
        ])
            ->and($array['verdict'])->toBe('deny')
            ->and($array['deciding_layer'])->toBe('data_network')
            ->and($array['layer_results'])->toHaveCount(1);
    });
});

// ------------------------------------------------------------------
// TelemetryEvent (DTO)
// ------------------------------------------------------------------

describe('TelemetryEvent DTO', function () {
    it('constructs and serializes with all correlation IDs', function () {
        $dto = new TelemetryEvent(
            eventId: CP_DTO_EVENT_ID,
            eventType: TelemetryEventType::RunCompleted,
            runId: CP_DTO_RUN_ID,
            sessionId: CP_DTO_SESSION_ID,
            dispatchId: CP_DTO_DISPATCH_ID,
            employeeId: CP_DTO_EMPLOYEE_ID,
            targetType: ControlPlaneTarget::Agent,
            targetId: '1',
            payload: ['latency_ms' => 200],
            occurredAt: CP_DTO_TIMESTAMP,
        );

        $array = $dto->toArray();

        expect($array)->toHaveKeys([
            'event_id', 'event_type', 'run_id', 'session_id',
            'dispatch_id', 'employee_id', 'target_type', 'target_id',
            'payload', 'occurred_at',
        ])
            ->and($array['event_type'])->toBe('run_completed')
            ->and($array['target_type'])->toBe('agent')
            ->and($array['payload']['latency_ms'])->toBe(200);
    });

    it('handles nullable fields correctly', function () {
        $dto = new TelemetryEvent(
            eventId: CP_DTO_EVENT_ID,
            eventType: TelemetryEventType::HealthCheck,
            runId: null,
            sessionId: null,
            dispatchId: null,
            employeeId: null,
            targetType: null,
            targetId: null,
            payload: [],
            occurredAt: CP_DTO_TIMESTAMP,
        );

        $array = $dto->toArray();

        expect($array['run_id'])->toBeNull()
            ->and($array['session_id'])->toBeNull()
            ->and($array['dispatch_id'])->toBeNull()
            ->and($array['employee_id'])->toBeNull()
            ->and($array['target_type'])->toBeNull()
            ->and($array['target_id'])->toBeNull();
    });
});
