<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Enums\TurnEventType;
use App\Modules\Core\AI\Enums\TurnPhase;
use App\Modules\Core\AI\Enums\TurnStatus;
use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Models\ChatTurnEvent;
use App\Modules\Core\AI\Services\TurnStreamBridge;
use App\Modules\Core\Employee\Models\Employee;

final class TurnStreamBridgeTestStreamFailure extends RuntimeException {}

const BRIDGE_TEST_SESSION = 'sess_bridge_test';
const BRIDGE_TEST_RUN_ID = 'run_bridge_test1';
const BRIDGE_TEST_LIST_ARGS = 'ls -la';

/**
 * Create a ChatTurn in queued state for bridge testing.
 */
function createBridgeTurn(): ChatTurn
{
    return ChatTurn::query()->create([
        'employee_id' => Employee::query()->first()->id,
        'session_id' => BRIDGE_TEST_SESSION,
        'status' => TurnStatus::Queued,
        'current_phase' => TurnPhase::WaitingForWorker,
    ]);
}

/**
 * Build a generator that yields the given runtime events.
 *
 * @param  list<array{event: string, data: array<string, mixed>}>  $events
 * @return Generator<int, array{event: string, data: array<string, mixed>}>
 */
function runtimeStream(array $events): Generator
{
    foreach ($events as $event) {
        yield $event;
    }
}

/**
 * Collect turn event types for a turn in seq order.
 *
 * @return list<TurnEventType>
 */
function turnEventTypes(ChatTurn $turn): array
{
    return ChatTurnEvent::query()
        ->where('turn_id', $turn->id)
        ->orderBy('seq')
        ->get()
        ->pluck('event_type')
        ->all();
}

// ------------------------------------------------------------------
// TurnStreamBridge — happy path
// ------------------------------------------------------------------

describe('TurnStreamBridge', function () {
    it('yields turn event SSE payloads instead of raw runtime events', function () {
        $turn = createBridgeTurn();
        $bridge = app(TurnStreamBridge::class);

        $input = [
            ['event' => 'status', 'data' => ['phase' => TurnPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'delta', 'data' => ['text' => 'Hi']],
            ['event' => 'done', 'data' => ['content' => 'Hi there', 'run_id' => BRIDGE_TEST_RUN_ID, 'meta' => []]],
        ];

        $output = iterator_to_array($bridge->wrap($turn, runtimeStream($input)), false);

        // Each yielded payload must be an SSE payload with turn_id, seq, event_type
        expect(count($output))->toBeGreaterThan(3);

        foreach ($output as $payload) {
            expect($payload)->toHaveKey('turn_id')
                ->and($payload)->toHaveKey('seq')
                ->and($payload)->toHaveKey('event_type')
                ->and($payload['turn_id'])->toBe($turn->id);
        }

        // First event should be turn.started
        expect($output[0]['event_type'])->toBe('turn.started');

        // Should contain the key event types
        $types = array_column($output, 'event_type');
        expect($types)->toContain('turn.started')
            ->and($types)->toContain('run.started')
            ->and($types)->toContain('assistant.output_delta')
            ->and($types)->toContain('turn.completed')
            ->and($types)->toContain('turn.ready_for_input');
    });

    it('transitions turn through Queued → Booting → Running → Completed', function () {
        $turn = createBridgeTurn();
        $bridge = app(TurnStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => TurnPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'done', 'data' => ['content' => 'Done', 'run_id' => BRIDGE_TEST_RUN_ID, 'meta' => []]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $turn->refresh();
        expect($turn->status)->toBe(TurnStatus::Completed)
            ->and($turn->started_at)->not()->toBeNull()
            ->and($turn->finished_at)->not()->toBeNull();
    });

    it('emits turn.started, run.started, and turn.completed events', function () {
        $turn = createBridgeTurn();
        $bridge = app(TurnStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => TurnPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'done', 'data' => ['content' => 'Result', 'run_id' => BRIDGE_TEST_RUN_ID, 'meta' => []]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $types = turnEventTypes($turn);

        expect($types)->toContain(TurnEventType::TurnStarted)
            ->and($types)->toContain(TurnEventType::RunStarted)
            ->and($types)->toContain(TurnEventType::TurnCompleted)
            ->and($types)->toContain(TurnEventType::TurnReadyForInput);
    });

    it('maps awaiting_llm status to phase change and thinking event', function () {
        $turn = createBridgeTurn();
        $bridge = app(TurnStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => TurnPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'done', 'data' => ['content' => 'Ok', 'run_id' => BRIDGE_TEST_RUN_ID, 'meta' => []]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $types = turnEventTypes($turn);

        expect($types)->toContain(TurnEventType::TurnPhaseChanged)
            ->and($types)->toContain(TurnEventType::AssistantThinkingStarted);
    });
});

describe('TurnStreamBridge tool and streaming events', function () {
    it('maps tool_started and tool_finished with phase transitions', function () {
        $turn = createBridgeTurn();
        $bridge = app(TurnStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => TurnPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'status', 'data' => [
                'phase' => 'tool_started',
                'tool' => 'bash',
                'args_summary' => BRIDGE_TEST_LIST_ARGS,
                'tool_call_index' => 0,
                'run_id' => BRIDGE_TEST_RUN_ID,
            ]],
            ['event' => 'status', 'data' => [
                'phase' => 'tool_finished',
                'tool' => 'bash',
                'status' => 'success',
                'result_preview' => '10 files',
                'duration_ms' => 150,
                'run_id' => BRIDGE_TEST_RUN_ID,
            ]],
            ['event' => 'done', 'data' => ['content' => 'Listed files', 'run_id' => BRIDGE_TEST_RUN_ID, 'meta' => []]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $types = turnEventTypes($turn);

        expect($types)->toContain(TurnEventType::ToolStarted)
            ->and($types)->toContain(TurnEventType::ToolFinished)
            ->and($types)->toContain(TurnEventType::Heartbeat);

        // Tool started should have set phase to running_tool
        $toolStartedEvent = ChatTurnEvent::query()
            ->where('turn_id', $turn->id)
            ->whereJsonContains('payload->tool', 'bash')
            ->where('event_type', TurnEventType::ToolStarted->value)
            ->first();

        expect($toolStartedEvent)->not()->toBeNull()
            ->and($toolStartedEvent->payload['args_summary'])->toBe(BRIDGE_TEST_LIST_ARGS);
    });

    it('maps iteration_completed status to a durable assistant iteration event', function () {
        $turn = createBridgeTurn();
        $bridge = app(TurnStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => TurnPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'status', 'data' => [
                'phase' => 'iteration_completed',
                'finish_reason' => 'tool_calls',
                'iteration' => 0,
                'tool_call_count' => 1,
                'run_id' => BRIDGE_TEST_RUN_ID,
            ]],
            ['event' => 'status', 'data' => [
                'phase' => 'tool_started',
                'tool' => 'bash',
                'args_summary' => BRIDGE_TEST_LIST_ARGS,
                'tool_call_index' => 0,
                'run_id' => BRIDGE_TEST_RUN_ID,
            ]],
            ['event' => 'done', 'data' => ['content' => 'Listed files', 'run_id' => BRIDGE_TEST_RUN_ID, 'meta' => []]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $iterationEvent = ChatTurnEvent::query()
            ->where('turn_id', $turn->id)
            ->where('event_type', TurnEventType::AssistantIterationCompleted->value)
            ->first();

        expect($iterationEvent)->not()->toBeNull()
            ->and($iterationEvent->payload['finish_reason'])->toBe('tool_calls')
            ->and($iterationEvent->payload['iteration'])->toBe(0)
            ->and($iterationEvent->payload['tool_call_count'])->toBe(1);

        $types = turnEventTypes($turn);
        $normalizedTypes = array_map(
            static fn ($type) => $type instanceof TurnEventType ? $type->value : $type,
            $types,
        );
        $iterationIndex = array_search(TurnEventType::AssistantIterationCompleted->value, $normalizedTypes, true);
        $toolStartedIndex = array_search(TurnEventType::ToolStarted->value, $normalizedTypes, true);

        expect($iterationIndex)->toBeInt()
            ->and($toolStartedIndex)->toBeInt()
            ->and($iterationIndex)->toBeLessThan($toolStartedIndex);
    });
});

describe('TurnStreamBridge output and sequencing events', function () {

    it('maps tool_denied to a turn event', function () {
        $turn = createBridgeTurn();
        $bridge = app(TurnStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => TurnPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'status', 'data' => [
                'phase' => 'tool_denied',
                'tool' => 'dangerous_tool',
                'reason' => 'blocked by policy',
                'source' => 'hook',
                'run_id' => BRIDGE_TEST_RUN_ID,
            ]],
            ['event' => 'done', 'data' => ['content' => 'Ok', 'run_id' => BRIDGE_TEST_RUN_ID, 'meta' => []]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $types = turnEventTypes($turn);
        expect($types)->toContain(TurnEventType::ToolDenied);

        $denied = ChatTurnEvent::query()
            ->where('turn_id', $turn->id)
            ->where('event_type', TurnEventType::ToolDenied->value)
            ->first();

        expect($denied->payload['tool'])->toBe('dangerous_tool')
            ->and($denied->payload['reason'])->toBe('blocked by policy');
    });

    it('maps delta events to output deltas with streaming phase', function () {
        $turn = createBridgeTurn();
        $bridge = app(TurnStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => TurnPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'delta', 'data' => ['text' => 'Hello ']],
            ['event' => 'delta', 'data' => ['text' => 'world']],
            ['event' => 'done', 'data' => ['content' => 'Hello world', 'run_id' => BRIDGE_TEST_RUN_ID, 'meta' => []]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $types = turnEventTypes($turn);
        $deltaCount = collect($types)->filter(fn ($t) => $t === TurnEventType::AssistantOutputDelta)->count();

        expect($deltaCount)->toBe(2);

        // Phase should have been set to streaming_answer
        $phaseEvents = ChatTurnEvent::query()
            ->where('turn_id', $turn->id)
            ->where('event_type', TurnEventType::TurnPhaseChanged->value)
            ->whereJsonContains('payload->phase', 'streaming_answer')
            ->get();

        expect($phaseEvents)->toHaveCount(1);
    });

    it('emits output_block_committed on done with content', function () {
        $turn = createBridgeTurn();
        $bridge = app(TurnStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => TurnPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'done', 'data' => ['content' => 'Final answer', 'run_id' => BRIDGE_TEST_RUN_ID, 'meta' => []]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $block = ChatTurnEvent::query()
            ->where('turn_id', $turn->id)
            ->where('event_type', TurnEventType::AssistantOutputBlockCommitted->value)
            ->first();

        expect($block)->not()->toBeNull()
            ->and($block->payload['block_type'])->toBe('markdown')
            ->and($block->payload['content'])->toBe('Final answer');
    });

    it('emits usage_updated when done includes tokens', function () {
        $turn = createBridgeTurn();
        $bridge = app(TurnStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => TurnPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'done', 'data' => [
                'content' => 'Reply',
                'run_id' => BRIDGE_TEST_RUN_ID,
                'meta' => ['tokens' => ['prompt' => 100, 'completion' => 50]],
            ]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $usage = ChatTurnEvent::query()
            ->where('turn_id', $turn->id)
            ->where('event_type', TurnEventType::UsageUpdated->value)
            ->first();

        expect($usage)->not()->toBeNull()
            ->and($usage->payload['prompt'])->toBe(100)
            ->and($usage->payload['completion'])->toBe(50);
    });

    it('maintains strictly increasing seq across all events', function () {
        $turn = createBridgeTurn();
        $bridge = app(TurnStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => TurnPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'status', 'data' => [
                'phase' => 'tool_started', 'tool' => 'bash',
                'args_summary' => 'pwd', 'tool_call_index' => 0,
                'run_id' => BRIDGE_TEST_RUN_ID,
            ]],
            ['event' => 'status', 'data' => [
                'phase' => 'tool_finished', 'tool' => 'bash',
                'status' => 'success', 'result_preview' => '/home',
                'duration_ms' => 50, 'run_id' => BRIDGE_TEST_RUN_ID,
            ]],
            ['event' => 'delta', 'data' => ['text' => 'Output']],
            ['event' => 'done', 'data' => ['content' => 'Output', 'run_id' => BRIDGE_TEST_RUN_ID, 'meta' => []]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $seqs = ChatTurnEvent::query()
            ->where('turn_id', $turn->id)
            ->orderBy('seq')
            ->pluck('seq')
            ->toArray();

        // Verify strictly increasing with no gaps
        for ($i = 1; $i < count($seqs); $i++) {
            expect($seqs[$i])->toBe($seqs[$i - 1] + 1);
        }

        expect($seqs[0])->toBe(1);
    });
});

// ------------------------------------------------------------------
// TurnStreamBridge — error handling
// ------------------------------------------------------------------

describe('TurnStreamBridge error handling', function () {
    it('maps runtime error events to turn failure', function () {
        $turn = createBridgeTurn();
        $bridge = app(TurnStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'error', 'data' => [
                'message' => 'API rate limited',
                'run_id' => BRIDGE_TEST_RUN_ID,
                'meta' => ['error_type' => 'rate_limit'],
            ]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $turn->refresh();
        expect($turn->status)->toBe(TurnStatus::Failed)
            ->and($turn->current_phase)->toBe(TurnPhase::Failed);

        $failed = ChatTurnEvent::query()
            ->where('turn_id', $turn->id)
            ->where('event_type', TurnEventType::TurnFailed->value)
            ->first();

        expect($failed)->not()->toBeNull()
            ->and($failed->payload['error_type'])->toBe('rate_limit')
            ->and($failed->payload['message'])->toBe('API rate limited');
    });

    it('fails the turn if stream ends without terminal event', function () {
        $turn = createBridgeTurn();
        $bridge = app(TurnStreamBridge::class);

        // Stream that produces events but no done/error
        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => TurnPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $turn->refresh();
        expect($turn->status)->toBe(TurnStatus::Failed);

        $failed = ChatTurnEvent::query()
            ->where('turn_id', $turn->id)
            ->where('event_type', TurnEventType::TurnFailed->value)
            ->first();

        expect($failed)->not()->toBeNull()
            ->and($failed->payload['error_type'])->toBe('unexpected_end');
    });

    it('fails the turn and rethrows when runtime throws an exception', function () {
        $turn = createBridgeTurn();
        $bridge = app(TurnStreamBridge::class);

        $throwingStream = (function () {
            yield ['event' => 'status', 'data' => ['phase' => TurnPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]];
            throw new TurnStreamBridgeTestStreamFailure('LLM connection lost');
        })();

        expect(fn () => iterator_to_array($bridge->wrap($turn, $throwingStream)))
            ->toThrow(TurnStreamBridgeTestStreamFailure::class, 'LLM connection lost');

        $turn->refresh();
        expect($turn->status)->toBe(TurnStatus::Failed);

        $failed = ChatTurnEvent::query()
            ->where('turn_id', $turn->id)
            ->where('event_type', TurnEventType::TurnFailed->value)
            ->first();

        expect($failed)->not()->toBeNull()
            ->and($failed->payload['error_type'])->toBe('runtime_exception');
    });

    it('handles error without run_id gracefully', function () {
        $turn = createBridgeTurn();
        $bridge = app(TurnStreamBridge::class);

        // Error before a run_id is ever seen — turn stays in Booting
        $stream = runtimeStream([
            ['event' => 'error', 'data' => [
                'message' => 'No config available',
                'meta' => ['error_type' => 'config_error'],
            ]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $turn->refresh();
        expect($turn->status)->toBe(TurnStatus::Failed);
    });

    it('handles empty stream by failing the turn', function () {
        $turn = createBridgeTurn();
        $bridge = app(TurnStreamBridge::class);

        $stream = runtimeStream([]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $turn->refresh();
        expect($turn->status)->toBe(TurnStatus::Failed);
    });
});

// ------------------------------------------------------------------
// TurnStreamBridge — recovery events
// ------------------------------------------------------------------

describe('TurnStreamBridge recovery events', function () {
    it('maps recovery_attempted to RecoveryAttempted turn event', function () {
        $turn = createBridgeTurn();
        $bridge = app(TurnStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => [
                'phase' => 'recovery_attempted',
                'attempt' => 1,
                'reason' => 'provider_fallback: API key invalid',
                'run_id' => BRIDGE_TEST_RUN_ID,
            ]],
            ['event' => 'status', 'data' => ['phase' => TurnPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'done', 'data' => ['content' => 'Ok', 'run_id' => BRIDGE_TEST_RUN_ID, 'meta' => []]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $types = turnEventTypes($turn);
        expect($types)->toContain(TurnEventType::RecoveryAttempted);

        $recovery = ChatTurnEvent::query()
            ->where('turn_id', $turn->id)
            ->where('event_type', TurnEventType::RecoveryAttempted->value)
            ->first();

        expect($recovery)->not()->toBeNull()
            ->and($recovery->payload['attempt'])->toBe(1)
            ->and($recovery->payload['reason'])->toBe('provider_fallback: API key invalid');
    });

    it('maps recovery_succeeded to RecoverySucceeded turn event', function () {
        $turn = createBridgeTurn();
        $bridge = app(TurnStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => [
                'phase' => 'recovery_attempted',
                'attempt' => 1,
                'reason' => 'retry: timeout',
                'run_id' => BRIDGE_TEST_RUN_ID,
            ]],
            ['event' => 'status', 'data' => [
                'phase' => 'recovery_succeeded',
                'attempt' => 1,
                'reason' => 'retry',
                'run_id' => BRIDGE_TEST_RUN_ID,
            ]],
            ['event' => 'status', 'data' => ['phase' => TurnPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'done', 'data' => ['content' => 'Done', 'run_id' => BRIDGE_TEST_RUN_ID, 'meta' => []]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $types = turnEventTypes($turn);
        expect($types)->toContain(TurnEventType::RecoveryAttempted)
            ->and($types)->toContain(TurnEventType::RecoverySucceeded);
    });

    it('captures tool result_length and error_payload in turn events', function () {
        $turn = createBridgeTurn();
        $bridge = app(TurnStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => TurnPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'status', 'data' => [
                'phase' => 'tool_started',
                'tool' => 'bash',
                'args_summary' => 'ls',
                'tool_call_index' => 0,
                'run_id' => BRIDGE_TEST_RUN_ID,
            ]],
            ['event' => 'status', 'data' => [
                'phase' => 'tool_finished',
                'tool' => 'bash',
                'status' => 'error',
                'result_preview' => 'Permission denied',
                'result_length' => 42,
                'duration_ms' => 100,
                'error_payload' => ['code' => 'permission_denied', 'message' => 'Not allowed'],
                'run_id' => BRIDGE_TEST_RUN_ID,
            ]],
            ['event' => 'done', 'data' => ['content' => 'Failed', 'run_id' => BRIDGE_TEST_RUN_ID, 'meta' => []]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $toolFinished = ChatTurnEvent::query()
            ->where('turn_id', $turn->id)
            ->where('event_type', TurnEventType::ToolFinished->value)
            ->first();

        expect($toolFinished)->not()->toBeNull()
            ->and($toolFinished->payload['result_length'])->toBe(42)
            ->and($toolFinished->payload['error_payload'])->toBe([
                'code' => 'permission_denied',
                'message' => 'Not allowed',
            ]);
    });
});
