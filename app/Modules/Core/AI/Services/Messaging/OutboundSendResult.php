<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Messaging;

use App\Modules\Core\AI\DTO\Messaging\SendResult;

/**
 * Result of an outbound message operation through OutboundMessageService.
 *
 * Wraps the transport-level SendResult with durable conversation and
 * message identifiers from BLB's persistence layer. This is the return
 * type that tools and callers receive — never the raw SendResult.
 */
final readonly class OutboundSendResult
{
    /**
     * @param  bool  $success  Whether the send operation succeeded
     * @param  string|null  $messageId  Platform-assigned external message ID
     * @param  string|null  $error  Error description (on failure)
     * @param  int|null  $conversationId  BLB conversation record ID
     * @param  int|null  $messageRecordId  BLB message record ID
     */
    public function __construct(
        public bool $success,
        public ?string $messageId = null,
        public ?string $error = null,
        public ?int $conversationId = null,
        public ?int $messageRecordId = null,
    ) {}

    /**
     * Create from a transport SendResult with durable identifiers.
     *
     * @param  SendResult  $sendResult  Transport-level result
     * @param  int  $conversationId  BLB conversation record ID
     * @param  int  $messageRecordId  BLB message record ID
     */
    public static function fromTransport(SendResult $sendResult, int $conversationId, int $messageRecordId): self
    {
        return new self(
            success: $sendResult->success,
            messageId: $sendResult->messageId,
            error: $sendResult->error,
            conversationId: $conversationId,
            messageRecordId: $messageRecordId,
        );
    }

    /**
     * Create a validation failure result (no transport attempted).
     *
     * @param  string  $error  Validation error description
     */
    public static function validationFailed(string $error): self
    {
        return new self(success: false, error: $error);
    }
}
