<?php
namespace App\Modules\Core\AI\Services\Messaging\Adapters;

use App\Modules\Core\AI\DTO\Messaging\ChannelCapabilities;

/**
 * WhatsApp channel adapter stub.
 *
 * Provides the contract skeleton for WhatsApp Business API integration.
 * All send methods return failure results until the channel integration
 * is fully configured with webhook routing and DB-backed accounts.
 */
class WhatsAppAdapter extends BaseChannelAdapter
{
    protected function channelKey(): string
    {
        return 'whatsapp';
    }

    protected function channelLabel(): string
    {
        return 'WhatsApp';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            supportsReactions: true,
            supportsMedia: true,
            maxMessageLength: 4096,
            mediaTypes: ['image', 'document', 'audio', 'video'],
        );
    }
}
