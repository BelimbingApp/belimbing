<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Foundation\ValueObjects\Money;
use App\Base\Integration\Services\IntegrationHttpClientFactory;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Contracts\MarketplaceChannel;
use App\Modules\Commerce\Marketplace\DTO\MarketplacePullResult;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;
use App\Modules\Commerce\Marketplace\Models\Listing;
use Illuminate\Support\Carbon;

class EbayMarketplaceChannel implements MarketplaceChannel
{
    public function __construct(
        private readonly EbayConfiguration $configuration,
        private readonly EbayOAuthService $oauth,
        private readonly IntegrationHttpClientFactory $http,
    ) {}

    public function key(): string
    {
        return EbayConfiguration::CHANNEL;
    }

    public function pullListings(int $companyId): MarketplacePullResult
    {
        $config = $this->configuration->forCompany($companyId);
        $client = $this->http->json($config['api_base_url'], $this->oauth->accessToken($companyId))
            ->withHeaders(['X-EBAY-C-MARKETPLACE-ID' => $config['marketplace_id']]);

        $fetched = 0;
        $created = 0;
        $updated = 0;
        $linked = 0;
        $offset = 0;
        $limit = 100;

        do {
            $inventoryResponse = $client->get('/sell/inventory/v1/inventory_item', [
                'limit' => $limit,
                'offset' => $offset,
            ])->throw()->json();

            $inventoryItems = $inventoryResponse['inventoryItems'] ?? [];

            foreach ($inventoryItems as $inventoryItem) {
                $sku = (string) ($inventoryItem['sku'] ?? '');
                if ($sku === '') {
                    continue;
                }

                $offerResponse = $client->get('/sell/inventory/v1/offer', [
                    'sku' => $sku,
                    'marketplace_id' => $config['marketplace_id'],
                ])->throw()->json();

                foreach ($offerResponse['offers'] ?? [] as $offer) {
                    $fetched++;
                    $listing = $this->upsertOffer($companyId, $offer, $inventoryItem);
                    $listing->wasRecentlyCreated ? $created++ : $updated++;

                    if ($listing->item_id !== null) {
                        $linked++;
                    }
                }
            }

            $total = (int) ($inventoryResponse['total'] ?? count($inventoryItems));
            $offset += $limit;
        } while ($offset < $total);

        return new MarketplacePullResult($this->key(), $fetched, $created, $updated, $linked);
    }

    public function pullOrders(int $companyId): MarketplacePullResult
    {
        $config = $this->configuration->forCompany($companyId);
        $response = $this->http->json($config['api_base_url'], $this->oauth->accessToken($companyId))
            ->get('/sell/fulfillment/v1/order', ['limit' => 50])
            ->throw()
            ->json();

        return new MarketplacePullResult(
            $this->key(),
            (int) ($response['total'] ?? count($response['orders'] ?? [])),
            0,
            0,
            0,
            [__('Order persistence waits for the Commerce Sales schema slice.')],
        );
    }

    public function createListing(Item $item): array
    {
        throw MarketplaceOperationException::writePathNotEnabled($this->key());
    }

    public function reviseListing(Listing $listing): array
    {
        throw MarketplaceOperationException::writePathNotEnabled($this->key());
    }

    public function endListing(Listing $listing): array
    {
        throw MarketplaceOperationException::writePathNotEnabled($this->key());
    }

    /**
     * @param  array<string, mixed>  $offer
     * @param  array<string, mixed>  $inventoryItem
     */
    private function upsertOffer(int $companyId, array $offer, array $inventoryItem): Listing
    {
        $sku = (string) ($offer['sku'] ?? $inventoryItem['sku'] ?? '');
        $listingId = $offer['listing']['listingId'] ?? null;
        $item = $sku !== ''
            ? Item::query()->where('company_id', $companyId)->where('sku', strtoupper($sku))->first()
            : null;
        $price = $offer['pricingSummary']['price']['value'] ?? null;
        $currency = $offer['pricingSummary']['price']['currency'] ?? null;

        return Listing::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'channel' => $this->key(),
                'external_listing_id' => $listingId ?? (string) ($offer['offerId'] ?? $sku),
            ],
            [
                'item_id' => $item?->id,
                'external_offer_id' => $offer['offerId'] ?? null,
                'external_sku' => $sku !== '' ? strtoupper($sku) : null,
                'marketplace_id' => $offer['marketplaceId'] ?? null,
                'title' => $inventoryItem['product']['title'] ?? null,
                'status' => $offer['listing']['listingStatus'] ?? $offer['status'] ?? null,
                'price_amount' => is_string($price) && is_string($currency) ? Money::fromDecimalString($price, $currency)?->minorAmount : null,
                'currency_code' => is_string($currency) ? strtoupper($currency) : null,
                'listing_url' => $listingId !== null ? 'https://www.ebay.com/itm/'.$listingId : null,
                'last_synced_at' => Carbon::now(),
                'raw_payload' => [
                    'offer' => $offer,
                    'inventory_item' => $inventoryItem,
                ],
            ],
        );
    }
}
