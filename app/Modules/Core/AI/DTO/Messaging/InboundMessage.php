<?php
namespace App\Modules\Core\AI\DTO\Messaging;

use DateTimeImmutable;

/**
 * Value object representing a parsed inbound message from a channel webhook.
 *
 * Produced by ChannelAdapter::parseInbound() and consumed by the
 * channel router for conversation assignment and agent processing.
 */
final readonly class InboundMessage
{
    /**
     * @param  string  $channelId  Channel identifier (e.g., 'whatsapp', 'telegram')
     * @param  string  $sender  Sender identifier (phone number, user ID, etc.)
     * @param  string  $content  Message text content
     * @param  string|null  $messageId  Platform-assigned message ID
     * @param  string|null  $conversationId  Platform-assigned conversation/chat ID
     * @param  array<string, mixed>  $media  Media attachments metadata
     * @param  array<string, mixed>  $meta  Additional platform-specific metadata
     * @param  DateTimeImmutable|null  $timestamp  Message timestamp from the platform
     */
    public function __construct(
        public string $channelId,
        public string $sender,
        public string $content,
        public ?string $messageId = null,
        public ?string $conversationId = null,
        public array $media = [],
        public array $meta = [],
        public ?DateTimeImmutable $timestamp = null,
    ) {}
}
