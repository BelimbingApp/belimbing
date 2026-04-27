<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Concerns;

use App\Modules\Core\AI\Enums\TurnEventType;
use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Models\ChatTurnEvent;

trait PublishesRecoveryEvents
{
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
}
