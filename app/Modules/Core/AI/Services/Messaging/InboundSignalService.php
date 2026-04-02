<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Messaging;

use App\Modules\Core\AI\Contracts\Messaging\ChannelAdapter;
use App\Modules\Core\AI\DTO\Messaging\InboundMessage;
use App\Modules\Core\AI\Enums\SignalAuthenticityStatus;
use App\Modules\Core\AI\Models\ChannelAccount;
use App\Modules\Core\AI\Models\InboundSignal;
use Illuminate\Http\Request;

/**
 * Ingests inbound webhook/channel events into durable signal records.
 *
 * Accepts a raw HTTP request, resolves the channel adapter, verifies
 * authenticity, normalizes the payload through the adapter, and persists
 * the result as an InboundSignal record for downstream routing.
 *
 * This service handles normalization only — routing decisions are made
 * by InboundRoutingService after the signal is persisted.
 */
class InboundSignalService
{
    public function __construct(
        private readonly ChannelAdapterRegistry $adapterRegistry,
    ) {}

    /**
     * Ingest an inbound webhook request for the given channel.
     *
     * Normalizes the raw request through the channel adapter and persists
     * the result as a durable InboundSignal record.
     *
     * @param  string  $channel  Channel identifier (e.g., 'email', 'whatsapp')
     * @param  Request  $request  Raw inbound HTTP request
     * @param  int|null  $channelAccountId  Optional specific account ID (from URL)
     * @return InboundSignal|null Persisted signal record, or null if parsing produced no message
     */
    public function ingest(string $channel, Request $request, ?int $channelAccountId = null): ?InboundSignal
    {
        $adapter = $this->adapterRegistry->resolve($channel);

        if ($adapter === null) {
            return $this->persistUnroutableSignal($channel, $request, 'No adapter registered for channel.');
        }

        // Verify authenticity (adapters that don't support verification return 'skipped')
        $authenticity = $this->verifyAuthenticity($adapter, $request);

        if ($authenticity === SignalAuthenticityStatus::Failed) {
            return $this->persistRejectedSignal($channel, $channelAccountId, $request, $authenticity);
        }

        // Normalize through adapter
        $message = $adapter->parseInbound($request);

        if ($message === null) {
            // Adapter could not parse (might be a verification ping, status callback, etc.)
            return $this->persistEmptySignal($channel, $channelAccountId, $request, $authenticity);
        }

        // Resolve channel account if not provided
        if ($channelAccountId === null) {
            $channelAccountId = $this->resolveAccountId($channel, $message);
        }

        return InboundSignal::query()->create([
            'channel' => $channel,
            'channel_account_id' => $channelAccountId,
            'authenticity_status' => $authenticity,
            'sender_identifier' => $message->sender,
            'conversation_identifier' => $message->conversationId,
            'normalized_content' => $message->content,
            'normalized_payload' => $this->buildNormalizedPayload($message),
            'raw_payload' => $this->captureRawPayload($request),
            'received_at' => $message->timestamp ?? now(),
        ]);
    }

    /**
     * Verify request authenticity through the channel adapter.
     *
     * Currently returns 'skipped' for all adapters since none implement
     * verification yet. Real adapters will check signatures, tokens, etc.
     */
    private function verifyAuthenticity(ChannelAdapter $adapter, Request $request): SignalAuthenticityStatus
    {
        // TODO: Add verifyInbound(Request) to ChannelAdapter contract when
        // real channel integrations land. For now, authenticity is skipped.
        return SignalAuthenticityStatus::Skipped;
    }

    /**
     * Resolve channel account ID from the inbound message metadata.
     *
     * @param  string  $channel  Channel identifier
     * @param  InboundMessage  $message  Parsed inbound message
     */
    private function resolveAccountId(string $channel, InboundMessage $message): ?int
    {
        // Try to find an account by matching the channel and conversation metadata
        $account = ChannelAccount::query()
            ->where('channel', $channel)
            ->where('is_enabled', true)
            ->first();

        return $account?->id;
    }

    /**
     * Build a structured normalized payload from the parsed message.
     *
     * @return array<string, mixed>
     */
    private function buildNormalizedPayload(InboundMessage $message): array
    {
        return array_filter([
            'channel_id' => $message->channelId,
            'sender' => $message->sender,
            'content' => $message->content,
            'message_id' => $message->messageId,
            'conversation_id' => $message->conversationId,
            'media' => $message->media !== [] ? $message->media : null,
            'meta' => $message->meta !== [] ? $message->meta : null,
            'timestamp' => $message->timestamp?->format('c'),
        ]);
    }

    /**
     * Capture raw payload from the request for audit purposes.
     *
     * @return array<string, mixed>
     */
    private function captureRawPayload(Request $request): array
    {
        return [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'headers' => $this->safeHeaders($request),
            'body' => $request->all(),
        ];
    }

    /**
     * Extract audit-safe headers (exclude authorization tokens).
     *
     * @return array<string, mixed>
     */
    private function safeHeaders(Request $request): array
    {
        $headers = $request->headers->all();
        $sensitive = ['authorization', 'cookie', 'x-api-key'];

        foreach ($sensitive as $key) {
            if (isset($headers[$key])) {
                $headers[$key] = ['[REDACTED]'];
            }
        }

        return $headers;
    }

    /**
     * Persist a signal that could not be routed due to missing adapter.
     */
    private function persistUnroutableSignal(string $channel, Request $request, string $reason): InboundSignal
    {
        return InboundSignal::query()->create([
            'channel' => $channel,
            'authenticity_status' => SignalAuthenticityStatus::Skipped,
            'normalized_content' => '[unroutable] '.$reason,
            'raw_payload' => $this->captureRawPayload($request),
            'received_at' => now(),
        ]);
    }

    /**
     * Persist a signal that failed authenticity verification.
     */
    private function persistRejectedSignal(
        string $channel,
        ?int $channelAccountId,
        Request $request,
        SignalAuthenticityStatus $authenticity,
    ): InboundSignal {
        return InboundSignal::query()->create([
            'channel' => $channel,
            'channel_account_id' => $channelAccountId,
            'authenticity_status' => $authenticity,
            'normalized_content' => '[rejected] Authenticity verification failed.',
            'raw_payload' => $this->captureRawPayload($request),
            'received_at' => now(),
        ]);
    }

    /**
     * Persist a signal where the adapter could not parse a message.
     */
    private function persistEmptySignal(
        string $channel,
        ?int $channelAccountId,
        Request $request,
        SignalAuthenticityStatus $authenticity,
    ): InboundSignal {
        return InboundSignal::query()->create([
            'channel' => $channel,
            'channel_account_id' => $channelAccountId,
            'authenticity_status' => $authenticity,
            'normalized_content' => null,
            'raw_payload' => $this->captureRawPayload($request),
            'received_at' => now(),
        ]);
    }
}
