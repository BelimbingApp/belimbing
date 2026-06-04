<?php

use App\Modules\Core\AI\Enums\ControlPlaneTarget;
use App\Modules\Core\AI\Enums\LifecycleAction;
use App\Modules\Core\AI\Enums\LifecycleActionStatus;
use App\Modules\Core\AI\Enums\PolicyLayer;
use App\Modules\Core\AI\Enums\PolicyVerdict;
use App\Modules\Core\AI\Enums\PresenceState;
use App\Modules\Core\AI\Enums\TelemetryEventType;
use Tests\TestCase;

uses(TestCase::class);

const CP_ENUM_LABEL_COLOR_CASE_MESSAGE = 'provides label and color for every case';
const CP_ENUM_FIVE_CASES_MESSAGE = 'has five cases';
const CP_ENUM_SEVEN_CASES_MESSAGE = 'has seven cases';
const CP_ENUM_NON_EMPTY_LABELS_MESSAGE = 'provides non-empty labels for all cases';

// ------------------------------------------------------------------
// ControlPlaneTarget
// ------------------------------------------------------------------

describe('ControlPlaneTarget', function () {
    it('has seven cases', function () {
        expect(ControlPlaneTarget::cases())->toHaveCount(7);
    });

    it('provides non-empty labels for every case', function () {
        foreach (ControlPlaneTarget::cases() as $case) {
            expect($case->label())->toBeString()->not()->toBeEmpty();
        }
    });

    it('maps specific backing values', function () {
        expect(ControlPlaneTarget::Agent->value)->toBe('agent')
            ->and(ControlPlaneTarget::BrowserSession->value)->toBe('browser_session')
            ->and(ControlPlaneTarget::Dispatch->value)->toBe('dispatch');
    });
});

// ------------------------------------------------------------------
// PresenceState
// ------------------------------------------------------------------

describe('PresenceState', function () {
    it('has three cases', function () {
        expect(PresenceState::cases())->toHaveCount(3);
    });

    it(CP_ENUM_LABEL_COLOR_CASE_MESSAGE, function () {
        foreach (PresenceState::cases() as $case) {
            expect($case->label())->toBeString()->not()->toBeEmpty()
                ->and($case->color())->toBeString()->not()->toBeEmpty();
        }
    });

    it('maps expected backing values', function () {
        expect(PresenceState::Offline->value)->toBe('offline')
            ->and(PresenceState::Idle->value)->toBe('idle')
            ->and(PresenceState::Active->value)->toBe('active');
    });

    it('assigns distinct colors per state', function () {
        expect(PresenceState::Offline->color())->toBe('default')
            ->and(PresenceState::Idle->color())->toBe('warning')
            ->and(PresenceState::Active->color())->toBe('success');
    });
});

// ------------------------------------------------------------------
// LifecycleAction
// ------------------------------------------------------------------

describe('LifecycleAction', function () {
    it(CP_ENUM_SEVEN_CASES_MESSAGE, function () {
        expect(LifecycleAction::cases())->toHaveCount(7);
    });

    it('maps specific backing values', function () {
        expect(LifecycleAction::CompactMemory->value)->toBe('compact_memory')
            ->and(LifecycleAction::PruneSessions->value)->toBe('prune_sessions')
            ->and(LifecycleAction::PruneArtifacts->value)->toBe('prune_artifacts')
            ->and(LifecycleAction::SweepBrowserSessions->value)->toBe('sweep_browser_sessions')
            ->and(LifecycleAction::SweepOperations->value)->toBe('sweep_operations')
            ->and(LifecycleAction::PruneWireLogs->value)->toBe('prune_wire_logs')
            ->and(LifecycleAction::RefreshPricingSnapshot->value)->toBe('refresh_pricing_snapshot');
    });

    it('marks destructive actions correctly', function () {
        expect(LifecycleAction::CompactMemory->isDestructive())->toBeFalse()
            ->and(LifecycleAction::PruneSessions->isDestructive())->toBeTrue()
            ->and(LifecycleAction::PruneArtifacts->isDestructive())->toBeTrue()
            ->and(LifecycleAction::SweepBrowserSessions->isDestructive())->toBeFalse()
            ->and(LifecycleAction::SweepOperations->isDestructive())->toBeFalse()
            ->and(LifecycleAction::PruneWireLogs->isDestructive())->toBeTrue()
            ->and(LifecycleAction::RefreshPricingSnapshot->isDestructive())->toBeFalse();
    });

    it(CP_ENUM_NON_EMPTY_LABELS_MESSAGE, function () {
        foreach (LifecycleAction::cases() as $case) {
            expect($case->label())->toBeString()->not()->toBeEmpty();
        }
    });
});

// ------------------------------------------------------------------
// LifecycleActionStatus
// ------------------------------------------------------------------

describe('LifecycleActionStatus', function () {
    it(CP_ENUM_FIVE_CASES_MESSAGE, function () {
        expect(LifecycleActionStatus::cases())->toHaveCount(5);
    });

    it('maps specific backing values', function () {
        expect(LifecycleActionStatus::Previewed->value)->toBe('previewed')
            ->and(LifecycleActionStatus::Executing->value)->toBe('executing')
            ->and(LifecycleActionStatus::Completed->value)->toBe('completed')
            ->and(LifecycleActionStatus::Failed->value)->toBe('failed')
            ->and(LifecycleActionStatus::Cancelled->value)->toBe('cancelled');
    });

    it('identifies terminal states correctly', function () {
        expect(LifecycleActionStatus::Previewed->isTerminal())->toBeFalse()
            ->and(LifecycleActionStatus::Executing->isTerminal())->toBeFalse()
            ->and(LifecycleActionStatus::Completed->isTerminal())->toBeTrue()
            ->and(LifecycleActionStatus::Failed->isTerminal())->toBeTrue()
            ->and(LifecycleActionStatus::Cancelled->isTerminal())->toBeTrue();
    });

    it(CP_ENUM_LABEL_COLOR_CASE_MESSAGE, function () {
        foreach (LifecycleActionStatus::cases() as $case) {
            expect($case->label())->toBeString()->not()->toBeEmpty()
                ->and($case->color())->toBeString()->not()->toBeEmpty();
        }
    });
});

// ------------------------------------------------------------------
// PolicyLayer
// ------------------------------------------------------------------

describe('PolicyLayer', function () {
    it(CP_ENUM_FIVE_CASES_MESSAGE, function () {
        expect(PolicyLayer::cases())->toHaveCount(5);
    });

    it('maps specific backing values', function () {
        expect(PolicyLayer::Capability->value)->toBe('capability')
            ->and(PolicyLayer::Readiness->value)->toBe('readiness')
            ->and(PolicyLayer::Subsystem->value)->toBe('subsystem')
            ->and(PolicyLayer::DataNetwork->value)->toBe('data_network')
            ->and(PolicyLayer::Operator->value)->toBe('operator');
    });

    it('orders layers from 1 to 5', function () {
        expect(PolicyLayer::Capability->order())->toBe(1)
            ->and(PolicyLayer::Readiness->order())->toBe(2)
            ->and(PolicyLayer::Subsystem->order())->toBe(3)
            ->and(PolicyLayer::DataNetwork->order())->toBe(4)
            ->and(PolicyLayer::Operator->order())->toBe(5);
    });

    it(CP_ENUM_NON_EMPTY_LABELS_MESSAGE, function () {
        foreach (PolicyLayer::cases() as $case) {
            expect($case->label())->toBeString()->not()->toBeEmpty();
        }
    });
});

// ------------------------------------------------------------------
// PolicyVerdict
// ------------------------------------------------------------------

describe('PolicyVerdict', function () {
    it('has three cases', function () {
        expect(PolicyVerdict::cases())->toHaveCount(3);
    });

    it('provides label and color for every case', function () {
        foreach (PolicyVerdict::cases() as $case) {
            expect($case->label())->toBeString()->not()->toBeEmpty()
                ->and($case->color())->toBeString()->not()->toBeEmpty();
        }
    });

    it('maps expected backing values', function () {
        expect(PolicyVerdict::Allow->value)->toBe('allow')
            ->and(PolicyVerdict::Deny->value)->toBe('deny')
            ->and(PolicyVerdict::Degrade->value)->toBe('degrade');
    });
});

// ------------------------------------------------------------------
// TelemetryEventType
// ------------------------------------------------------------------

describe('TelemetryEventType', function () {
    it('has twelve cases', function () {
        expect(TelemetryEventType::cases())->toHaveCount(12);
    });

    it('maps specific backing values', function () {
        expect(TelemetryEventType::RunStarted->value)->toBe('run_started')
            ->and(TelemetryEventType::RunCompleted->value)->toBe('run_completed')
            ->and(TelemetryEventType::RunFailed->value)->toBe('run_failed')
            ->and(TelemetryEventType::ToolInvoked->value)->toBe('tool_invoked')
            ->and(TelemetryEventType::ProviderFallback->value)->toBe('provider_fallback')
            ->and(TelemetryEventType::PolicyDecision->value)->toBe('policy_decision');
    });

    it('provides non-empty labels for all cases', function () {
        foreach (TelemetryEventType::cases() as $case) {
            expect($case->label())->toBeString()->not()->toBeEmpty();
        }
    });

    it('classifies error types as error severity', function () {
        expect(TelemetryEventType::RunFailed->severity())->toBe('error')
            ->and(TelemetryEventType::StreamFailed->severity())->toBe('error');
    });

    it('classifies fallback and policy decisions as warning severity', function () {
        expect(TelemetryEventType::ProviderFallback->severity())->toBe('warning')
            ->and(TelemetryEventType::PolicyDecision->severity())->toBe('warning');
    });

    it('defaults to info severity for normal operations', function () {
        expect(TelemetryEventType::RunStarted->severity())->toBe('info')
            ->and(TelemetryEventType::RunCompleted->severity())->toBe('info')
            ->and(TelemetryEventType::ToolInvoked->severity())->toBe('info')
            ->and(TelemetryEventType::HealthCheck->severity())->toBe('info');
    });
});
