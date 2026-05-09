<?php
namespace App\Modules\Core\AI\Services\Messaging\Adapters;

use App\Modules\Core\AI\DTO\Messaging\ChannelCapabilities;

/**
 * Slack channel adapter stub.
 *
 * Provides the contract skeleton for Slack Web API integration.
 * All send methods return failure results until the channel integration
 * is fully configured with webhook routing and DB-backed accounts.
 */
class SlackAdapter extends BaseChannelAdapter
{
    protected function channelKey(): string
    {
        return 'slack';
    }

    protected function channelLabel(): string
    {
        return 'Slack';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            supportsReactions: true,
            supportsEditing: true,
            supportsDeletion: true,
            supportsThreads: true,
            supportsMedia: true,
            supportsSearch: true,
            maxMessageLength: 40000,
            mediaTypes: ['image', 'document'],
        );
    }
}
