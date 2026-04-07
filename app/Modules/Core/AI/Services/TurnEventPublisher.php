<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\Enums\TurnEventType;
use App\Modules\Core\AI\Enums\TurnPhase;
use App\Modules\Core\AI\Enums\TurnStatus;
use App\Modules\Core\AI\Events\TurnEventOccurred;
use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Models\ChatTurnEvent;
use Illuminate\Support\Facades\DB;

/**
 * Publishes structured events to a chat turn's append-only event stream.
 *
 * This is the primary live-write path for the coding-agent console UX.
 * Every meaningful runtime state change (phase transitions, tool execution,
 * assistant output deltas, retries, errors) flows through this publisher.
 *
 * ChatRunPersister is demoted to a transcript materializer that consumes
 * turn events after a turn completes — it no longer defines the live UX.
 *
 * Each published event is assigned a strictly increasing seq within the
 * turn, persisted atomically, and broadcast via Reverb WebSocket for
 * live delivery. Persisted events are also available for HTTP replay
 * via the `after_seq` resume contract.
 *
 * Cross-reference: Claw Code's SessionTracer in
 * `rust/crates/telemetry/src/lib.rs` — record_turn_started(),
 * record_tool_started(), record_tool_finished(), record_turn_completed().
 */
class TurnEventPublisher
{
    /**
     * Publish a single event to a turn's event stream.
     *
     * Atomically allocates the next seq and inserts the event row.
     * Returns the created event for callers that need the seq.
     *
     * @param  ChatTurn  $turn  The turn to publish to
     * @param  TurnEventType  $eventType  Discriminated event type
     * @param  array<string, mixed>|null  $payload  Event-specific data
     */
    public function publish(ChatTurn $turn, TurnEventType $eventType, ?array $payload = null): ChatTurnEvent
    {
        $event = DB::transaction(function () use ($turn, $eventType, $payload): ChatTurnEvent {
            $seq = $turn->nextSeq();

            return ChatTurnEvent::query()->create([
                'turn_id' => $turn->id,
                'seq' => $seq,
                'event_type' => $eventType->value,
                'payload' => $payload,
            ]);
        });

        TurnEventOccurred::dispatch($turn->id, $event->toBroadcastPayload());

        return $event;
    }

    // ── Turn lifecycle ───────────────────────────────────────────────

    /**
     * Emit turn.started and transition to Booting.
     */
    public function turnStarted(ChatTurn $turn): ChatTurnEvent
    {
        $event = $this->publish($turn, TurnEventType::TurnStarted, [
            'session_id' => $turn->session_id,
            'employee_id' => $turn->employee_id,
            'started_at' => now()->toIso8601String(),
        ]);

        $turn->transitionTo(TurnStatus::Booting);

        return $event;
    }

    /**
     * Emit turn.phase_changed and update the turn's current phase.
     */
    public function phaseChanged(ChatTurn $turn, TurnPhase $phase, ?string $label = null): ChatTurnEvent
    {
        $event = $this->publish($turn, TurnEventType::TurnPhaseChanged, [
            'phase' => $phase->value,
            'label' => $label,
        ]);

        $turn->updatePhase($phase, $label);

        return $event;
    }

    /**
     * Emit turn.completed, finalize the turn, and emit turn.ready_for_input.
     */
    public function turnCompleted(ChatTurn $turn, ?array $payload = null): ChatTurnEvent
    {
        $event = $this->publish($turn, TurnEventType::TurnCompleted, $payload);

        $turn->transitionTo(TurnStatus::Completed);

        $this->publish($turn, TurnEventType::TurnReadyForInput);

        return $event;
    }

    /**
     * Emit turn.failed and finalize the turn.
     */
    public function turnFailed(ChatTurn $turn, string $errorType, string $message, ?array $meta = null): ChatTurnEvent
    {
        $event = $this->publish($turn, TurnEventType::TurnFailed, [
            'error_type' => $errorType,
            'message' => $message,
            'meta' => $meta,
        ]);

        $turn->updatePhase(TurnPhase::Failed);
        $turn->transitionTo(TurnStatus::Failed);

        return $event;
    }

    /**
     * Emit turn.cancelled and finalize the turn.
     */
    public function turnCancelled(ChatTurn $turn, ?string $reason = null): ChatTurnEvent
    {
        $event = $this->publish($turn, TurnEventType::TurnCancelled, [
            'reason' => $reason,
        ]);

        $turn->updatePhase(TurnPhase::Cancelled);
        $turn->transitionTo(TurnStatus::Cancelled);

        return $event;
    }

    // ── Run lifecycle ────────────────────────────────────────────────

    /**
     * Emit run.started when an LLM run begins within a turn.
     */
    public function runStarted(ChatTurn $turn, string $runId, ?string $provider = null, ?string $model = null): ChatTurnEvent
    {
        $turn->update(['current_run_id' => $runId]);

        return $this->publish($turn, TurnEventType::RunStarted, [
            'run_id' => $runId,
            'provider' => $provider,
            'model' => $model,
        ]);
    }

    /**
     * Emit run.failed when an LLM run fails (retry/fallback may follow).
     */
    public function runFailed(ChatTurn $turn, string $runId, string $errorType, string $message): ChatTurnEvent
    {
        return $this->publish($turn, TurnEventType::RunFailed, [
            'run_id' => $runId,
            'error_type' => $errorType,
            'message' => $message,
        ]);
    }

    // ── Assistant output ─────────────────────────────────────────────

    /**
     * Emit assistant.thinking_started when the agent enters reasoning mode.
     */
    public function thinkingStarted(ChatTurn $turn, ?string $description = null): ChatTurnEvent
    {
        return $this->publish($turn, TurnEventType::AssistantThinkingStarted, $description !== null
            ? ['description' => $description]
            : null,
        );
    }

    /**
     * Emit assistant.output_delta for incremental response text.
     */
    public function outputDelta(ChatTurn $turn, string $delta): ChatTurnEvent
    {
        return $this->publish($turn, TurnEventType::AssistantOutputDelta, [
            'delta' => $delta,
        ]);
    }

    /**
     * Emit assistant.output_block_committed for a complete content block.
     */
    public function outputBlockCommitted(ChatTurn $turn, string $blockType, string $content): ChatTurnEvent
    {
        return $this->publish($turn, TurnEventType::AssistantOutputBlockCommitted, [
            'block_type' => $blockType,
            'content' => $content,
        ]);
    }

    // ── Tool execution ───────────────────────────────────────────────

    /**
     * Emit tool.started when a tool invocation begins.
     */
    public function toolStarted(ChatTurn $turn, string $toolName, ?string $argsSummary = null, ?int $toolCallIndex = null): ChatTurnEvent
    {
        return $this->publish($turn, TurnEventType::ToolStarted, [
            'tool' => $toolName,
            'args_summary' => $argsSummary,
            'tool_call_index' => $toolCallIndex,
        ]);
    }

    /**
     * Emit tool.stdout_delta for incremental tool output.
     */
    public function toolStdoutDelta(ChatTurn $turn, string $toolName, string $delta): ChatTurnEvent
    {
        return $this->publish($turn, TurnEventType::ToolStdoutDelta, [
            'tool' => $toolName,
            'delta' => $delta,
        ]);
    }

    /**
     * Emit tool.finished when a tool invocation completes.
     *
     * @param  array<string, mixed>|null  $errorPayload  Structured error data when status is 'error'
     */
    public function toolFinished(
        ChatTurn $turn,
        string $toolName,
        string $status,
        ?string $resultPreview = null,
        ?int $durationMs = null,
        ?int $resultLength = null,
        ?array $errorPayload = null,
    ): ChatTurnEvent {
        return $this->publish($turn, TurnEventType::ToolFinished, array_filter([
            'tool' => $toolName,
            'status' => $status,
            'result_preview' => $resultPreview,
            'duration_ms' => $durationMs,
            'result_length' => $resultLength,
            'error_payload' => $errorPayload,
        ], fn ($v) => $v !== null));
    }

    /**
     * Emit tool.denied when policy blocks a tool invocation.
     */
    public function toolDenied(ChatTurn $turn, string $toolName, string $reason, string $source = 'hook'): ChatTurnEvent
    {
        return $this->publish($turn, TurnEventType::ToolDenied, [
            'tool' => $toolName,
            'reason' => $reason,
            'source' => $source,
        ]);
    }

    // ── Telemetry ────────────────────────────────────────────────────

    /**
     * Emit usage.updated with token/cost snapshots.
     *
     * @param  array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int}  $usage
     */
    public function usageUpdated(ChatTurn $turn, array $usage): ChatTurnEvent
    {
        return $this->publish($turn, TurnEventType::UsageUpdated, $usage);
    }

    // ── Liveness ─────────────────────────────────────────────────────

    /**
     * Emit heartbeat so the client knows the turn is alive during quiet phases.
     */
    public function heartbeat(ChatTurn $turn, ?int $elapsedMs = null): ChatTurnEvent
    {
        return $this->publish($turn, TurnEventType::Heartbeat, [
            'elapsed_ms' => $elapsedMs,
        ]);
    }

    // ── Recovery ─────────────────────────────────────────────────────

    /**
     * Emit recovery.attempted when a retry or recovery starts.
     */
    public function recoveryAttempted(ChatTurn $turn, int $attempt, ?string $reason = null): ChatTurnEvent
    {
        return $this->publish($turn, TurnEventType::RecoveryAttempted, [
            'attempt' => $attempt,
            'reason' => $reason,
        ]);
    }

    /**
     * Emit recovery.succeeded when recovery resolves the issue.
     */
    public function recoverySucceeded(ChatTurn $turn, int $attempt): ChatTurnEvent
    {
        return $this->publish($turn, TurnEventType::RecoverySucceeded, [
            'attempt' => $attempt,
        ]);
    }

    /**
     * Emit recovery.failed when all recovery attempts are exhausted.
     */
    public function recoveryFailed(ChatTurn $turn, int $attempt, string $reason): ChatTurnEvent
    {
        return $this->publish($turn, TurnEventType::RecoveryFailed, [
            'attempt' => $attempt,
            'reason' => $reason,
        ]);
    }
}
