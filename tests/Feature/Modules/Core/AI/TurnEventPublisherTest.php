<?php

use App\Modules\Core\AI\Enums\RunEventType;
use App\Modules\Core\AI\Enums\RunPhase;
use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Models\AiRunEvent;
use App\Modules\Core\AI\Services\RunEventPublisher;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\UniqueConstraintViolationException;

const TURN_TEST_SESSION_ID = 'sess_turn_publisher_test';
const TURN_LARGE_PAYLOAD_BYTES = 8_000;

/**
 * Create a AiRun in queued state for testing.
 */
function createTestTurn(?Employee $employee = null): AiRun
{
    $employee ??= Employee::query()->first();

    if ($employee === null) {
        $company = Company::factory()->create();
        $employee = Employee::factory()->create([
            'company_id' => $company->id,
            'status' => 'active',
        ]);
    }

    return AiRun::query()->create([
        'employee_id' => $employee->id,
        'session_id' => TURN_TEST_SESSION_ID,
        'status' => AiRunStatus::Queued,
        'current_phase' => RunPhase::WaitingForWorker,
    ]);
}

// ------------------------------------------------------------------
// AiRun model
// ------------------------------------------------------------------

describe('AiRun model', function () {
    it('creates a turn with ULID primary key', function () {
        $turn = createTestTurn();

        expect($turn->id)->toBeString()->toHaveLength(26)
            ->and($turn->status)->toBe(AiRunStatus::Queued)
            ->and($turn->current_phase)->toBe(RunPhase::WaitingForWorker)
            ->and($turn->fresh()->last_event_seq)->toBe(0);
    });

    it('transitions through valid state machine path', function () {
        $turn = createTestTurn();

        $turn->transitionTo(AiRunStatus::Booting);
        expect($turn->status)->toBe(AiRunStatus::Booting);

        $turn->transitionTo(AiRunStatus::Running);
        expect($turn->status)->toBe(AiRunStatus::Running);

        $turn->transitionTo(AiRunStatus::Succeeded);
        expect($turn->status)->toBe(AiRunStatus::Succeeded)
            ->and($turn->finished_at)->not()->toBeNull();
    });

    it('rejects invalid state transitions', function () {
        $turn = createTestTurn();

        $turn->transitionTo(AiRunStatus::Booting);
        $turn->transitionTo(AiRunStatus::Running);
        $turn->transitionTo(AiRunStatus::Succeeded);

        expect(fn () => $turn->transitionTo(AiRunStatus::Running))
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

        $turn->updatePhase(RunPhase::AwaitingLlm, 'Analyzing prompt');

        expect($turn->current_phase)->toBe(RunPhase::AwaitingLlm)
            ->and($turn->current_label)->toBe('Analyzing prompt');
    });

    it('reports busy for active states and not for terminal', function () {
        $turn = createTestTurn();
        expect($turn->isBusy())->toBeTrue();

        $turn->transitionTo(AiRunStatus::Booting);
        $turn->transitionTo(AiRunStatus::Running);
        $turn->transitionTo(AiRunStatus::Succeeded);
        expect($turn->isBusy())->toBeFalse();
    });
});

// ------------------------------------------------------------------
// AiRunEvent model
// ------------------------------------------------------------------

describe('AiRunEvent model', function () {
    it('creates an event with proper casting', function () {
        $turn = createTestTurn();
        $event = AiRunEvent::query()->create([
            'run_id' => $turn->id,
            'seq' => 1,
            'event_type' => RunEventType::RunStarted->value,
            'payload' => ['session_id' => TURN_TEST_SESSION_ID],
        ]);

        expect($event->event_type)->toBe(RunEventType::RunStarted)
            ->and($event->seq)->toBe(1)
            ->and($event->payload)->toBeArray()
            ->and($event->payload['session_id'])->toBe(TURN_TEST_SESSION_ID);
    });

    it('formats SSE payload correctly', function () {
        $turn = createTestTurn();
        $event = AiRunEvent::query()->create([
            'run_id' => $turn->id,
            'seq' => 1,
            'event_type' => RunEventType::Heartbeat->value,
            'payload' => ['elapsed_ms' => 5000],
        ]);

        $sse = $event->toSsePayload();

        expect($sse)->toHaveKeys(['run_id', 'seq', 'event_type', 'payload', 'occurred_at'])
            ->and($sse['run_id'])->toBe($turn->id)
            ->and($sse['seq'])->toBe(1)
            ->and($sse['event_type'])->toBe('heartbeat');
    });

    it('keeps small payloads inline', function () {
        $turn = createTestTurn();
        $event = AiRunEvent::query()->create([
            'run_id' => $turn->id,
            'seq' => 1,
            'event_type' => RunEventType::AssistantOutputDelta->value,
            'payload' => ['delta' => 'Hello, world'],
        ]);

        $payload = $event->toSsePayload();

        expect($payload)->toHaveKey('payload')
            ->and($payload['payload']['delta'])->toBe('Hello, world');
    });

    it('preserves oversized payloads in the canonical event envelope', function () {
        $turn = createTestTurn();
        $event = AiRunEvent::query()->create([
            'run_id' => $turn->id,
            'seq' => 1,
            'event_type' => RunEventType::AssistantOutputDelta->value,
            'payload' => ['delta' => str_repeat('A', TURN_LARGE_PAYLOAD_BYTES)],
        ]);

        $payload = $event->toSsePayload();

        expect($payload['seq'])->toBe(1)
            ->and($payload['event_type'])->toBe('assistant.output_delta')
            ->and($payload['payload']['delta'])->toHaveLength(TURN_LARGE_PAYLOAD_BYTES);
    });

    it('enforces unique run_id + seq constraint', function () {
        $turn = createTestTurn();

        AiRunEvent::query()->create([
            'run_id' => $turn->id,
            'seq' => 1,
            'event_type' => RunEventType::RunStarted->value,
        ]);

        expect(fn () => AiRunEvent::query()->create([
            'run_id' => $turn->id,
            'seq' => 1,
            'event_type' => RunEventType::Heartbeat->value,
        ]))->toThrow(UniqueConstraintViolationException::class);
    });
});

// ------------------------------------------------------------------
// RunEventPublisher service
// ------------------------------------------------------------------

describe('RunEventPublisher', function () {
    it('publishes run.started and transitions to booting', function () {
        $publisher = app(RunEventPublisher::class);
        $turn = createTestTurn();

        $event = $publisher->turnStarted($turn);

        expect($event->event_type)->toBe(RunEventType::RunStarted)
            ->and($event->seq)->toBe(1)
            ->and($turn->fresh()->status)->toBe(AiRunStatus::Booting);
    });

    it('publishes phase changes', function () {
        $publisher = app(RunEventPublisher::class);
        $turn = createTestTurn();
        $turn->transitionTo(AiRunStatus::Booting);
        $turn->transitionTo(AiRunStatus::Running);

        $event = $publisher->phaseChanged($turn, RunPhase::AwaitingLlm, 'Reasoning about prompt');

        expect($event->event_type)->toBe(RunEventType::RunPhaseChanged)
            ->and($event->payload['phase'])->toBe(RunPhase::AwaitingLlm->value)
            ->and($event->payload['label'])->toBe('Reasoning about prompt')
            ->and($turn->fresh()->current_phase)->toBe(RunPhase::AwaitingLlm);
    });

    it('publishes tool lifecycle events', function () {
        $publisher = app(RunEventPublisher::class);
        $turn = createTestTurn();
        $turn->transitionTo(AiRunStatus::Booting);
        $turn->transitionTo(AiRunStatus::Running);

        $started = $publisher->toolStarted($turn, 'bash', 'ls -la', 0);
        $finished = $publisher->toolFinished($turn, 'bash', 'success', '10 files', 150);

        expect($started->event_type)->toBe(RunEventType::ToolStarted)
            ->and($started->payload['tool'])->toBe('bash')
            ->and($finished->event_type)->toBe(RunEventType::ToolFinished)
            ->and($finished->payload['duration_ms'])->toBe(150);
    });

    it('publishes assistant output deltas', function () {
        $publisher = app(RunEventPublisher::class);
        $turn = createTestTurn();
        $turn->transitionTo(AiRunStatus::Booting);
        $turn->transitionTo(AiRunStatus::Running);

        $delta = $publisher->outputDelta($turn, 'Hello, ');

        expect($delta->event_type)->toBe(RunEventType::AssistantOutputDelta)
            ->and($delta->payload['delta'])->toBe('Hello, ');
    });

    it('publishes turn completed with ready_for_input', function () {
        $publisher = app(RunEventPublisher::class);
        $turn = createTestTurn();
        $turn->transitionTo(AiRunStatus::Booting);
        $turn->transitionTo(AiRunStatus::Running);

        $event = $publisher->turnCompleted($turn, ['tokens' => 500]);

        expect($event->event_type)->toBe(RunEventType::RunCompleted)
            ->and($turn->fresh()->status)->toBe(AiRunStatus::Succeeded);

        // Should also have emitted ready_for_input
        $events = AiRunEvent::query()
            ->where('run_id', $turn->id)
            ->orderBy('seq')
            ->get();

        $lastEvent = $events->last();
        expect($lastEvent->event_type)->toBe(RunEventType::RunReadyForInput);
    });

    it('publishes turn failed with error details', function () {
        $publisher = app(RunEventPublisher::class);
        $turn = createTestTurn();
        $turn->transitionTo(AiRunStatus::Booting);
        $turn->transitionTo(AiRunStatus::Running);

        $event = $publisher->turnFailed($turn, 'provider_error', 'API rate limited');

        expect($event->event_type)->toBe(RunEventType::RunFailed)
            ->and($event->payload['error_type'])->toBe('provider_error')
            ->and($turn->fresh()->status)->toBe(AiRunStatus::Failed)
            ->and($turn->fresh()->current_phase)->toBe(RunPhase::Failed);
    });

    it('publishes heartbeat with elapsed time', function () {
        $publisher = app(RunEventPublisher::class);
        $turn = createTestTurn();

        $event = $publisher->heartbeat($turn, 5000);

        expect($event->event_type)->toBe(RunEventType::Heartbeat)
            ->and($event->payload['elapsed_ms'])->toBe(5000);
    });

    it('maintains strictly increasing seq across all events', function () {
        $publisher = app(RunEventPublisher::class);
        $turn = createTestTurn();

        $publisher->turnStarted($turn);
        $publisher->phaseChanged($turn, RunPhase::AwaitingLlm);
        $publisher->toolStarted($turn, 'bash', 'echo hello');
        $publisher->toolFinished($turn, 'bash', 'success');
        $publisher->outputDelta($turn, 'Output');
        $publisher->heartbeat($turn, 1000);

        $seqs = AiRunEvent::query()
            ->where('run_id', $turn->id)
            ->orderBy('seq')
            ->pluck('seq')
            ->toArray();

        expect($seqs)->toBe([1, 2, 3, 4, 5, 6]);
    });

    it('supports SSE resume via eventsAfter', function () {
        $publisher = app(RunEventPublisher::class);
        $turn = createTestTurn();

        $publisher->turnStarted($turn);
        $publisher->phaseChanged($turn, RunPhase::AwaitingLlm);
        $publisher->outputDelta($turn, 'Some text');
        $publisher->heartbeat($turn, 2000);

        $afterSeq2 = $turn->eventsAfter(2)->get();

        expect($afterSeq2)->toHaveCount(2)
            ->and($afterSeq2->first()->seq)->toBe(3)
            ->and($afterSeq2->last()->seq)->toBe(4);
    });

    it('persists oversized live payloads without transport-specific fallback', function () {
        $publisher = app(RunEventPublisher::class);
        $turn = createTestTurn();
        $turn->transitionTo(AiRunStatus::Booting);
        $turn->transitionTo(AiRunStatus::Running);

        $event = $publisher->outputDelta($turn, str_repeat('A', TURN_LARGE_PAYLOAD_BYTES));

        expect($event->payload['delta'])->toHaveLength(TURN_LARGE_PAYLOAD_BYTES)
            ->and($turn->fresh()->last_event_seq)->toBe(1);
    });
});
