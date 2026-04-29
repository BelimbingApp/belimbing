<?php

use App\Base\Integration\Services\OAuthTokenStore;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayMarketplaceChannel;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Services\MarketplaceChannelRegistry;
use App\Modules\Commerce\Sales\Models\Order;
use App\Modules\Commerce\Sales\Models\OrderLine;
use App\Modules\Commerce\Sales\Models\Sale;
use Illuminate\Support\Facades\Http;

test('ebay marketplace page is visible to admins', function (): void {
    $user = createAdminUser();

    $this->actingAs($user)
        ->get(route('commerce.marketplace.ebay.index'))
        ->assertOk()
        ->assertSee('eBay Marketplace');
});

test('ebay listing pull materializes offers and links by sku', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $scope = Scope::company($user->company_id);
    $settings = app(SettingsService::class);
    $settings->set('marketplace.ebay.environment', 'sandbox', $scope);
    $settings->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);
    $settings->set('marketplace.ebay.client_id', 'client-123', $scope);
    $settings->set('marketplace.ebay.client_secret', 'secret-456', $scope, encrypted: true);
    $settings->set('marketplace.ebay.redirect_uri', 'https://blb.test/commerce/marketplace/ebay/oauth/callback', $scope);

    app(OAuthTokenStore::class)->persist(
        EbayConfiguration::CHANNEL,
        $scope,
        [
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
        ],
        ['https://api.ebay.com/oauth/api_scope/sell.inventory'],
    );

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => 'HAM-HEADLIGHT-0001',
    ]);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/inventory/v1/inventory_item*' => Http::response([
            'total' => 1,
            'inventoryItems' => [
                [
                    'sku' => 'HAM-HEADLIGHT-0001',
                    'product' => ['title' => '2008 Honda Civic driver side headlight'],
                ],
            ],
        ]),
        'https://api.sandbox.ebay.com/sell/inventory/v1/offer*' => Http::response([
            'offers' => [
                [
                    'offerId' => 'offer-1',
                    'sku' => 'HAM-HEADLIGHT-0001',
                    'marketplaceId' => 'EBAY_US',
                    'status' => 'PUBLISHED',
                    'listing' => [
                        'listingId' => '1234567890',
                        'listingStatus' => 'ACTIVE',
                    ],
                    'pricingSummary' => [
                        'price' => [
                            'currency' => 'USD',
                            'value' => '120.00',
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $result = app(EbayMarketplaceChannel::class)->pullListings($user->company_id);

    $listing = Listing::query()->where('external_listing_id', '1234567890')->first();

    expect($result->fetched)->toBe(1)
        ->and($result->created)->toBe(1)
        ->and($result->linked)->toBe(1)
        ->and($listing)->not()->toBeNull()
        ->and($listing->item_id)->toBe($item->id)
        ->and($listing->price_amount)->toBe(12000)
        ->and($listing->currency_code)->toBe('USD');
});

test('ebay registers as a marketplace channel provider', function (): void {
    $descriptor = app(MarketplaceChannelRegistry::class)->descriptor(EbayConfiguration::CHANNEL);

    expect($descriptor->key)->toBe(EbayConfiguration::CHANNEL)
        ->and($descriptor->label)->toBe('eBay')
        ->and($descriptor->settingsGroup)->toBe('marketplace_ebay')
        ->and($descriptor->supports('pull_listings'))->toBeTrue()
        ->and($descriptor->supports('create_listing'))->toBeFalse()
        ->and(app(MarketplaceChannelRegistry::class)->channel(EbayConfiguration::CHANNEL))
        ->toBeInstanceOf(EbayMarketplaceChannel::class);
});

test('ebay order pull materializes sales ledger rows and links inventory', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $scope = Scope::company($user->company_id);
    $settings = app(SettingsService::class);
    $settings->set('marketplace.ebay.environment', 'sandbox', $scope);
    $settings->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);
    $settings->set('marketplace.ebay.client_id', 'client-123', $scope);
    $settings->set('marketplace.ebay.client_secret', 'secret-456', $scope, encrypted: true);
    $settings->set('marketplace.ebay.redirect_uri', 'https://blb.test/commerce/marketplace/ebay/oauth/callback', $scope);

    app(OAuthTokenStore::class)->persist(
        EbayConfiguration::CHANNEL,
        $scope,
        [
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
        ],
        ['https://api.ebay.com/oauth/api_scope/sell.fulfillment'],
    );

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => 'HAM-HEADLIGHT-0001',
        'status' => Item::STATUS_LISTED,
        'unit_cost_amount' => 4000,
    ]);

    Listing::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $item->id,
        'channel' => EbayConfiguration::CHANNEL,
        'external_listing_id' => '1234567890',
        'external_offer_id' => 'offer-1',
        'external_sku' => 'HAM-HEADLIGHT-0001',
        'marketplace_id' => 'EBAY_US',
        'title' => '2008 Honda Civic driver side headlight',
        'status' => 'ACTIVE',
        'price_amount' => 12000,
        'currency_code' => 'USD',
        'last_synced_at' => now(),
    ]);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/fulfillment/v1/order*' => Http::response([
            'total' => 1,
            'orders' => [
                [
                    'orderId' => '27-12345-67890',
                    'creationDate' => '2026-04-20T12:34:56.000Z',
                    'lastModifiedDate' => '2026-04-21T12:34:56.000Z',
                    'orderPaymentStatus' => 'PAID',
                    'orderFulfillmentStatus' => 'FULFILLED',
                    'buyer' => [
                        'username' => 'buyer-one',
                        'email' => 'buyer@example.test',
                    ],
                    'pricingSummary' => [
                        'total' => [
                            'currency' => 'USD',
                            'value' => '135.00',
                        ],
                    ],
                    'paymentSummary' => [
                        'payments' => [
                            [
                                'paymentDate' => '2026-04-20T12:40:00.000Z',
                            ],
                        ],
                    ],
                    'lineItems' => [
                        [
                            'lineItemId' => '1001',
                            'legacyItemId' => '1234567890',
                            'sku' => 'HAM-HEADLIGHT-0001',
                            'title' => '2008 Honda Civic driver side headlight',
                            'quantity' => 1,
                            'listingMarketplaceId' => 'EBAY_US',
                            'lineItemCost' => [
                                'currency' => 'USD',
                                'value' => '120.00',
                            ],
                            'total' => [
                                'currency' => 'USD',
                                'value' => '120.00',
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $result = app(EbayMarketplaceChannel::class)->pullOrders($user->company_id);

    $order = Order::query()->where('external_order_id', '27-12345-67890')->first();
    $line = OrderLine::query()->where('external_line_item_id', '1001')->first();
    $sale = Sale::query()->where('external_sale_id', '27-12345-67890:1001')->first();

    expect($result->fetched)->toBe(1)
        ->and($result->created)->toBe(1)
        ->and($result->linked)->toBe(1)
        ->and($order)->not()->toBeNull()
        ->and($order->total_amount)->toBe(13500)
        ->and($line)->not()->toBeNull()
        ->and($line->item_id)->toBe($item->id)
        ->and($line->line_total_amount)->toBe(12000)
        ->and($sale)->not()->toBeNull()
        ->and($sale->item_id)->toBe($item->id)
        ->and($sale->sale_amount)->toBe(12000)
        ->and($sale->cost_basis_amount)->toBe(4000)
        ->and($item->refresh()->status)->toBe(Item::STATUS_SOLD);

    $secondResult = app(EbayMarketplaceChannel::class)->pullOrders($user->company_id);

    expect($secondResult->created)->toBe(0)
        ->and($secondResult->updated)->toBe(1)
        ->and(Order::query()->count())->toBe(1)
        ->and(OrderLine::query()->count())->toBe(1)
        ->and(Sale::query()->count())->toBe(1);
});
