<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * User-visible phase within an active chat turn.
 *
 * While TurnStatus tracks the coarse lifecycle (queued → running → terminal),
 * TurnPhase provides the fine-grained label the user sees in the busy signal.
 * These phases correspond to what coding-agent CLIs display as their spinner
 * status text.
 *
 * Phase transitions are emitted as `turn.phase_changed` events so the UI
 * can update the status bar and activity indicator in real time.
 */
enum TurnPhase: string
{
    case WaitingForWorker = 'waiting_for_worker';
    case Thinking = 'thinking';
    case RunningTool = 'running_tool';
    case StreamingAnswer = 'streaming_answer';
    case Finalizing = 'finalizing';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Whether this phase indicates the agent is actively busy.
     */
    public function isBusy(): bool
    {
        return match ($this) {
            self::WaitingForWorker, self::Thinking, self::RunningTool,
            self::StreamingAnswer, self::Finalizing => true,
            self::Failed, self::Cancelled => false,
        };
    }

    /**
     * Short label suitable for the UI status bar.
     */
    public function label(): string
    {
        return match ($this) {
            self::WaitingForWorker => 'Waiting for worker…',
            self::Thinking => 'Thinking…',
            self::RunningTool => 'Running tool…',
            self::StreamingAnswer => 'Writing…',
            self::Finalizing => 'Finalizing…',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Icon hint for the UI (Heroicon name).
     */
    public function icon(): string
    {
        return match ($this) {
            self::WaitingForWorker => 'clock',
            self::Thinking => 'sparkles',
            self::RunningTool => 'wrench',
            self::StreamingAnswer => 'pencil',
            self::Finalizing => 'check-circle',
            self::Failed => 'x-circle',
            self::Cancelled => 'minus-circle',
        };
    }
}
