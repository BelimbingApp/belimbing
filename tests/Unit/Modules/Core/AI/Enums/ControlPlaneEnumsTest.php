<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Enums\ControlPlaneTarget;
use App\Modules\Core\AI\Enums\LifecycleAction;
use App\Modules\Core\AI\Enums\LifecycleActionStatus;
use App\Modules\Core\AI\Enums\PolicyLayer;
use App\Modules\Core\AI\Enums\PolicyVerdict;
use App\Modules\Core\AI\Enums\PresenceState;
use App\Modules\Core\AI\Enums\TelemetryEventType;
use Tests\TestCase;

uses(TestCase::class);

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

    it('provides label and color for every case', function () {
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
    it('has five cases', function () {
        expect(LifecycleAction::cases())->toHaveCount(5);
    });

    it('marks destructive actions correctly', function () {
        expect(LifecycleAction::CompactMemory->isDestructive())->toBeFalse()
            ->and(LifecycleAction::PruneSessions->isDestructive())->toBeTrue()
            ->and(LifecycleAction::PruneArtifacts->isDestructive())->toBeTrue()
            ->and(LifecycleAction::SweepBrowserSessions->isDestructive())->toBeFalse()
            ->and(LifecycleAction::SweepOperations->isDestructive())->toBeFalse();
    });

    it('provides non-empty labels for all cases', function () {
        foreach (LifecycleAction::cases() as $case) {
            expect($case->label())->toBeString()->not()->toBeEmpty();
        }
    });
});

// ------------------------------------------------------------------
// LifecycleActionStatus
// ------------------------------------------------------------------

describe('LifecycleActionStatus', function () {
    it('has five cases', function () {
        expect(LifecycleActionStatus::cases())->toHaveCount(5);
    });

    it('identifies terminal states correctly', function () {
        expect(LifecycleActionStatus::Previewed->isTerminal())->toBeFalse()
            ->and(LifecycleActionStatus::Executing->isTerminal())->toBeFalse()
            ->and(LifecycleActionStatus::Completed->isTerminal())->toBeTrue()
            ->and(LifecycleActionStatus::Failed->isTerminal())->toBeTrue()
            ->and(LifecycleActionStatus::Cancelled->isTerminal())->toBeTrue();
    });

    it('provides label and color for every case', function () {
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
    it('has five cases', function () {
        expect(PolicyLayer::cases())->toHaveCount(5);
    });

    it('orders layers from 1 to 5', function () {
        expect(PolicyLayer::Capability->order())->toBe(1)
            ->and(PolicyLayer::Readiness->order())->toBe(2)
            ->and(PolicyLayer::Subsystem->order())->toBe(3)
            ->and(PolicyLayer::DataNetwork->order())->toBe(4)
            ->and(PolicyLayer::Operator->order())->toBe(5);
    });

    it('provides non-empty labels for all cases', function () {
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
