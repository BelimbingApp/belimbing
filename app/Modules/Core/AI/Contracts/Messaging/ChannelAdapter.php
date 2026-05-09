<?php
namespace App\Modules\Core\AI\Contracts\Messaging;

use App\Modules\Core\AI\DTO\Messaging\ChannelAccount;
use App\Modules\Core\AI\DTO\Messaging\ChannelCapabilities;
use App\Modules\Core\AI\DTO\Messaging\InboundMessage;
use App\Modules\Core\AI\DTO\Messaging\SendResult;
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
     * Supported capabilities for this channel.
     */
    public function capabilities(): ChannelCapabilities;
}
