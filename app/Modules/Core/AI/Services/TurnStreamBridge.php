<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\Enums\TurnPhase;
use App\Modules\Core\AI\Enums\TurnStatus;
use App\Modules\Core\AI\Models\ChatTurn;

/**
 * Maps AgenticRuntime event streams to durable turn events.
 *
 * Wraps the runtime's Generator and publishes structured turn events via
 * TurnEventPublisher while passing the original events through unchanged
 * for existing consumers (ChatRunPersister, SSE emission).
 *
 * The bridge:
 * 1. Transitions the turn Queued → Booting → Running as events arrive
 * 2. Maps each runtime event to corresponding turn events
 * 3. Yields the original event unchanged for existing consumers
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
     * @param  ChatTurn  $turn  A freshly created turn in Queued status
     * @param  \Generator<int, array{event: string, data: array<string, mixed>}>  $runtimeStream
     * @return \Generator<int, array{event: string, data: array<string, mixed>}>
     */
    public function wrap(ChatTurn $turn, \Generator $runtimeStream): \Generator
    {
        $turnStartedAt = hrtime(true);

        $this->publisher->turnStarted($turn);
        $turn->update(['started_at' => now()]);

        try {
            foreach ($runtimeStream as $event) {
                $data = $event['data'];

                $this->maybeTransitionToRunning($turn, $data);

                match ($event['event']) {
                    'status' => $this->mapStatusEvent($turn, $data, $turnStartedAt),
                    'delta' => $this->mapDeltaEvent($turn, $data),
                    'done' => $this->mapDoneEvent($turn, $data, $turnStartedAt),
                    'error' => $this->mapErrorEvent($turn, $data),
                    default => null,
                };

                yield $event;
            }
        } catch (\Throwable $e) {
            $turn->refresh();

            if (! $turn->isTerminal()) {
                $this->publisher->turnFailed($turn, 'runtime_exception', $e->getMessage());
            }

            throw $e;
        }

        // If stream completed naturally without a terminal event, fail the turn.
        $turn->refresh();

        if (! $turn->isTerminal()) {
            $this->publisher->turnFailed(
                $turn,
                'unexpected_end',
                'Runtime stream ended without terminal event',
            );
        }
    }

    /**
     * Transition Booting → Running on first event with a run_id.
     *
     * @param  array<string, mixed>  $data
     */
    private function maybeTransitionToRunning(ChatTurn $turn, array $data): void
    {
        if ($turn->status !== TurnStatus::Booting) {
            return;
        }

        $runId = $data['run_id'] ?? null;

        if (! is_string($runId)) {
            return;
        }

        $turn->transitionTo(TurnStatus::Running);
        $this->publisher->runStarted($turn, $runId);
    }

    /**
     * Map a runtime 'status' event to turn events.
     *
     * @param  array<string, mixed>  $data
     */
    private function mapStatusEvent(ChatTurn $turn, array $data, int $turnStartedAt): void
    {
        match ($data['phase'] ?? '') {
            'thinking' => $this->onThinking($turn),
            'tool_started' => $this->onToolStarted($turn, $data),
            'tool_finished' => $this->onToolFinished($turn, $data, $turnStartedAt),
            'tool_denied' => $this->onToolDenied($turn, $data),
            'recovery_attempted' => $this->onRecoveryAttempted($turn, $data),
            'recovery_succeeded' => $this->onRecoverySucceeded($turn, $data),
            default => null,
        };
    }

    private function onThinking(ChatTurn $turn): void
    {
        $this->publisher->phaseChanged($turn, TurnPhase::Thinking, 'Thinking…');
        $this->publisher->thinkingStarted($turn);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function onToolStarted(ChatTurn $turn, array $data): void
    {
        $toolName = (string) ($data['tool'] ?? 'tool');

        $this->publisher->phaseChanged($turn, TurnPhase::RunningTool, $toolName);
        $this->publisher->toolStarted(
            $turn,
            $toolName,
            $data['args_summary'] ?? null,
            isset($data['tool_call_index']) ? (int) $data['tool_call_index'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function onToolFinished(ChatTurn $turn, array $data, int $turnStartedAt): void
    {
        $this->publisher->toolFinished(
            $turn,
            (string) ($data['tool'] ?? ''),
            (string) ($data['status'] ?? 'success'),
            $data['result_preview'] ?? null,
            isset($data['duration_ms']) ? (int) $data['duration_ms'] : null,
            isset($data['result_length']) ? (int) $data['result_length'] : null,
            is_array($data['error_payload'] ?? null) ? $data['error_payload'] : null,
        );

        // Return to thinking phase between tool completion and next LLM call.
        $this->publisher->phaseChanged($turn, TurnPhase::Thinking, 'Thinking…');

        // Heartbeat before the next quiet LLM call gives the client a liveness signal.
        $elapsedMs = (int) ((hrtime(true) - $turnStartedAt) / 1_000_000);
        $this->publisher->heartbeat($turn, $elapsedMs);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function onToolDenied(ChatTurn $turn, array $data): void
    {
        $this->publisher->toolDenied(
            $turn,
            (string) ($data['tool'] ?? ''),
            (string) ($data['reason'] ?? 'denied'),
            (string) ($data['source'] ?? 'hook'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function onRecoveryAttempted(ChatTurn $turn, array $data): void
    {
        $this->publisher->recoveryAttempted(
            $turn,
            (int) ($data['attempt'] ?? 1),
            $data['reason'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function onRecoverySucceeded(ChatTurn $turn, array $data): void
    {
        $this->publisher->recoverySucceeded(
            $turn,
            (int) ($data['attempt'] ?? 1),
        );
    }

    /**
     * Map a runtime 'delta' event to an output delta turn event.
     *
     * @param  array<string, mixed>  $data
     */
    private function mapDeltaEvent(ChatTurn $turn, array $data): void
    {
        if ($turn->current_phase !== TurnPhase::StreamingAnswer) {
            $this->publisher->phaseChanged($turn, TurnPhase::StreamingAnswer, 'Responding…');
        }

        $this->publisher->outputDelta($turn, $data['text'] ?? '');
    }

    /**
     * Map a runtime 'done' event to turn completion events.
     *
     * @param  array<string, mixed>  $data
     */
    private function mapDoneEvent(ChatTurn $turn, array $data, int $turnStartedAt): void
    {
        $elapsedMs = (int) ((hrtime(true) - $turnStartedAt) / 1_000_000);

        $this->publisher->phaseChanged($turn, TurnPhase::Finalizing, 'Finishing up…');

        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        if (isset($meta['tokens'])) {
            $this->publisher->usageUpdated($turn, $meta['tokens']);
        }

        $content = $data['content'] ?? '';

        if ($content !== '') {
            $this->publisher->outputBlockCommitted($turn, 'markdown', $content);
        }

        $this->publisher->turnCompleted($turn, [
            'run_id' => $data['run_id'] ?? null,
            'elapsed_ms' => $elapsedMs,
        ]);
    }

    /**
     * Map a runtime 'error' event to a turn failure.
     *
     * @param  array<string, mixed>  $data
     */
    private function mapErrorEvent(ChatTurn $turn, array $data): void
    {
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        $this->publisher->turnFailed(
            $turn,
            $meta['error_type'] ?? 'unknown',
            $data['message'] ?? 'An unexpected error occurred',
            $meta !== [] ? $meta : null,
        );
    }
}
