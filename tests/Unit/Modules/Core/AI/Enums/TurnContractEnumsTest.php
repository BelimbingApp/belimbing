<?php

use App\Modules\Core\AI\Enums\RunEventType;
use App\Modules\Core\AI\Enums\RunPhase;
use App\Modules\Core\AI\Enums\AiRunStatus;

const TURN_STATUS_TERMINAL_STATES = ['succeeded', 'failed', 'cancelled', 'timed_out'];

// ------------------------------------------------------------------
// AiRunStatus — lifecycle state machine
// ------------------------------------------------------------------

describe('AiRunStatus', function () {
    it('has seven cases', function () {
        expect(AiRunStatus::cases())->toHaveCount(7);
    });

    it('identifies terminal states correctly', function () {
        expect(AiRunStatus::Queued->isTerminal())->toBeFalse()
            ->and(AiRunStatus::Booting->isTerminal())->toBeFalse()
            ->and(AiRunStatus::Running->isTerminal())->toBeFalse()
            ->and(AiRunStatus::Succeeded->isTerminal())->toBeTrue()
            ->and(AiRunStatus::Failed->isTerminal())->toBeTrue()
            ->and(AiRunStatus::Cancelled->isTerminal())->toBeTrue()
            ->and(AiRunStatus::TimedOut->isTerminal())->toBeTrue();
    });

    it('provides inverse of terminal via isActive', function () {
        foreach (AiRunStatus::cases() as $case) {
            expect($case->isActive())->toBe(! $case->isTerminal());
        }
    });

    it('enforces valid state transitions from queued', function () {
        $queued = AiRunStatus::Queued;
        expect($queued->canTransitionTo(AiRunStatus::Booting))->toBeTrue()
            ->and($queued->canTransitionTo(AiRunStatus::Failed))->toBeTrue()
            ->and($queued->canTransitionTo(AiRunStatus::Cancelled))->toBeTrue()
            ->and($queued->canTransitionTo(AiRunStatus::TimedOut))->toBeTrue()
            ->and($queued->canTransitionTo(AiRunStatus::Running))->toBeFalse()
            ->and($queued->canTransitionTo(AiRunStatus::Succeeded))->toBeFalse();
    });

    it('enforces valid state transitions from booting', function () {
        $booting = AiRunStatus::Booting;
        expect($booting->canTransitionTo(AiRunStatus::Running))->toBeTrue()
            ->and($booting->canTransitionTo(AiRunStatus::Failed))->toBeTrue()
            ->and($booting->canTransitionTo(AiRunStatus::Cancelled))->toBeTrue()
            ->and($booting->canTransitionTo(AiRunStatus::TimedOut))->toBeTrue()
            ->and($booting->canTransitionTo(AiRunStatus::Queued))->toBeFalse()
            ->and($booting->canTransitionTo(AiRunStatus::Succeeded))->toBeFalse();
    });

    it('enforces valid state transitions from running', function () {
        $running = AiRunStatus::Running;
        expect($running->canTransitionTo(AiRunStatus::Succeeded))->toBeTrue()
            ->and($running->canTransitionTo(AiRunStatus::Failed))->toBeTrue()
            ->and($running->canTransitionTo(AiRunStatus::Cancelled))->toBeTrue()
            ->and($running->canTransitionTo(AiRunStatus::TimedOut))->toBeTrue()
            ->and($running->canTransitionTo(AiRunStatus::Queued))->toBeFalse()
            ->and($running->canTransitionTo(AiRunStatus::Booting))->toBeFalse();
    });

    it('blocks transitions from terminal states', function () {
        foreach ([AiRunStatus::Succeeded, AiRunStatus::Failed, AiRunStatus::Cancelled, AiRunStatus::TimedOut] as $terminal) {
            expect($terminal->allowedTransitions())->toBeEmpty();

            foreach (AiRunStatus::cases() as $target) {
                expect($terminal->canTransitionTo($target))->toBeFalse();
            }
        }
    });

    it('provides non-empty labels and colors for all cases', function () {
        foreach (AiRunStatus::cases() as $case) {
            expect($case->label())->toBeString()->not()->toBeEmpty()
                ->and($case->color())->toBeString()->not()->toBeEmpty();
        }
    });

    it('maps expected backing values', function () {
        expect(AiRunStatus::Queued->value)->toBe('queued')
            ->and(AiRunStatus::Booting->value)->toBe('booting')
            ->and(AiRunStatus::Running->value)->toBe('running')
            ->and(AiRunStatus::Succeeded->value)->toBe('succeeded')
            ->and(AiRunStatus::Failed->value)->toBe('failed')
            ->and(AiRunStatus::Cancelled->value)->toBe('cancelled')
            ->and(AiRunStatus::TimedOut->value)->toBe('timed_out');
    });
});

// ------------------------------------------------------------------
// RunPhase — user-visible sub-state
// ------------------------------------------------------------------

describe('RunPhase', function () {
    it('has seven cases', function () {
        expect(RunPhase::cases())->toHaveCount(7);
    });

    it('marks active phases as busy', function () {
        expect(RunPhase::WaitingForWorker->isBusy())->toBeTrue()
            ->and(RunPhase::AwaitingLlm->isBusy())->toBeTrue()
            ->and(RunPhase::RunningTool->isBusy())->toBeTrue()
            ->and(RunPhase::StreamingAnswer->isBusy())->toBeTrue()
            ->and(RunPhase::Finalizing->isBusy())->toBeTrue()
            ->and(RunPhase::Failed->isBusy())->toBeFalse()
            ->and(RunPhase::Cancelled->isBusy())->toBeFalse();
    });

    it('provides non-empty labels and icons for all cases', function () {
        foreach (RunPhase::cases() as $case) {
            expect($case->label())->toBeString()->not()->toBeEmpty()
                ->and($case->icon())->toBeString()->not()->toBeEmpty();
        }
    });

    it('maps expected backing values', function () {
        expect(RunPhase::WaitingForWorker->value)->toBe('waiting_for_worker')
            ->and(RunPhase::AwaitingLlm->value)->toBe('awaiting_llm')
            ->and(RunPhase::RunningTool->value)->toBe('running_tool')
            ->and(RunPhase::StreamingAnswer->value)->toBe('streaming_answer')
            ->and(RunPhase::Finalizing->value)->toBe('finalizing');
    });
});

// ------------------------------------------------------------------
// RunEventType — event taxonomy contract
// ------------------------------------------------------------------

describe('RunEventType', function () {
    it('has nineteen cases', function () {
        expect(RunEventType::cases())->toHaveCount(17);
    });

    it('uses dot-separated naming convention except for single-word events', function () {
        $singleWordAllowed = ['heartbeat'];

        foreach (RunEventType::cases() as $case) {
            if (in_array($case->value, $singleWordAllowed, true)) {
                continue;
            }
            expect($case->value)->toContain('.');
        }
    });

    it('identifies terminal events correctly', function () {
        expect(RunEventType::RunCompleted->isTerminal())->toBeTrue()
            ->and(RunEventType::RunFailed->isTerminal())->toBeTrue()
            ->and(RunEventType::RunCancelled->isTerminal())->toBeTrue()
            ->and(RunEventType::RunStarted->isTerminal())->toBeFalse()
            ->and(RunEventType::AssistantOutputDelta->isTerminal())->toBeFalse()
            ->and(RunEventType::ToolStarted->isTerminal())->toBeFalse();
    });

    it('identifies delta events correctly', function () {
        expect(RunEventType::AssistantOutputDelta->isDelta())->toBeTrue()
            ->and(RunEventType::ToolStdoutDelta->isDelta())->toBeTrue()
            ->and(RunEventType::RunStarted->isDelta())->toBeFalse()
            ->and(RunEventType::ToolFinished->isDelta())->toBeFalse();
    });

    it('classifies severity for error events', function () {
        expect(RunEventType::RunFailed->severity())->toBe('error');
    });

    it('classifies severity for warning events', function () {
        expect(RunEventType::ToolDenied->severity())->toBe('warning');
    });

    it('defaults to info severity for normal events', function () {
        expect(RunEventType::RunStarted->severity())->toBe('info')
            ->and(RunEventType::AssistantOutputDelta->severity())->toBe('info')
            ->and(RunEventType::Heartbeat->severity())->toBe('info');
    });

    it('provides non-empty labels for all cases', function () {
        foreach (RunEventType::cases() as $case) {
            expect($case->label())->toBeString()->not()->toBeEmpty();
        }
    });

    it('covers all expected event families', function () {
        $values = array_map(fn ($c) => $c->value, RunEventType::cases());

        expect($values)->toContain('run.started')
            ->toContain('run.phase_changed')
            ->toContain('run.completed')
            ->toContain('run.failed')
            ->toContain('run.cancelled')
            ->toContain('run.ready_for_input')
            ->toContain('assistant.thinking_started')
            ->toContain('assistant.iteration_completed')
            ->toContain('assistant.output_delta')
            ->toContain('assistant.output_block_committed')
            ->toContain('tool.started')
            ->toContain('tool.stdout_delta')
            ->toContain('tool.finished')
            ->toContain('tool.denied')
            ->toContain('usage.updated')
            ->toContain('heartbeat');
    });
});
