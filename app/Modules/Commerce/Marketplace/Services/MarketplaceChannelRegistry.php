<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Marketplace\Services;

use App\Modules\Commerce\Marketplace\Contracts\MarketplaceChannel;
use App\Modules\Commerce\Marketplace\DTO\MarketplaceChannelDescriptor;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;
use Illuminate\Contracts\Container\Container;

class MarketplaceChannelRegistry
{
    /**
     * @var array<string, MarketplaceChannelDescriptor>
     */
    private array $channels = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(MarketplaceChannelDescriptor $descriptor): void
    {
        $this->channels[$descriptor->key] = $descriptor;
    }

    /**
     * @return array<string, MarketplaceChannelDescriptor>
     */
    public function all(): array
    {
        return $this->channels;
    }

    public function descriptor(string $channel): MarketplaceChannelDescriptor
    {
        return $this->channels[$channel]
            ?? throw MarketplaceOperationException::missingChannel($channel);
    }

    public function channel(string $channel): MarketplaceChannel
    {
        $resolved = $this->container->make($this->descriptor($channel)->channelClass);

        if (! $resolved instanceof MarketplaceChannel) {
            throw MarketplaceOperationException::invalidChannel($channel, $resolved::class);
        }

        return $resolved;
    }
}
