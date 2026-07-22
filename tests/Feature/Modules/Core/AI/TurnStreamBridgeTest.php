<?php

use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Enums\RunEventType;
use App\Modules\Core\AI\Enums\RunPhase;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Models\AiRunEvent;
use App\Modules\Core\AI\Services\RunStreamBridge;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;

final class RunStreamBridgeTestStreamFailure extends RuntimeException {}

const BRIDGE_TEST_SESSION = 'sess_bridge_test';
const BRIDGE_TEST_RUN_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAY';
const BRIDGE_TEST_LIST_ARGS = 'ls -la';

/**
 * Create a AiRun in queued state for bridge testing.
 */
function createBridgeTurn(): AiRun
{
    $employee = Employee::query()->first();

    if ($employee === null) {
        $company = Company::factory()->create();
        $employee = Employee::factory()->create([
            'company_id' => $company->id,
            'status' => 'active',
        ]);
    }

    return AiRun::query()->create([
        'id' => BRIDGE_TEST_RUN_ID,
        'employee_id' => $employee->id,
        'source' => 'chat',
        'execution_mode' => 'interactive',
        'session_id' => BRIDGE_TEST_SESSION,
        'status' => AiRunStatus::Queued,
        'current_phase' => RunPhase::WaitingForWorker,
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
 * @return list<RunEventType>
 */
function turnEventTypes(AiRun $turn): array
{
    return AiRunEvent::query()
        ->where('run_id', $turn->id)
        ->orderBy('seq')
        ->get()
        ->pluck('event_type')
        ->all();
}

// ------------------------------------------------------------------
// RunStreamBridge — happy path
// ------------------------------------------------------------------

describe('RunStreamBridge', function () {
    it('yields turn event SSE payloads instead of raw runtime events', function () {
        $turn = createBridgeTurn();
        $bridge = app(RunStreamBridge::class);

        $input = [
            ['event' => 'status', 'data' => ['phase' => RunPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'delta', 'data' => ['text' => 'Hi']],
            ['event' => 'done', 'data' => ['content' => 'Hi there', 'run_id' => BRIDGE_TEST_RUN_ID, 'meta' => []]],
        ];

        $output = iterator_to_array($bridge->wrap($turn, runtimeStream($input)), false);

        // Each yielded payload must be an SSE payload with run_id, seq, event_type
        expect(count($output))->toBeGreaterThan(3);

        foreach ($output as $payload) {
            expect($payload)->toHaveKey('run_id')
                ->and($payload)->toHaveKey('seq')
                ->and($payload)->toHaveKey('event_type')
                ->and($payload['run_id'])->toBe($turn->id);
        }

        // First event should be run.started
        expect($output[0]['event_type'])->toBe('run.started');

        // Should contain the key event types
        $types = array_column($output, 'event_type');
        expect($types)->toContain('run.started')
            ->and($types)->toContain('run.started')
            ->and($types)->toContain('assistant.output_delta')
            ->and($types)->toContain('run.completed')
            ->and($types)->toContain('run.ready_for_input');
    });

    it('transitions turn through Queued → Booting → Running → Completed', function () {
        $turn = createBridgeTurn();
        $bridge = app(RunStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => RunPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'done', 'data' => ['content' => 'Done', 'run_id' => BRIDGE_TEST_RUN_ID, 'meta' => []]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $turn->refresh();
        expect($turn->status)->toBe(AiRunStatus::Succeeded)
            ->and($turn->started_at)->not()->toBeNull()
            ->and($turn->finished_at)->not()->toBeNull();
    });

    it('emits run.started, run.started, and run.completed events', function () {
        $turn = createBridgeTurn();
        $bridge = app(RunStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => RunPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'done', 'data' => ['content' => 'Result', 'run_id' => BRIDGE_TEST_RUN_ID, 'meta' => []]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $types = turnEventTypes($turn);

        expect($types)->toContain(RunEventType::RunStarted)
            ->and($types)->toContain(RunEventType::RunStarted)
            ->and($types)->toContain(RunEventType::RunCompleted)
            ->and($types)->toContain(RunEventType::RunReadyForInput);
    });

    it('maps awaiting_llm status to phase change and thinking event', function () {
        $turn = createBridgeTurn();
        $bridge = app(RunStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => RunPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'done', 'data' => ['content' => 'Ok', 'run_id' => BRIDGE_TEST_RUN_ID, 'meta' => []]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $types = turnEventTypes($turn);

        expect($types)->toContain(RunEventType::RunPhaseChanged)
            ->and($types)->toContain(RunEventType::AssistantThinkingStarted);
    });

    it('maps runtime cancellation status to a terminal cancelled turn event', function () {
        $turn = createBridgeTurn();
        $bridge = app(RunStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => RunPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'status', 'data' => ['phase' => RunPhase::Cancelled->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
        ]);

        $output = iterator_to_array($bridge->wrap($turn, $stream), false);

        $turn->refresh();

        expect(array_column($output, 'event_type'))->toContain('run.cancelled')
            ->and($turn->status)->toBe(AiRunStatus::Cancelled)
            ->and($turn->current_phase)->toBe(RunPhase::Cancelled);
    });
});

describe('RunStreamBridge tool and streaming events', function () {
    it('maps tool_started and tool_finished with phase transitions', function () {
        $turn = createBridgeTurn();
        $bridge = app(RunStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => RunPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
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
                'tool_call_index' => 0,
                'status' => 'success',
                'result_preview' => '10 files',
                'duration_ms' => 150,
                'run_id' => BRIDGE_TEST_RUN_ID,
            ]],
            ['event' => 'done', 'data' => ['content' => 'Listed files', 'run_id' => BRIDGE_TEST_RUN_ID, 'meta' => []]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $types = turnEventTypes($turn);

        expect($types)->toContain(RunEventType::ToolStarted)
            ->and($types)->toContain(RunEventType::ToolFinished)
            ->and($types)->toContain(RunEventType::Heartbeat);

        $toolFinished = AiRunEvent::query()
            ->where('run_id', $turn->id)
            ->where('event_type', RunEventType::ToolFinished->value)
            ->firstOrFail();

        expect($toolFinished->payload['tool_call_index'])->toBe(0);

        // Tool started should have set phase to running_tool
        $toolStartedEvent = AiRunEvent::query()
            ->where('run_id', $turn->id)
            ->whereJsonContains('payload->tool', 'bash')
            ->where('event_type', RunEventType::ToolStarted->value)
            ->first();

        expect($toolStartedEvent)->not()->toBeNull()
            ->and($toolStartedEvent->payload['args_summary'])->toBe(BRIDGE_TEST_LIST_ARGS);
    });

    it('maps iteration_completed status to a durable assistant iteration event', function () {
        $turn = createBridgeTurn();
        $bridge = app(RunStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => RunPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'status', 'data' => [
                'phase' => 'iteration_completed',
                'finish_reason' => 'tool_calls',
                'iteration' => 0,
                'tool_call_count' => 1,
                'tool_round' => 1,
                'max_tool_rounds' => 100,
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

        $iterationEvent = AiRunEvent::query()
            ->where('run_id', $turn->id)
            ->where('event_type', RunEventType::AssistantIterationCompleted->value)
            ->first();

        expect($iterationEvent)->not()->toBeNull()
            ->and($iterationEvent->payload['finish_reason'])->toBe('tool_calls')
            ->and($iterationEvent->payload['iteration'])->toBe(0)
            ->and($iterationEvent->payload['tool_call_count'])->toBe(1)
            ->and($iterationEvent->payload['tool_round'])->toBe(1)
            ->and($iterationEvent->payload['max_tool_rounds'])->toBe(100);

        $types = turnEventTypes($turn);
        $normalizedTypes = array_map(
            static fn ($type) => $type instanceof RunEventType ? $type->value : $type,
            $types,
        );
        $iterationIndex = array_search(RunEventType::AssistantIterationCompleted->value, $normalizedTypes, true);
        $toolStartedIndex = array_search(RunEventType::ToolStarted->value, $normalizedTypes, true);

        expect($iterationIndex)->toBeInt()
            ->and($toolStartedIndex)->toBeInt()
            ->and($iterationIndex)->toBeLessThan($toolStartedIndex);
    });

    it('maps the tool-round threshold warning to a durable warning event', function () {
        $turn = createBridgeTurn();
        $bridge = app(RunStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => RunPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'status', 'data' => [
                'phase' => 'tool_round_warning',
                'tool_round_count' => 80,
                'max_tool_rounds' => 100,
                'run_id' => BRIDGE_TEST_RUN_ID,
            ]],
            ['event' => 'done', 'data' => [
                'content' => 'Finished',
                'run_id' => BRIDGE_TEST_RUN_ID,
                'meta' => [
                    'tool_round_count' => 80,
                    'tool_call_count' => 96,
                    'max_tool_rounds' => 100,
                ],
            ]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $warning = AiRunEvent::query()
            ->where('run_id', $turn->id)
            ->where('event_type', RunEventType::AssistantToolRoundWarning->value)
            ->firstOrFail();
        $completed = AiRunEvent::query()
            ->where('run_id', $turn->id)
            ->where('event_type', RunEventType::RunCompleted->value)
            ->firstOrFail();

        expect($warning->payload)->toMatchArray([
            'tool_round_count' => 80,
            'max_tool_rounds' => 100,
            'percent_used' => 80,
        ])->and($completed->payload)->toMatchArray([
            'tool_round_count' => 80,
            'tool_call_count' => 96,
            'max_tool_rounds' => 100,
        ]);
    });
});

describe('RunStreamBridge output and sequencing events', function () {

    it('maps tool_denied to a turn event', function () {
        $turn = createBridgeTurn();
        $bridge = app(RunStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => RunPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
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
        expect($types)->toContain(RunEventType::ToolDenied);

        $denied = AiRunEvent::query()
            ->where('run_id', $turn->id)
            ->where('event_type', RunEventType::ToolDenied->value)
            ->first();

        expect($denied->payload['tool'])->toBe('dangerous_tool')
            ->and($denied->payload['reason'])->toBe('blocked by policy');
    });

    it('maps delta events to output deltas with streaming phase', function () {
        $turn = createBridgeTurn();
        $bridge = app(RunStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => RunPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'delta', 'data' => ['text' => 'Hello ']],
            ['event' => 'delta', 'data' => ['text' => 'world']],
            ['event' => 'done', 'data' => ['content' => 'Hello world', 'run_id' => BRIDGE_TEST_RUN_ID, 'meta' => []]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $types = turnEventTypes($turn);
        $deltaCount = collect($types)->filter(fn ($t) => $t === RunEventType::AssistantOutputDelta)->count();

        expect($deltaCount)->toBe(2);

        // Phase should have been set to streaming_answer
        $phaseEvents = AiRunEvent::query()
            ->where('run_id', $turn->id)
            ->where('event_type', RunEventType::RunPhaseChanged->value)
            ->whereJsonContains('payload->phase', 'streaming_answer')
            ->get();

        expect($phaseEvents)->toHaveCount(1);
    });

    it('emits output_block_committed on done with content', function () {
        $turn = createBridgeTurn();
        $bridge = app(RunStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => RunPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'done', 'data' => ['content' => 'Final answer', 'run_id' => BRIDGE_TEST_RUN_ID, 'meta' => []]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $block = AiRunEvent::query()
            ->where('run_id', $turn->id)
            ->where('event_type', RunEventType::AssistantOutputBlockCommitted->value)
            ->first();

        expect($block)->not()->toBeNull()
            ->and($block->payload['block_type'])->toBe('markdown')
            ->and($block->payload['content'])->toBe('Final answer');
    });

    it('emits usage_updated when done includes tokens', function () {
        $turn = createBridgeTurn();
        $bridge = app(RunStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => RunPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
            ['event' => 'done', 'data' => [
                'content' => 'Reply',
                'run_id' => BRIDGE_TEST_RUN_ID,
                'meta' => ['tokens' => ['prompt' => 100, 'completion' => 50]],
            ]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $usage = AiRunEvent::query()
            ->where('run_id', $turn->id)
            ->where('event_type', RunEventType::UsageUpdated->value)
            ->first();

        expect($usage)->not()->toBeNull()
            ->and($usage->payload['prompt'])->toBe(100)
            ->and($usage->payload['completion'])->toBe(50);
    });

    it('maintains strictly increasing seq across all events', function () {
        $turn = createBridgeTurn();
        $bridge = app(RunStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => RunPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
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

        $seqs = AiRunEvent::query()
            ->where('run_id', $turn->id)
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
// RunStreamBridge — error handling
// ------------------------------------------------------------------

describe('RunStreamBridge error handling', function () {
    it('maps runtime error events to turn failure', function () {
        $turn = createBridgeTurn();
        $bridge = app(RunStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'error', 'data' => [
                'message' => 'API rate limited',
                'run_id' => BRIDGE_TEST_RUN_ID,
                'meta' => ['error_type' => 'rate_limit'],
            ]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $turn->refresh();
        expect($turn->status)->toBe(AiRunStatus::Failed)
            ->and($turn->current_phase)->toBe(RunPhase::Failed);

        $failed = AiRunEvent::query()
            ->where('run_id', $turn->id)
            ->where('event_type', RunEventType::RunFailed->value)
            ->first();

        expect($failed)->not()->toBeNull()
            ->and($failed->payload['error_type'])->toBe('rate_limit')
            ->and($failed->payload['message'])->toBe('API rate limited');
    });

    it('fails the turn if stream ends without terminal event', function () {
        $turn = createBridgeTurn();
        $bridge = app(RunStreamBridge::class);

        // Stream that produces events but no done/error
        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => RunPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $turn->refresh();
        expect($turn->status)->toBe(AiRunStatus::Failed);

        $failed = AiRunEvent::query()
            ->where('run_id', $turn->id)
            ->where('event_type', RunEventType::RunFailed->value)
            ->first();

        expect($failed)->not()->toBeNull()
            ->and($failed->payload['error_type'])->toBe('unexpected_end');
    });

    it('fails the turn and rethrows when runtime throws an exception', function () {
        $turn = createBridgeTurn();
        $bridge = app(RunStreamBridge::class);

        $throwingStream = (function () {
            yield ['event' => 'status', 'data' => ['phase' => RunPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]];
            throw new RunStreamBridgeTestStreamFailure('LLM connection lost');
        })();

        expect(fn () => iterator_to_array($bridge->wrap($turn, $throwingStream)))
            ->toThrow(RunStreamBridgeTestStreamFailure::class, 'LLM connection lost');

        $turn->refresh();
        expect($turn->status)->toBe(AiRunStatus::Failed);

        $failed = AiRunEvent::query()
            ->where('run_id', $turn->id)
            ->where('event_type', RunEventType::RunFailed->value)
            ->first();

        expect($failed)->not()->toBeNull()
            ->and($failed->payload['error_type'])->toBe('runtime_exception');
    });

    it('handles error without run_id gracefully', function () {
        $turn = createBridgeTurn();
        $bridge = app(RunStreamBridge::class);

        // Error before a run_id is ever seen — turn stays in Booting
        $stream = runtimeStream([
            ['event' => 'error', 'data' => [
                'message' => 'No config available',
                'meta' => ['error_type' => 'config_error'],
            ]],
        ]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $turn->refresh();
        expect($turn->status)->toBe(AiRunStatus::Failed);
    });

    it('handles empty stream by failing the turn', function () {
        $turn = createBridgeTurn();
        $bridge = app(RunStreamBridge::class);

        $stream = runtimeStream([]);

        iterator_to_array($bridge->wrap($turn, $stream));

        $turn->refresh();
        expect($turn->status)->toBe(AiRunStatus::Failed);
    });
});

describe('RunStreamBridge tool events', function () {
    it('captures tool result_length and error_payload in turn events', function () {
        $turn = createBridgeTurn();
        $bridge = app(RunStreamBridge::class);

        $stream = runtimeStream([
            ['event' => 'status', 'data' => ['phase' => RunPhase::AwaitingLlm->value, 'run_id' => BRIDGE_TEST_RUN_ID]],
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

        $toolFinished = AiRunEvent::query()
            ->where('run_id', $turn->id)
            ->where('event_type', RunEventType::ToolFinished->value)
            ->first();

        expect($toolFinished)->not()->toBeNull()
            ->and($toolFinished->payload['result_length'])->toBe(42)
            ->and($toolFinished->payload['error_payload'])->toBe([
                'code' => 'permission_denied',
                'message' => 'Not allowed',
            ]);
    });
});
