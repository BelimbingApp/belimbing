<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Discriminated event types for the chat turn event stream.
 *
 * Stored as the string backing value in `ai_chat_turn_events.event_type`.
 * This enum is the durable contract key shared across:
 *   - DB persistence (event_type column)
 *   - SSE transport (event field)
 *   - UI discriminated union (TypeScript switch key)
 *
 * Events are append-only and immutable once written. The seq column
 * within a turn provides strict ordering for replay and resume.
 *
 * Naming convention: `{domain}.{action}` using dot-separated families.
 *
 * Cross-reference: Claw Code's AssistantEvent enum in
 * `rust/crates/runtime/src/conversation.rs` (TextDelta, ToolUse, Usage,
 * MessageStop) — BLB's event taxonomy is richer because it covers the
 * full turn lifecycle, not just assistant streaming.
 */
enum TurnEventType: string
{
    // ── Turn lifecycle ───────────────────────────────────────────────
    /** Turn created and enqueued for execution. */
    case TurnStarted = 'turn.started';

    /** User-visible phase changed (thinking → running_tool, etc.). */
    case TurnPhaseChanged = 'turn.phase_changed';

    /** Turn reached a terminal success state — input is accepted again. */
    case TurnCompleted = 'turn.completed';

    /** Turn ended in error. Payload carries error_type and message. */
    case TurnFailed = 'turn.failed';

    /** Turn was cancelled by user or system. */
    case TurnCancelled = 'turn.cancelled';

    /** Turn is ready to accept the next user message. */
    case TurnReadyForInput = 'turn.ready_for_input';

    // ── Run lifecycle ────────────────────────────────────────────────
    /** An LLM run began within this turn (may retry → multiple runs). */
    case RunStarted = 'run.started';

    /** An LLM run failed (retry/fallback may follow). */
    case RunFailed = 'run.failed';

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
    /** Periodic heartbeat during quiet phases so the UI knows the turn is alive. */
    case Heartbeat = 'heartbeat';

    // ── Recovery ─────────────────────────────────────────────────────
    /** A retry or recovery attempt started. */
    case RecoveryAttempted = 'recovery.attempted';

    /** Recovery succeeded — execution continues. */
    case RecoverySucceeded = 'recovery.succeeded';

    /** Recovery exhausted — turn will fail. */
    case RecoveryFailed = 'recovery.failed';

    /**
     * Whether this event type signals a terminal turn state.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::TurnCompleted, self::TurnFailed, self::TurnCancelled => true,
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
            self::TurnFailed, self::RunFailed, self::RecoveryFailed => 'error',
            self::ToolDenied, self::RecoveryAttempted => 'warning',
            default => 'info',
        };
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::TurnStarted => 'Turn Started',
            self::TurnPhaseChanged => 'Phase Changed',
            self::TurnCompleted => 'Turn Completed',
            self::TurnFailed => 'Turn Failed',
            self::TurnCancelled => 'Turn Cancelled',
            self::TurnReadyForInput => 'Ready for Input',
            self::RunStarted => 'Run Started',
            self::RunFailed => 'Run Failed',
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
            self::RecoveryAttempted => 'Recovery Attempted',
            self::RecoverySucceeded => 'Recovery Succeeded',
            self::RecoveryFailed => 'Recovery Failed',
        };
    }
}
