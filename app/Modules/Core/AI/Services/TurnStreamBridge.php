<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\Enums\TurnPhase;
use App\Modules\Core\AI\Enums\TurnStatus;
use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Models\ChatTurnEvent;

/**
 * Maps AgenticRuntime event streams to durable turn events.
 *
 * Wraps the runtime's Generator and publishes structured turn events via
 * TurnEventPublisher. Yields each persisted turn event payload,
 * providing a single unified event format for both live delivery
 * (via the streaming HTTP response) and replay-after-disconnect.
 *
 * The bridge:
 * 1. Transitions the turn Queued → Booting → Running as events arrive
 * 2. Maps each runtime event to one or more turn events
 * 3. Yields turn event payloads to the streaming controller
 * 4. Finalizes the turn on done/error
 *
 * Callers that abandon the generator early (e.g., cancellation) must
 * handle terminal turn transitions themselves.
 *
 * Cross-reference: Claw Code ConversationRuntime in
 * `rust/crates/runtime/src/conversation.rs` — the event loop that drives
 * AssistantEvent emission during a conversation turn.
 */
class TurnStreamBridge
{
    public function __construct(
        private readonly TurnEventPublisher $publisher,
    ) {}

    /**
     * Wrap a runtime event stream and publish turn events.
     *
     * Yields turn event payloads (same format as TurnEventStreamController JSON
     * response and the direct stream controller).
     *
     * @param  ChatTurn  $turn  A freshly created turn in Queued status
     * @param  \Generator<int, array{event: string, data: array<string, mixed>}>  $runtimeStream
     * @return \Generator<int, array{turn_id: string, seq: int, event_type: string, payload: mixed, occurred_at: string}>
     */
    public function wrap(ChatTurn $turn, \Generator $runtimeStream): \Generator
    {
        $turnStartedAt = hrtime(true);

        yield $this->publisher->turnStarted($turn)->toSsePayload();
        $turn->update(['started_at' => now()]);

        try {
            foreach ($runtimeStream as $event) {
                $data = $event['data'];

                yield from $this->maybeTransitionToRunning($turn, $data);

                yield from match ($event['event']) {
                    'status' => $this->mapStatusEvent($turn, $data, $turnStartedAt),
                    'delta' => $this->mapDeltaEvent($turn, $data),
                    'done' => $this->mapDoneEvent($turn, $data, $turnStartedAt),
                    'error' => $this->mapErrorEvent($turn, $data),
                    default => [],
                };
            }
        } catch (\Throwable $e) {
            $turn->refresh();

            if (! $turn->isTerminal()) {
                yield $this->publisher->turnFailed($turn, 'runtime_exception', $e->getMessage())->toSsePayload();
            }

            throw $e;
        }

        // If stream completed naturally without a terminal event, fail the turn.
        $turn->refresh();

        if (! $turn->isTerminal()) {
            yield $this->publisher->turnFailed(
                $turn,
                'unexpected_end',
                'Runtime stream ended without terminal event',
            )->toSsePayload();
        }
    }

    /**
     * Transition Booting → Running on first event with a run_id.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function maybeTransitionToRunning(ChatTurn $turn, array $data): array
    {
        if ($turn->status !== TurnStatus::Booting) {
            return [];
        }

        $runId = $data['run_id'] ?? null;

        if (! is_string($runId)) {
            return [];
        }

        $turn->transitionTo(TurnStatus::Running);

        return [$this->publisher->runStarted($turn, $runId)->toSsePayload()];
    }

    /**
     * Map a runtime 'status' event to turn events.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function mapStatusEvent(ChatTurn $turn, array $data, int $turnStartedAt): array
    {
        return match ($data['phase'] ?? '') {
            TurnPhase::AwaitingLlm->value => $this->onAwaitingLlmPhase($turn, $data),
            'thinking_delta' => $this->onThinkingDelta($turn, $data),
            'tool_started' => $this->onToolStarted($turn, $data),
            'tool_stdout' => $this->onToolStdout($turn, $data),
            'tool_finished' => $this->onToolFinished($turn, $data, $turnStartedAt),
            'tool_denied' => $this->onToolDenied($turn, $data),
            'recovery_attempted' => $this->onRecoveryAttempted($turn, $data),
            'recovery_succeeded' => $this->onRecoverySucceeded($turn, $data),
            default => [],
        };
    }

    /**
     * Maps a runtime "awaiting LLM" status to phase + optional reasoning panel seed.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function onAwaitingLlmPhase(ChatTurn $turn, array $_data): array
    {
        $label = TurnPhase::AwaitingLlm->label();

        return [
            $this->publisher->phaseChanged($turn, TurnPhase::AwaitingLlm, $label)->toSsePayload(),
            $this->publisher->thinkingStarted($turn)->toSsePayload(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function onThinkingDelta(ChatTurn $turn, array $data): array
    {
        return [
            $this->publisher->thinkingDelta($turn, (string) ($data['delta'] ?? ''))->toSsePayload(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function onToolStarted(ChatTurn $turn, array $data): array
    {
        $toolName = (string) ($data['tool'] ?? 'tool');

        return [
            $this->publisher->phaseChanged($turn, TurnPhase::RunningTool, $toolName)->toSsePayload(),
            $this->publisher->toolStarted(
                $turn,
                $toolName,
                $data['args_summary'] ?? null,
                isset($data['tool_call_index']) ? (int) $data['tool_call_index'] : null,
            )->toSsePayload(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function onToolStdout(ChatTurn $turn, array $data): array
    {
        return [
            $this->publisher->toolStdoutDelta(
                $turn,
                (string) ($data['tool'] ?? ''),
                (string) ($data['delta'] ?? ''),
            )->toSsePayload(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function onToolFinished(ChatTurn $turn, array $data, int $turnStartedAt): array
    {
        $elapsedMs = (int) ((hrtime(true) - $turnStartedAt) / 1_000_000);
        $toolName = (string) ($data['tool'] ?? '');
        $postToolLabel = TurnPhase::AwaitingLlm->label();

        return [
            $this->publisher->toolFinished(
                $turn,
                $toolName,
                (string) ($data['status'] ?? 'success'),
                $data['result_preview'] ?? null,
                isset($data['duration_ms']) ? (int) $data['duration_ms'] : null,
                isset($data['result_length']) ? (int) $data['result_length'] : null,
                is_array($data['error_payload'] ?? null) ? $data['error_payload'] : null,
            )->toSsePayload(),
            $this->publisher->phaseChanged($turn, TurnPhase::AwaitingLlm, $postToolLabel)->toSsePayload(),
            $this->publisher->heartbeat($turn, $elapsedMs)->toSsePayload(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function onToolDenied(ChatTurn $turn, array $data): array
    {
        return [
            $this->publisher->toolDenied(
                $turn,
                (string) ($data['tool'] ?? ''),
                (string) ($data['reason'] ?? 'denied'),
                (string) ($data['source'] ?? 'hook'),
            )->toSsePayload(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function onRecoveryAttempted(ChatTurn $turn, array $data): array
    {
        return [
            $this->publisher->recoveryAttempted(
                $turn,
                (int) ($data['attempt'] ?? 1),
                $data['reason'] ?? null,
            )->toSsePayload(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function onRecoverySucceeded(ChatTurn $turn, array $data): array
    {
        return [
            $this->publisher->recoverySucceeded(
                $turn,
                (int) ($data['attempt'] ?? 1),
            )->toSsePayload(),
        ];
    }

    /**
     * Map a runtime 'delta' event to an output delta turn event.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function mapDeltaEvent(ChatTurn $turn, array $data): array
    {
        $events = [];

        if ($turn->current_phase !== TurnPhase::StreamingAnswer) {
            $events[] = $this->publisher->phaseChanged($turn, TurnPhase::StreamingAnswer, 'Responding…')->toSsePayload();
        }

        $events[] = $this->publisher->outputDelta($turn, $data['text'] ?? '')->toSsePayload();

        return $events;
    }

    /**
     * Map a runtime 'done' event to turn completion events.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function mapDoneEvent(ChatTurn $turn, array $data, int $turnStartedAt): array
    {
        $elapsedMs = (int) ((hrtime(true) - $turnStartedAt) / 1_000_000);
        $events = [];

        $events[] = $this->publisher->phaseChanged($turn, TurnPhase::Finalizing, 'Finishing up…')->toSsePayload();

        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        if (isset($meta['tokens'])) {
            $events[] = $this->publisher->usageUpdated($turn, $meta['tokens'])->toSsePayload();
        }

        $content = $data['content'] ?? '';

        if ($content !== '') {
            $events[] = $this->publisher->outputBlockCommitted($turn, 'markdown', $content)->toSsePayload();
        }

        $events[] = $this->publisher->turnCompleted($turn, [
            'run_id' => $data['run_id'] ?? null,
            'elapsed_ms' => $elapsedMs,
        ])->toSsePayload();

        // turnCompleted also publishes ReadyForInput — read it back
        $readyEvent = ChatTurnEvent::query()
            ->where('turn_id', $turn->id)
            ->where('event_type', 'turn.ready_for_input')
            ->orderByDesc('seq')
            ->first();

        if ($readyEvent !== null) {
            $events[] = $readyEvent->toSsePayload();
        }

        return $events;
    }

    /**
     * Map a runtime 'error' event to a turn failure.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function mapErrorEvent(ChatTurn $turn, array $data): array
    {
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        return [
            $this->publisher->turnFailed(
                $turn,
                $meta['error_type'] ?? 'unknown',
                $data['message'] ?? 'An unexpected error occurred',
                $meta !== [] ? $meta : null,
            )->toSsePayload(),
        ];
    }
}
