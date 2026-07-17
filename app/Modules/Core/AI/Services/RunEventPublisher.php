<?php

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Enums\RunEventType;
use App\Modules\Core\AI\Enums\RunPhase;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Models\AiRunEvent;
use Illuminate\Support\Facades\DB;

/**
 * Publishes structured events to an AI run's append-only event stream.
 *
 * This is the primary live-write path for the coding-agent console UX.
 * Every meaningful runtime state change (phase transitions, tool execution,
 * assistant output deltas, retries, errors) flows through this publisher.
 *
 * ChatRunPersister is demoted to a transcript materializer that consumes
 * run events after a chat run completes — it no longer defines the live UX.
 *
 * Each published event is assigned a strictly increasing seq within the
 * run and persisted atomically. Fresh chat runs stream those events directly
 * over the NDJSON response, while persisted events remain available for
 * HTTP replay via the `after_seq` resume contract.
 *
 * Cross-reference: Claw Code's SessionTracer in
 * `rust/crates/telemetry/src/lib.rs` — record_turn_started(),
 * record_tool_started(), record_tool_finished(), record_turn_completed().
 */
class RunEventPublisher
{
    /**
     * Publish a single event to a run's event stream.
     *
     * Atomically allocates the next seq and inserts the event row.
     * Returns the created event for callers that need the seq.
     *
     * @param  AiRun  $turn  The run to publish to
     * @param  RunEventType  $eventType  Discriminated event type
     * @param  array<string, mixed>|null  $payload  Event-specific data
     */
    public function publish(AiRun $turn, RunEventType $eventType, ?array $payload = null): AiRunEvent
    {
        return DB::transaction(function () use ($turn, $eventType, $payload): AiRunEvent {
            $seq = $turn->nextSeq();

            return AiRunEvent::query()->create([
                'run_id' => $turn->id,
                'seq' => $seq,
                'event_type' => $eventType->value,
                'payload' => $this->sanitizePayload($payload),
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Scrub invalid UTF-8 sequences from all string values in the payload.
     *
     * Shell output (toolStdoutDelta) and LLM stream chunks can carry bytes
     * that are not valid UTF-8 (BOM, Windows-1252, partial multi-byte sequences).
     * json_encode rejects those, causing a model-cast exception on save.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function sanitizePayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        array_walk_recursive($payload, static function (mixed &$value): void {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $value = mb_scrub($value, 'UTF-8');
            }
        });

        return $payload;
    }

    // ── Run lifecycle ────────────────────────────────────────────────

    /**
     * Emit run.started and transition to Booting.
     */
    public function turnStarted(AiRun $turn): AiRunEvent
    {
        $event = $this->publish($turn, RunEventType::RunStarted, [
            'session_id' => $turn->session_id,
            'employee_id' => $turn->employee_id,
            'started_at' => now()->toIso8601String(),
        ]);

        $turn->transitionTo(AiRunStatus::Booting);

        return $event;
    }

    /**
     * Emit run.phase_changed and update the run's current phase.
     */
    public function phaseChanged(AiRun $turn, RunPhase $phase, ?string $label = null): AiRunEvent
    {
        $event = $this->publish($turn, RunEventType::RunPhaseChanged, [
            'phase' => $phase->value,
            'label' => $label,
        ]);

        $turn->updatePhase($phase, $label);

        return $event;
    }

    /**
     * Emit run.completed, finalize the run, and emit run.ready_for_input for chat.
     */
    public function turnCompleted(AiRun $turn, ?array $payload = null): AiRunEvent
    {
        $event = $this->publish($turn, RunEventType::RunCompleted, $payload);

        $turn->transitionTo(AiRunStatus::Succeeded);

        $this->publish($turn, RunEventType::RunReadyForInput);

        return $event;
    }

    /**
     * Emit run.failed and finalize the run.
     */
    public function turnFailed(AiRun $turn, string $errorType, string $message, ?array $meta = null): AiRunEvent
    {
        $event = $this->publish($turn, RunEventType::RunFailed, [
            'error_type' => $errorType,
            'message' => $message,
            'meta' => $meta,
        ]);

        $turn->updatePhase(RunPhase::Failed);
        $turn->transitionTo(AiRunStatus::Failed);

        return $event;
    }

    /**
     * Emit run.cancelled and finalize the run.
     */
    public function turnCancelled(AiRun $turn, ?string $reason = null): AiRunEvent
    {
        $event = $this->publish($turn, RunEventType::RunCancelled, [
            'reason' => $reason,
        ]);

        $turn->updatePhase(RunPhase::Cancelled);
        $turn->transitionTo(AiRunStatus::Cancelled);

        return $event;
    }

    // ── Assistant output ─────────────────────────────────────────────

    /**
     * Emit assistant.thinking_started when the agent enters reasoning mode.
     */
    public function thinkingStarted(AiRun $turn, ?string $description = null): AiRunEvent
    {
        return $this->publish($turn, RunEventType::AssistantThinkingStarted, $description !== null
            ? ['description' => $description]
            : null,
        );
    }

    /**
     * Emit assistant.thinking_delta for incremental reasoning text.
     */
    public function thinkingDelta(AiRun $turn, string $delta): AiRunEvent
    {
        return $this->publish($turn, RunEventType::AssistantThinkingDelta, [
            'delta' => $delta,
        ]);
    }

    /**
     * Emit assistant.iteration_completed when a streamed LLM iteration ends.
     */
    public function iterationCompleted(
        AiRun $turn,
        string $finishReason,
        ?int $iteration = null,
        ?int $toolCallCount = null,
    ): AiRunEvent {
        return $this->publish($turn, RunEventType::AssistantIterationCompleted, array_filter([
            'finish_reason' => $finishReason,
            'iteration' => $iteration,
            'tool_call_count' => $toolCallCount,
        ], fn ($v) => $v !== null));
    }

    /**
     * Emit assistant.output_delta for incremental response text.
     */
    public function outputDelta(AiRun $turn, string $delta): AiRunEvent
    {
        return $this->publish($turn, RunEventType::AssistantOutputDelta, [
            'delta' => $delta,
        ]);
    }

    /**
     * Emit assistant.output_block_committed for a complete content block.
     */
    public function outputBlockCommitted(AiRun $turn, string $blockType, string $content): AiRunEvent
    {
        return $this->publish($turn, RunEventType::AssistantOutputBlockCommitted, [
            'block_type' => $blockType,
            'content' => $content,
        ]);
    }

    // ── Tool execution ───────────────────────────────────────────────

    /**
     * Emit tool.started when a tool invocation begins.
     */
    public function toolStarted(
        AiRun $turn,
        string $toolName,
        ?string $argsSummary = null,
        ?int $toolCallIndex = null,
        ?string $displaySummary = null,
    ): AiRunEvent {
        return $this->publish($turn, RunEventType::ToolStarted, [
            'tool' => $toolName,
            'args_summary' => $argsSummary,
            'display_summary' => $displaySummary,
            'tool_call_index' => $toolCallIndex,
        ]);
    }

    /**
     * Emit tool.stdout_delta for incremental tool output.
     */
    public function toolStdoutDelta(AiRun $turn, string $toolName, string $delta): AiRunEvent
    {
        return $this->publish($turn, RunEventType::ToolStdoutDelta, [
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
        AiRun $turn,
        string $toolName,
        string $status,
        ?string $resultPreview = null,
        ?int $durationMs = null,
        ?int $resultLength = null,
        ?array $errorPayload = null,
    ): AiRunEvent {
        return $this->publish($turn, RunEventType::ToolFinished, array_filter([
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
    public function toolDenied(AiRun $turn, string $toolName, string $reason, string $source = 'hook'): AiRunEvent
    {
        return $this->publish($turn, RunEventType::ToolDenied, [
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
    public function usageUpdated(AiRun $turn, array $usage): AiRunEvent
    {
        return $this->publish($turn, RunEventType::UsageUpdated, $usage);
    }

    // ── Liveness ─────────────────────────────────────────────────────

    /**
     * Emit heartbeat so the client knows the turn is alive during quiet phases.
     */
    public function heartbeat(AiRun $turn, ?int $elapsedMs = null): AiRunEvent
    {
        return $this->publish($turn, RunEventType::Heartbeat, [
            'elapsed_ms' => $elapsedMs,
        ]);
    }
}
