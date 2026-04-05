<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Enums\TurnEventType;
use App\Modules\Core\AI\Enums\TurnPhase;
use App\Modules\Core\AI\Enums\TurnStatus;
use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Models\ChatTurnEvent;
use App\Modules\Core\AI\Services\TurnEventPublisher;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\UniqueConstraintViolationException;

const TURN_TEST_SESSION_ID = 'sess_turn_publisher_test';

/**
 * Create a ChatTurn in queued state for testing.
 */
function createTestTurn(?Employee $employee = null): ChatTurn
{
    $employee ??= Employee::query()->first();

    return ChatTurn::query()->create([
        'employee_id' => $employee->id,
        'session_id' => TURN_TEST_SESSION_ID,
        'status' => TurnStatus::Queued,
        'current_phase' => TurnPhase::WaitingForWorker,
    ]);
}

// ------------------------------------------------------------------
// ChatTurn model
// ------------------------------------------------------------------

describe('ChatTurn model', function () {
    it('creates a turn with ULID primary key', function () {
        $turn = createTestTurn();

        expect($turn->id)->toBeString()->toHaveLength(26)
            ->and($turn->status)->toBe(TurnStatus::Queued)
            ->and($turn->current_phase)->toBe(TurnPhase::WaitingForWorker)
            ->and($turn->fresh()->last_event_seq)->toBe(0);
    });

    it('transitions through valid state machine path', function () {
        $turn = createTestTurn();

        $turn->transitionTo(TurnStatus::Booting);
        expect($turn->status)->toBe(TurnStatus::Booting);

        $turn->transitionTo(TurnStatus::Running);
        expect($turn->status)->toBe(TurnStatus::Running);

        $turn->transitionTo(TurnStatus::Completed);
        expect($turn->status)->toBe(TurnStatus::Completed)
            ->and($turn->finished_at)->not()->toBeNull();
    });

    it('rejects invalid state transitions', function () {
        $turn = createTestTurn();

        $turn->transitionTo(TurnStatus::Booting);
        $turn->transitionTo(TurnStatus::Running);
        $turn->transitionTo(TurnStatus::Completed);

        expect(fn () => $turn->transitionTo(TurnStatus::Running))
            ->toThrow(InvalidArgumentException::class);
    });

    it('allocates sequential event numbers', function () {
        $turn = createTestTurn();

        expect($turn->nextSeq())->toBe(1)
            ->and($turn->nextSeq())->toBe(2)
            ->and($turn->nextSeq())->toBe(3);
    });

    it('updates phase and label', function () {
        $turn = createTestTurn();

        $turn->updatePhase(TurnPhase::Thinking, 'Analyzing prompt');

        expect($turn->current_phase)->toBe(TurnPhase::Thinking)
            ->and($turn->current_label)->toBe('Analyzing prompt');
    });

    it('reports busy for active states and not for terminal', function () {
        $turn = createTestTurn();
        expect($turn->isBusy())->toBeTrue();

        $turn->transitionTo(TurnStatus::Booting);
        $turn->transitionTo(TurnStatus::Running);
        $turn->transitionTo(TurnStatus::Completed);
        expect($turn->isBusy())->toBeFalse();
    });
});

// ------------------------------------------------------------------
// ChatTurnEvent model
// ------------------------------------------------------------------

describe('ChatTurnEvent model', function () {
    it('creates an event with proper casting', function () {
        $turn = createTestTurn();
        $event = ChatTurnEvent::query()->create([
            'turn_id' => $turn->id,
            'seq' => 1,
            'event_type' => TurnEventType::TurnStarted->value,
            'payload' => ['session_id' => TURN_TEST_SESSION_ID],
        ]);

        expect($event->event_type)->toBe(TurnEventType::TurnStarted)
            ->and($event->seq)->toBe(1)
            ->and($event->payload)->toBeArray()
            ->and($event->payload['session_id'])->toBe(TURN_TEST_SESSION_ID);
    });

    it('formats SSE payload correctly', function () {
        $turn = createTestTurn();
        $event = ChatTurnEvent::query()->create([
            'turn_id' => $turn->id,
            'seq' => 1,
            'event_type' => TurnEventType::Heartbeat->value,
            'payload' => ['elapsed_ms' => 5000],
        ]);

        $sse = $event->toSsePayload();

        expect($sse)->toHaveKeys(['turn_id', 'seq', 'event_type', 'payload', 'occurred_at'])
            ->and($sse['turn_id'])->toBe($turn->id)
            ->and($sse['seq'])->toBe(1)
            ->and($sse['event_type'])->toBe('heartbeat');
    });

    it('enforces unique turn_id + seq constraint', function () {
        $turn = createTestTurn();

        ChatTurnEvent::query()->create([
            'turn_id' => $turn->id,
            'seq' => 1,
            'event_type' => TurnEventType::TurnStarted->value,
        ]);

        expect(fn () => ChatTurnEvent::query()->create([
            'turn_id' => $turn->id,
            'seq' => 1,
            'event_type' => TurnEventType::Heartbeat->value,
        ]))->toThrow(UniqueConstraintViolationException::class);
    });
});

// ------------------------------------------------------------------
// TurnEventPublisher service
// ------------------------------------------------------------------

describe('TurnEventPublisher', function () {
    it('publishes turn.started and transitions to booting', function () {
        $publisher = app(TurnEventPublisher::class);
        $turn = createTestTurn();

        $event = $publisher->turnStarted($turn);

        expect($event->event_type)->toBe(TurnEventType::TurnStarted)
            ->and($event->seq)->toBe(1)
            ->and($turn->fresh()->status)->toBe(TurnStatus::Booting);
    });

    it('publishes phase changes', function () {
        $publisher = app(TurnEventPublisher::class);
        $turn = createTestTurn();
        $turn->transitionTo(TurnStatus::Booting);
        $turn->transitionTo(TurnStatus::Running);

        $event = $publisher->phaseChanged($turn, TurnPhase::Thinking, 'Reasoning about prompt');

        expect($event->event_type)->toBe(TurnEventType::TurnPhaseChanged)
            ->and($event->payload['phase'])->toBe('thinking')
            ->and($event->payload['label'])->toBe('Reasoning about prompt')
            ->and($turn->fresh()->current_phase)->toBe(TurnPhase::Thinking);
    });

    it('publishes tool lifecycle events', function () {
        $publisher = app(TurnEventPublisher::class);
        $turn = createTestTurn();
        $turn->transitionTo(TurnStatus::Booting);
        $turn->transitionTo(TurnStatus::Running);

        $started = $publisher->toolStarted($turn, 'bash', 'ls -la', 0);
        $finished = $publisher->toolFinished($turn, 'bash', 'success', '10 files', 150);

        expect($started->event_type)->toBe(TurnEventType::ToolStarted)
            ->and($started->payload['tool'])->toBe('bash')
            ->and($finished->event_type)->toBe(TurnEventType::ToolFinished)
            ->and($finished->payload['duration_ms'])->toBe(150);
    });

    it('publishes assistant output deltas', function () {
        $publisher = app(TurnEventPublisher::class);
        $turn = createTestTurn();
        $turn->transitionTo(TurnStatus::Booting);
        $turn->transitionTo(TurnStatus::Running);

        $delta = $publisher->outputDelta($turn, 'Hello, ');

        expect($delta->event_type)->toBe(TurnEventType::AssistantOutputDelta)
            ->and($delta->payload['delta'])->toBe('Hello, ');
    });

    it('publishes turn completed with ready_for_input', function () {
        $publisher = app(TurnEventPublisher::class);
        $turn = createTestTurn();
        $turn->transitionTo(TurnStatus::Booting);
        $turn->transitionTo(TurnStatus::Running);

        $event = $publisher->turnCompleted($turn, ['tokens' => 500]);

        expect($event->event_type)->toBe(TurnEventType::TurnCompleted)
            ->and($turn->fresh()->status)->toBe(TurnStatus::Completed);

        // Should also have emitted ready_for_input
        $events = ChatTurnEvent::query()
            ->where('turn_id', $turn->id)
            ->orderBy('seq')
            ->get();

        $lastEvent = $events->last();
        expect($lastEvent->event_type)->toBe(TurnEventType::TurnReadyForInput);
    });

    it('publishes turn failed with error details', function () {
        $publisher = app(TurnEventPublisher::class);
        $turn = createTestTurn();
        $turn->transitionTo(TurnStatus::Booting);
        $turn->transitionTo(TurnStatus::Running);

        $event = $publisher->turnFailed($turn, 'provider_error', 'API rate limited');

        expect($event->event_type)->toBe(TurnEventType::TurnFailed)
            ->and($event->payload['error_type'])->toBe('provider_error')
            ->and($turn->fresh()->status)->toBe(TurnStatus::Failed)
            ->and($turn->fresh()->current_phase)->toBe(TurnPhase::Failed);
    });

    it('publishes heartbeat with elapsed time', function () {
        $publisher = app(TurnEventPublisher::class);
        $turn = createTestTurn();

        $event = $publisher->heartbeat($turn, 5000);

        expect($event->event_type)->toBe(TurnEventType::Heartbeat)
            ->and($event->payload['elapsed_ms'])->toBe(5000);
    });

    it('publishes recovery lifecycle', function () {
        $publisher = app(TurnEventPublisher::class);
        $turn = createTestTurn();
        $turn->transitionTo(TurnStatus::Booting);
        $turn->transitionTo(TurnStatus::Running);

        $attempted = $publisher->recoveryAttempted($turn, 1, 'Provider timeout');
        $succeeded = $publisher->recoverySucceeded($turn, 1);

        expect($attempted->event_type)->toBe(TurnEventType::RecoveryAttempted)
            ->and($attempted->payload['attempt'])->toBe(1)
            ->and($succeeded->event_type)->toBe(TurnEventType::RecoverySucceeded);
    });

    it('maintains strictly increasing seq across all events', function () {
        $publisher = app(TurnEventPublisher::class);
        $turn = createTestTurn();

        $publisher->turnStarted($turn);
        $publisher->phaseChanged($turn, TurnPhase::Thinking);
        $publisher->toolStarted($turn, 'bash', 'echo hello');
        $publisher->toolFinished($turn, 'bash', 'success');
        $publisher->outputDelta($turn, 'Output');
        $publisher->heartbeat($turn, 1000);

        $seqs = ChatTurnEvent::query()
            ->where('turn_id', $turn->id)
            ->orderBy('seq')
            ->pluck('seq')
            ->toArray();

        expect($seqs)->toBe([1, 2, 3, 4, 5, 6]);
    });

    it('supports SSE resume via eventsAfter', function () {
        $publisher = app(TurnEventPublisher::class);
        $turn = createTestTurn();

        $publisher->turnStarted($turn);
        $publisher->phaseChanged($turn, TurnPhase::Thinking);
        $publisher->outputDelta($turn, 'Some text');
        $publisher->heartbeat($turn, 2000);

        $afterSeq2 = $turn->eventsAfter(2)->get();

        expect($afterSeq2)->toHaveCount(2)
            ->and($afterSeq2->first()->seq)->toBe(3)
            ->and($afterSeq2->last()->seq)->toBe(4);
    });
});
