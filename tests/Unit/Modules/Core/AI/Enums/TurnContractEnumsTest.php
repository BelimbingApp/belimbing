<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Enums\TurnEventType;
use App\Modules\Core\AI\Enums\TurnPhase;
use App\Modules\Core\AI\Enums\TurnStatus;

const TURN_STATUS_TERMINAL_STATES = ['completed', 'failed', 'cancelled'];

// ------------------------------------------------------------------
// TurnStatus — lifecycle state machine
// ------------------------------------------------------------------

describe('TurnStatus', function () {
    it('has six cases', function () {
        expect(TurnStatus::cases())->toHaveCount(6);
    });

    it('identifies terminal states correctly', function () {
        expect(TurnStatus::Queued->isTerminal())->toBeFalse()
            ->and(TurnStatus::Booting->isTerminal())->toBeFalse()
            ->and(TurnStatus::Running->isTerminal())->toBeFalse()
            ->and(TurnStatus::Completed->isTerminal())->toBeTrue()
            ->and(TurnStatus::Failed->isTerminal())->toBeTrue()
            ->and(TurnStatus::Cancelled->isTerminal())->toBeTrue();
    });

    it('provides inverse of terminal via isActive', function () {
        foreach (TurnStatus::cases() as $case) {
            expect($case->isActive())->toBe(! $case->isTerminal());
        }
    });

    it('enforces valid state transitions from queued', function () {
        $queued = TurnStatus::Queued;
        expect($queued->canTransitionTo(TurnStatus::Booting))->toBeTrue()
            ->and($queued->canTransitionTo(TurnStatus::Failed))->toBeTrue()
            ->and($queued->canTransitionTo(TurnStatus::Cancelled))->toBeTrue()
            ->and($queued->canTransitionTo(TurnStatus::Running))->toBeFalse()
            ->and($queued->canTransitionTo(TurnStatus::Completed))->toBeFalse();
    });

    it('enforces valid state transitions from booting', function () {
        $booting = TurnStatus::Booting;
        expect($booting->canTransitionTo(TurnStatus::Running))->toBeTrue()
            ->and($booting->canTransitionTo(TurnStatus::Failed))->toBeTrue()
            ->and($booting->canTransitionTo(TurnStatus::Cancelled))->toBeTrue()
            ->and($booting->canTransitionTo(TurnStatus::Queued))->toBeFalse()
            ->and($booting->canTransitionTo(TurnStatus::Completed))->toBeFalse();
    });

    it('enforces valid state transitions from running', function () {
        $running = TurnStatus::Running;
        expect($running->canTransitionTo(TurnStatus::Completed))->toBeTrue()
            ->and($running->canTransitionTo(TurnStatus::Failed))->toBeTrue()
            ->and($running->canTransitionTo(TurnStatus::Cancelled))->toBeTrue()
            ->and($running->canTransitionTo(TurnStatus::Queued))->toBeFalse()
            ->and($running->canTransitionTo(TurnStatus::Booting))->toBeFalse();
    });

    it('blocks transitions from terminal states', function () {
        foreach ([TurnStatus::Completed, TurnStatus::Failed, TurnStatus::Cancelled] as $terminal) {
            expect($terminal->allowedTransitions())->toBeEmpty();

            foreach (TurnStatus::cases() as $target) {
                expect($terminal->canTransitionTo($target))->toBeFalse();
            }
        }
    });

    it('provides non-empty labels and colors for all cases', function () {
        foreach (TurnStatus::cases() as $case) {
            expect($case->label())->toBeString()->not()->toBeEmpty()
                ->and($case->color())->toBeString()->not()->toBeEmpty();
        }
    });

    it('maps expected backing values', function () {
        expect(TurnStatus::Queued->value)->toBe('queued')
            ->and(TurnStatus::Booting->value)->toBe('booting')
            ->and(TurnStatus::Running->value)->toBe('running')
            ->and(TurnStatus::Completed->value)->toBe('completed')
            ->and(TurnStatus::Failed->value)->toBe('failed')
            ->and(TurnStatus::Cancelled->value)->toBe('cancelled');
    });
});

// ------------------------------------------------------------------
// TurnPhase — user-visible sub-state
// ------------------------------------------------------------------

describe('TurnPhase', function () {
    it('has seven cases', function () {
        expect(TurnPhase::cases())->toHaveCount(7);
    });

    it('marks active phases as busy', function () {
        expect(TurnPhase::WaitingForWorker->isBusy())->toBeTrue()
            ->and(TurnPhase::Thinking->isBusy())->toBeTrue()
            ->and(TurnPhase::RunningTool->isBusy())->toBeTrue()
            ->and(TurnPhase::StreamingAnswer->isBusy())->toBeTrue()
            ->and(TurnPhase::Finalizing->isBusy())->toBeTrue()
            ->and(TurnPhase::Failed->isBusy())->toBeFalse()
            ->and(TurnPhase::Cancelled->isBusy())->toBeFalse();
    });

    it('provides non-empty labels and icons for all cases', function () {
        foreach (TurnPhase::cases() as $case) {
            expect($case->label())->toBeString()->not()->toBeEmpty()
                ->and($case->icon())->toBeString()->not()->toBeEmpty();
        }
    });

    it('maps expected backing values', function () {
        expect(TurnPhase::WaitingForWorker->value)->toBe('waiting_for_worker')
            ->and(TurnPhase::Thinking->value)->toBe('thinking')
            ->and(TurnPhase::RunningTool->value)->toBe('running_tool')
            ->and(TurnPhase::StreamingAnswer->value)->toBe('streaming_answer')
            ->and(TurnPhase::Finalizing->value)->toBe('finalizing');
    });
});

// ------------------------------------------------------------------
// TurnEventType — event taxonomy contract
// ------------------------------------------------------------------

describe('TurnEventType', function () {
    it('has twenty-two cases', function () {
        expect(TurnEventType::cases())->toHaveCount(22);
    });

    it('uses dot-separated naming convention except for single-word events', function () {
        $singleWordAllowed = ['heartbeat'];

        foreach (TurnEventType::cases() as $case) {
            if (in_array($case->value, $singleWordAllowed, true)) {
                continue;
            }
            expect($case->value)->toContain('.');
        }
    });

    it('identifies terminal events correctly', function () {
        expect(TurnEventType::TurnCompleted->isTerminal())->toBeTrue()
            ->and(TurnEventType::TurnFailed->isTerminal())->toBeTrue()
            ->and(TurnEventType::TurnCancelled->isTerminal())->toBeTrue()
            ->and(TurnEventType::TurnStarted->isTerminal())->toBeFalse()
            ->and(TurnEventType::AssistantOutputDelta->isTerminal())->toBeFalse()
            ->and(TurnEventType::ToolStarted->isTerminal())->toBeFalse();
    });

    it('identifies delta events correctly', function () {
        expect(TurnEventType::AssistantOutputDelta->isDelta())->toBeTrue()
            ->and(TurnEventType::ToolStdoutDelta->isDelta())->toBeTrue()
            ->and(TurnEventType::TurnStarted->isDelta())->toBeFalse()
            ->and(TurnEventType::ToolFinished->isDelta())->toBeFalse();
    });

    it('classifies severity for error events', function () {
        expect(TurnEventType::TurnFailed->severity())->toBe('error')
            ->and(TurnEventType::RunFailed->severity())->toBe('error')
            ->and(TurnEventType::RecoveryFailed->severity())->toBe('error');
    });

    it('classifies severity for warning events', function () {
        expect(TurnEventType::ToolDenied->severity())->toBe('warning')
            ->and(TurnEventType::RecoveryAttempted->severity())->toBe('warning');
    });

    it('defaults to info severity for normal events', function () {
        expect(TurnEventType::TurnStarted->severity())->toBe('info')
            ->and(TurnEventType::AssistantOutputDelta->severity())->toBe('info')
            ->and(TurnEventType::Heartbeat->severity())->toBe('info');
    });

    it('provides non-empty labels for all cases', function () {
        foreach (TurnEventType::cases() as $case) {
            expect($case->label())->toBeString()->not()->toBeEmpty();
        }
    });

    it('covers all expected event families', function () {
        $values = array_map(fn ($c) => $c->value, TurnEventType::cases());

        expect($values)->toContain('turn.started')
            ->toContain('turn.phase_changed')
            ->toContain('turn.completed')
            ->toContain('turn.failed')
            ->toContain('turn.cancelled')
            ->toContain('turn.ready_for_input')
            ->toContain('run.started')
            ->toContain('run.failed')
            ->toContain('assistant.thinking_started')
            ->toContain('assistant.iteration_completed')
            ->toContain('assistant.output_delta')
            ->toContain('assistant.output_block_committed')
            ->toContain('tool.started')
            ->toContain('tool.stdout_delta')
            ->toContain('tool.finished')
            ->toContain('tool.denied')
            ->toContain('usage.updated')
            ->toContain('heartbeat')
            ->toContain('recovery.attempted')
            ->toContain('recovery.succeeded')
            ->toContain('recovery.failed');
    });
});
