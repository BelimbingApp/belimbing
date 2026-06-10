<?php

use App\Modules\Commerce\Marketplace\Contracts\MarketplaceChannel;
use App\Modules\Commerce\Marketplace\Services\MarketplaceChannelRegistry;

/**
 * Phase D — genericity guard. Adding a channel must mean only "implement
 * MarketplaceChannel + register a descriptor"; nothing else may hard-code eBay.
 * This contract test runs against every registered channel, so a future Shopee
 * or Lazada adapter is held to the same bar. (The availability-sync tests
 * additionally drive a non-eBay fake channel end to end.)
 */
test('every registered marketplace channel satisfies the contract', function (): void {
    $registry = app(MarketplaceChannelRegistry::class);

    expect($registry->all())->not->toBeEmpty();

    foreach ($registry->all() as $key => $descriptor) {
        expect($descriptor->key)->toBe($key)
            ->and(trim($descriptor->label))->not->toBe('');

        $channel = $registry->channel($key);

        expect($channel)->toBeInstanceOf(MarketplaceChannel::class)
            ->and($channel->key())->toBe($key);

        foreach ($descriptor->capabilities as $capability => $enabled) {
            expect($enabled)->toBeBool();
        }
    }
});

test('eBay is registered and advertises the pull/push capabilities', function (): void {
    $descriptor = app(MarketplaceChannelRegistry::class)->descriptor('ebay');

    expect($descriptor->supports('pull_listings'))->toBeTrue()
        ->and($descriptor->supports('pull_orders'))->toBeTrue()
        ->and($descriptor->supports('create_listing'))->toBeTrue()
        ->and($descriptor->supports('revise_listing'))->toBeTrue()
        ->and($descriptor->supports('end_listing'))->toBeTrue()
        ->and($descriptor->supports('not_a_capability'))->toBeFalse();
});
