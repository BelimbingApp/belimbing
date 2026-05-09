<?php
namespace App\Modules\Core\AI\Services\Messaging\Adapters;

use App\Modules\Core\AI\DTO\Messaging\ChannelCapabilities;

/**
 * Telegram channel adapter stub.
 *
 * Provides the contract skeleton for Telegram Bot API integration.
 * All send methods return failure results until the channel integration
 * is fully configured with webhook routing and DB-backed accounts.
 */
class TelegramAdapter extends BaseChannelAdapter
{
    protected function channelKey(): string
    {
        return 'telegram';
    }

    protected function channelLabel(): string
    {
        return 'Telegram';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            supportsReactions: true,
            supportsEditing: true,
            supportsDeletion: true,
            supportsPolls: true,
            supportsMedia: true,
            maxMessageLength: 4096,
            mediaTypes: ['image', 'document', 'audio', 'video'],
        );
    }
}
