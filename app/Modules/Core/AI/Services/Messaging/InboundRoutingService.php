<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Messaging;

use App\Modules\Core\AI\Enums\MessageDirection;
use App\Modules\Core\AI\Enums\SignalAuthenticityStatus;
use App\Modules\Core\AI\Models\Conversation;
use App\Modules\Core\AI\Models\ConversationMessage;
use App\Modules\Core\AI\Models\InboundSignal;

/**
 * Routes normalized inbound signals to conversations and domain work.
 *
 * After InboundSignalService creates a durable signal record, this
 * service decides how the signal maps to the BLB domain:
 *
 * 1. Assigns the signal to an existing or new conversation
 * 2. Creates a ConversationMessage record for the inbound content
 * 3. Marks the signal as routed with any resulting operation ID
 *
 * Future extensions will add domain workflow routing (e.g., auto-assign
 * to an agent dispatch based on conversation rules, create IT tickets
 * from inbound messages, etc.). For now, the routing terminates at
 * conversation assignment.
 */
class InboundRoutingService
{
    /**
     * Route an inbound signal to a conversation.
     *
     * Finds or creates the appropriate conversation, persists the
     * inbound message, and marks the signal as routed.
     *
     * @param  InboundSignal  $signal  Persisted and normalized inbound signal
     * @return RoutingOutcome Structured result describing what happened
     */
    public function route(InboundSignal $signal): RoutingOutcome
    {
        // Skip signals that failed authenticity or have no content
        if ($signal->authenticity_status === SignalAuthenticityStatus::Failed) {
            $signal->markRouted();

            return RoutingOutcome::rejected('Signal failed authenticity verification.');
        }

        if ($signal->normalized_content === null && $signal->normalized_payload === null) {
            $signal->markRouted();

            return RoutingOutcome::skipped('No parseable content in signal.');
        }

        // Resolve or create conversation
        $conversation = $this->resolveConversation($signal);
        $message = $this->persistInboundMessage($conversation, $signal);
        $conversation->touchActivity(MessageDirection::Inbound);

        // Mark signal as routed (no dispatch operation yet — future work)
        $signal->markRouted();

        return RoutingOutcome::routed($conversation->id, $message->id);
    }

    /**
     * Find or create a conversation matching the inbound signal.
     *
     * Uses the channel + account + sender/conversation identifier as
     * the deduplication key.
     */
    private function resolveConversation(InboundSignal $signal): Conversation
    {
        $externalId = $signal->conversation_identifier ?? $signal->sender_identifier;

        // Resolve company_id from the channel account
        $companyId = $signal->channelAccount?->company_id;

        if ($companyId === null) {
            // Fallback: use a sentinel company ID for orphaned signals.
            // This should not happen in practice — channel accounts should
            // always be company-scoped.
            $companyId = 1;
        }

        return Conversation::query()->firstOrCreate(
            [
                'company_id' => $companyId,
                'channel' => $signal->channel,
                'channel_account_id' => $signal->channel_account_id,
                'external_id' => $externalId,
            ],
            [
                'participants' => array_filter([$signal->sender_identifier]),
            ],
        );
    }

    /**
     * Persist the inbound message content as a ConversationMessage.
     */
    private function persistInboundMessage(Conversation $conversation, InboundSignal $signal): ConversationMessage
    {
        $normalizedPayload = $signal->normalized_payload ?? [];

        return ConversationMessage::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'external_message_id' => $normalizedPayload['message_id'] ?? null,
            'content' => $signal->normalized_content,
            'media' => isset($normalizedPayload['media']) ? $normalizedPayload['media'] : null,
            'delivery_status' => 'received',
            'sent_at' => $signal->received_at,
            'raw_payload' => $signal->raw_payload,
        ]);
    }
}
