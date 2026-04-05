<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Classifies operations tracked in the dispatch ledger.
 *
 * Each case maps to a distinct execution path and lifecycle.
 */
enum OperationType: string
{
    /** Delegated agent work dispatched via delegate_task tool. */
    case AgentTask = 'agent_task';

    /** Execution triggered by a due schedule definition. */
    case ScheduledTask = 'scheduled_task';

    /** Background artisan command dispatched through policy. */
    case BackgroundCommand = 'background_command';

    /** Child agent session spawned through orchestration. */
    case ChildSession = 'child_session';

    /** Interactive chat message dispatched to worker queue. */
    case BackgroundChat = 'background_chat';

    /**
     * Determine whether the operation type targets an agent employee.
     */
    public function targetsAgent(): bool
    {
        return match ($this) {
            self::AgentTask, self::ScheduledTask, self::ChildSession, self::BackgroundChat => true,
            self::BackgroundCommand => false,
        };
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::AgentTask => 'Agent Task',
            self::ScheduledTask => 'Scheduled Task',
            self::BackgroundCommand => 'Background Command',
            self::ChildSession => 'Child Session',
            self::BackgroundChat => 'Background Chat',
        };
    }
}
