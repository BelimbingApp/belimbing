<?php
namespace App\Modules\Core\AI\Services\Messaging;

use App\Modules\Core\AI\Contracts\Messaging\ChannelAdapter;

/**
 * Registry for messaging channel adapters.
 *
 * Stores adapter instances and resolves them by channel identifier.
 * Used by the MessageTool to dispatch actions to the correct platform
 * adapter. Adapters are registered at service provider boot time.
 */
class ChannelAdapterRegistry
{
    /** @var array<string, ChannelAdapter> */
    private array $adapters = [];

    /**
     * Register a channel adapter.
     *
     * @param  ChannelAdapter  $adapter  The adapter instance to register
     */
    public function register(ChannelAdapter $adapter): void
    {
        $this->adapters[$adapter->channelId()] = $adapter;
    }

    /**
     * Resolve an adapter by channel identifier.
     *
     * @param  string  $channelId  Channel identifier (e.g., 'whatsapp', 'telegram')
     */
    public function resolve(string $channelId): ?ChannelAdapter
    {
        return $this->adapters[$channelId] ?? null;
    }

    /**
     * List all registered channel identifiers.
     *
     * @return list<string>
     */
    public function channels(): array
    {
        return array_keys($this->adapters);
    }

    /**
     * Check whether a channel is registered and enabled.
     *
     * @param  string  $channelId  Channel identifier
     */
    public function isAvailable(string $channelId): bool
    {
        return isset($this->adapters[$channelId]);
    }
}
