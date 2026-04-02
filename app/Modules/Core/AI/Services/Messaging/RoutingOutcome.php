<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Messaging;

/**
 * Result of routing an inbound signal through InboundRoutingService.
 *
 * Describes whether the signal was routed to a conversation, rejected
 * due to authenticity failure, or skipped due to missing content.
 */
final readonly class RoutingOutcome
{
    private function __construct(
        public string $disposition,
        public ?int $conversationId = null,
        public ?int $messageRecordId = null,
        public ?string $reason = null,
    ) {}

    /**
     * Signal was successfully routed to a conversation.
     */
    public static function routed(int $conversationId, int $messageRecordId): self
    {
        return new self(
            disposition: 'routed',
            conversationId: $conversationId,
            messageRecordId: $messageRecordId,
        );
    }

    /**
     * Signal was rejected (authenticity failure).
     */
    public static function rejected(string $reason): self
    {
        return new self(disposition: 'rejected', reason: $reason);
    }

    /**
     * Signal was skipped (no actionable content).
     */
    public static function skipped(string $reason): self
    {
        return new self(disposition: 'skipped', reason: $reason);
    }

    public function wasRouted(): bool
    {
        return $this->disposition === 'routed';
    }
}
