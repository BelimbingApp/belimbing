<?php

namespace App\Core\AI\Contracts\Messaging;

use App\Core\AI\DTO\Messaging\ChannelAccount;
use App\Core\AI\DTO\Messaging\ChannelCapabilities;
use App\Core\AI\DTO\Messaging\InboundMessage;
use App\Core\AI\DTO\Messaging\SendResult;
use App\Core\AI\Enums\SignalAuthenticityStatus;
use Illuminate\Http\Request;

/**
 * Contract for messaging channel adapter implementations.
 *
 * Each adapter encapsulates the platform-specific logic for a single
 * messaging channel (WhatsApp, Telegram, Slack, etc.). Adapters are
 * registered in the ChannelAdapterRegistry and resolved by channel ID
 * when the MessageTool dispatches actions.
 */
interface ChannelAdapter
{
    /**
     * Channel identifier (e.g., 'whatsapp', 'telegram').
     */
    public function channelId(): string;

    /**
     * Human-readable label (e.g., 'WhatsApp', 'Telegram').
     */
    public function label(): string;

    /**
     * Resolve account config for a company.
     *
     * @param  int  $companyId  The company ID to resolve the account for
     * @param  string|null  $accountId  Optional specific account identifier
     */
    public function resolveAccount(int $companyId, ?string $accountId = null): ?ChannelAccount;

    /**
     * Send a text message.
     *
     * @param  ChannelAccount  $account  The authenticated channel account
     * @param  string  $target  Recipient identifier (phone number, chat ID, etc.)
     * @param  string  $text  Message content
     * @param  array<string, mixed>  $options  Platform-specific options
     */
    public function sendText(ChannelAccount $account, string $target, string $text, array $options = []): SendResult;

    /**
     * Send media (image, document, audio, video).
     *
     * @param  ChannelAccount  $account  The authenticated channel account
     * @param  string  $target  Recipient identifier
     * @param  string  $mediaPath  Path or URL to the media file
     * @param  string|null  $caption  Optional caption for the media
     */
    public function sendMedia(ChannelAccount $account, string $target, string $mediaPath, ?string $caption = null): SendResult;

    /**
     * Process inbound webhook payload.
     *
     * @param  Request  $request  The incoming webhook request
     */
    public function parseInbound(Request $request): ?InboundMessage;

    /**
     * Verify the authenticity of an inbound webhook request.
     *
     * Adapters MUST validate the platform's signature, token, or other
     * authentication mechanism. Return {@see SignalAuthenticityStatus::Verified}
     * when the request is authentic, or {@see SignalAuthenticityStatus::Failed}
     * when verification fails. This is a fail-closed contract: unverified
     * requests must not be processed.
     *
     * @param  Request  $request  The incoming webhook request
     */
    public function verifyAuthenticity(Request $request): SignalAuthenticityStatus;

    /**
     * Supported capabilities for this channel.
     */
    public function capabilities(): ChannelCapabilities;
}
