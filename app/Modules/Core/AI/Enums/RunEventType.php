<?php
namespace App\Modules\Core\AI\Enums;

/**
 * Discriminated event types for the AI run event stream.
 *
 * Stored as the string backing value in `ai_run_events.event_type`.
 * This enum is the durable contract key shared across:
 *   - DB persistence (event_type column)
 *   - SSE transport (event field)
 *   - UI discriminated union (TypeScript switch key)
 *
 * Events are append-only and immutable once written. The seq column
 * within a run provides strict ordering for replay and resume.
 *
 * Naming convention: `{domain}.{action}` using dot-separated families.
 *
 * Cross-reference: Claw Code's AssistantEvent enum in
 * `rust/crates/runtime/src/conversation.rs` (TextDelta, ToolUse, Usage,
 * MessageStop) — BLB's event taxonomy is richer because it covers the
 * full run lifecycle, not just assistant streaming.
 */
enum RunEventType: string
{
    // ── Run lifecycle ────────────────────────────────────────────────
    /** Run envelope created and enqueued for execution. */
    case RunStarted = 'run.started';

    /** User-visible phase changed (thinking → running_tool, etc.). */
    case RunPhaseChanged = 'run.phase_changed';

    /** Run reached a terminal success state. */
    case RunCompleted = 'run.completed';

    /** Run ended in error. Payload carries error_type and message. */
    case RunFailed = 'run.failed';

    /** Run was cancelled by user or system. */
    case RunCancelled = 'run.cancelled';

    /** Chat-originated run is ready to accept the next user message. */
    case RunReadyForInput = 'run.ready_for_input';

    // ── Assistant output ─────────────────────────────────────────────
    /** Agent entered thinking/reasoning phase. */
    case AssistantThinkingStarted = 'assistant.thinking_started';

    /** Incremental thinking/reasoning text from the model. */
    case AssistantThinkingDelta = 'assistant.thinking_delta';

    /** One agentic loop iteration completed (think → tools → draft). */
    case AssistantIterationCompleted = 'assistant.iteration_completed';

    /** Incremental text chunk from the assistant response stream. */
    case AssistantOutputDelta = 'assistant.output_delta';

    /** A complete content block committed (e.g., full code block). */
    case AssistantOutputBlockCommitted = 'assistant.output_block_committed';

    // ── Tool execution ───────────────────────────────────────────────
    /** Tool invocation began. Payload: tool name, args summary. */
    case ToolStarted = 'tool.started';

    /** Incremental stdout/output from a running tool (optional). */
    case ToolStdoutDelta = 'tool.stdout_delta';

    /** Tool finished. Payload: result preview, duration, status. */
    case ToolFinished = 'tool.finished';

    /** Tool was denied by policy. Payload: tool name, reason. */
    case ToolDenied = 'tool.denied';

    // ── Telemetry ────────────────────────────────────────────────────
    /** Token/cost usage snapshot updated. */
    case UsageUpdated = 'usage.updated';

    // ── Liveness ─────────────────────────────────────────────────────
    /** Periodic heartbeat during quiet phases so the UI knows the run is alive. */
    case Heartbeat = 'heartbeat';

    /**
     * Whether this event type signals a terminal run state.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::RunCompleted, self::RunFailed, self::RunCancelled => true,
            default => false,
        };
    }

    /**
     * Whether this event carries incremental content (deltas).
     */
    public function isDelta(): bool
    {
        return match ($this) {
            self::AssistantOutputDelta, self::ToolStdoutDelta, self::AssistantThinkingDelta => true,
            default => false,
        };
    }

    /**
     * Severity level for filtering and alerting.
     */
    public function severity(): string
    {
        return match ($this) {
            self::RunFailed => 'error',
            self::ToolDenied => 'warning',
            default => 'info',
        };
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::RunStarted => 'Run Started',
            self::RunPhaseChanged => 'Phase Changed',
            self::RunCompleted => 'Run Completed',
            self::RunFailed => 'Run Failed',
            self::RunCancelled => 'Run Cancelled',
            self::RunReadyForInput => 'Ready for Input',
            self::AssistantThinkingStarted => 'Thinking',
            self::AssistantThinkingDelta => 'Thinking Delta',
            self::AssistantIterationCompleted => 'Iteration Completed',
            self::AssistantOutputDelta => 'Output Delta',
            self::AssistantOutputBlockCommitted => 'Block Committed',
            self::ToolStarted => 'Tool Started',
            self::ToolStdoutDelta => 'Tool Output',
            self::ToolFinished => 'Tool Finished',
            self::ToolDenied => 'Tool Denied',
            self::UsageUpdated => 'Usage Updated',
            self::Heartbeat => 'Heartbeat',

        };
    }
}
