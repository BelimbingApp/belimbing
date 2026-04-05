<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Messaging;

use App\Modules\Core\AI\DTO\Messaging\SendResult;
use App\Modules\Core\AI\Enums\MessageDirection;
use App\Modules\Core\AI\Exceptions\ChannelNotAvailableException;
use App\Modules\Core\AI\Exceptions\NoChannelAccountException;
use App\Modules\Core\AI\Models\ChannelAccount;
use App\Modules\Core\AI\Models\ChannelConversation;
use App\Modules\Core\AI\Models\ChannelConversationMessage;

/**
 * Orchestrates outbound message delivery across all channels.
 *
 * Resolves the company's channel account, validates the payload against
 * channel capabilities, dispatches through the transport adapter, and
 * persists conversation/message records for audit. Tools call this
 * service — they never invoke adapters directly.
 */
class OutboundMessageService
{
    public function __construct(
        private readonly ChannelAdapterRegistry $adapterRegistry,
    ) {}

    /**
     * Send a text message through a configured channel.
     *
     * @param  int  $companyId  Owning company ID
     * @param  string  $channel  Channel identifier (e.g., 'email', 'whatsapp')
     * @param  string  $target  Recipient identifier (email, phone, chat ID)
     * @param  string  $text  Message content
     * @param  array{account_id?: int, subject?: string, media_path?: string, reply_to_message_id?: string}  $options  Channel-specific options
     * @return OutboundSendResult Structured result with durable identifiers
     *
     * @throws ChannelNotAvailableException When the channel adapter is not registered
     * @throws NoChannelAccountException When no enabled account exists for the company/channel
     */
    public function send(int $companyId, string $channel, string $target, string $text, array $options = []): OutboundSendResult
    {
        $adapter = $this->adapterRegistry->resolve($channel);

        if ($adapter === null) {
            throw new ChannelNotAvailableException($channel);
        }

        $accountModel = $this->resolveAccountModel($companyId, $channel, $options['account_id'] ?? null);

        if ($accountModel === null) {
            throw new NoChannelAccountException($channel, $companyId);
        }

        $accountDto = $accountModel->toDto();

        // Validate message length against channel capabilities
        $capabilities = $adapter->capabilities();
        if (mb_strlen($text) > $capabilities->maxMessageLength) {
            return OutboundSendResult::validationFailed(
                'Message exceeds '.$channel.' limit of '.$capabilities->maxMessageLength.' characters.',
            );
        }

        // Dispatch through the transport adapter
        $mediaPath = $options['media_path'] ?? null;
        $sendResult = $mediaPath !== null
            ? $adapter->sendMedia($accountDto, $target, $mediaPath, $text)
            : $adapter->sendText($accountDto, $target, $text, $options);

        // Persist conversation and message records regardless of outcome
        $conversation = $this->findOrCreateConversation($companyId, $channel, $accountModel->id, $target);
        $message = $this->recordOutboundMessage($conversation, $sendResult, $text, $mediaPath);
        $conversation->touchActivity(MessageDirection::Outbound);

        return OutboundSendResult::fromTransport($sendResult, $conversation->id, $message->id);
    }

    /**
     * Reply to an existing conversation message.
     *
     * Resolves the conversation from the original message, sends through
     * the adapter, and persists the reply as a new outbound message.
     *
     * @param  int  $companyId  Owning company ID
     * @param  string  $channel  Channel identifier
     * @param  string  $messageId  Platform message ID to reply to
     * @param  string  $text  Reply content
     * @param  array<string, mixed>  $options  Channel-specific options
     * @return OutboundSendResult Structured result with durable identifiers
     *
     * @throws ChannelNotAvailableException When the channel adapter is not registered
     * @throws NoChannelAccountException When no enabled account exists for the company/channel
     */
    public function reply(int $companyId, string $channel, string $messageId, string $text, array $options = []): OutboundSendResult
    {
        $adapter = $this->adapterRegistry->resolve($channel);

        if ($adapter === null) {
            throw new ChannelNotAvailableException($channel);
        }

        // Find the original message to discover the conversation and target
        $originalMessage = ChannelConversationMessage::query()
            ->where('external_message_id', $messageId)
            ->first();

        if ($originalMessage === null) {
            return OutboundSendResult::validationFailed(
                'Original message "'.$messageId.'" not found. Cannot determine reply target.',
            );
        }

        /** @var ChannelConversation $conversation */
        $conversation = $originalMessage->conversation;
        $accountModel = ChannelAccount::query()->find($conversation->channel_account_id);

        if ($accountModel === null) {
            throw new NoChannelAccountException($channel, $companyId);
        }

        $accountDto = $accountModel->toDto();

        // Determine the reply target from the original conversation participants
        $target = $this->resolveReplyTarget($conversation);

        if ($target === null) {
            return OutboundSendResult::validationFailed(
                'Cannot determine reply target from conversation participants.',
            );
        }

        $sendResult = $adapter->sendText($accountDto, $target, $text, array_merge($options, [
            'reply_to' => $messageId,
        ]));

        $message = $this->recordOutboundMessage($conversation, $sendResult, $text);
        $conversation->touchActivity(MessageDirection::Outbound);

        return OutboundSendResult::fromTransport($sendResult, $conversation->id, $message->id);
    }

    /**
     * Resolve the channel account model for the given company and channel.
     *
     * If an explicit account_id is provided, looks up that specific record.
     * Otherwise, finds the first enabled account for the company/channel.
     *
     * @param  int  $companyId  Company ID
     * @param  string  $channel  Channel identifier
     * @param  int|null  $accountId  Optional specific account ID
     */
    private function resolveAccountModel(int $companyId, string $channel, ?int $accountId): ?ChannelAccount
    {
        $query = ChannelAccount::query()
            ->where('company_id', $companyId)
            ->where('channel', $channel)
            ->where('is_enabled', true);

        if ($accountId !== null) {
            return $query->where('id', $accountId)->first();
        }

        return $query->first();
    }

    /**
     * Find or create a conversation record for the given participants.
     *
     * @param  int  $companyId  Company ID
     * @param  string  $channel  Channel identifier
     * @param  int  $channelAccountId  Account record ID
     * @param  string  $target  Recipient identifier (becomes part of participants)
     */
    private function findOrCreateConversation(int $companyId, string $channel, int $channelAccountId, string $target): ChannelConversation
    {
        return ChannelConversation::query()->firstOrCreate(
            [
                'company_id' => $companyId,
                'channel' => $channel,
                'channel_account_id' => $channelAccountId,
                'external_id' => $target,
            ],
            [
                'participants' => [$target],
            ],
        );
    }

    /**
     * Persist an outbound message record.
     *
     * @param  ChannelConversation  $conversation  Parent conversation
     * @param  SendResult  $sendResult  Transport result
     * @param  string  $text  Message content
     * @param  string|null  $mediaPath  Optional media path
     */
    private function recordOutboundMessage(
        ChannelConversation $conversation,
        SendResult $sendResult,
        string $text,
        ?string $mediaPath = null,
    ): ChannelConversationMessage {
        return ChannelConversationMessage::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'external_message_id' => $sendResult->messageId,
            'content' => $text,
            'media' => $mediaPath !== null ? [['path' => $mediaPath]] : null,
            'delivery_status' => $sendResult->success ? 'sent' : 'failed',
            'sent_at' => $sendResult->success ? now() : null,
            'raw_payload' => $sendResult->success
                ? null
                : ['error' => $sendResult->error],
        ]);
    }

    /**
     * Resolve the reply target from conversation participants.
     *
     * @param  ChannelConversation  $conversation  The conversation to extract target from
     */
    private function resolveReplyTarget(ChannelConversation $conversation): ?string
    {
        // The external_id typically holds the primary participant identifier
        if ($conversation->external_id !== null) {
            return $conversation->external_id;
        }

        $participants = $conversation->participants ?? [];

        return $participants[0] ?? null;
    }
}
