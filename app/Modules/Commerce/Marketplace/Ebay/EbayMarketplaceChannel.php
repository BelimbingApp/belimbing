<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Foundation\ValueObjects\Money;
use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Contracts\MarketplaceChannel;
use App\Modules\Commerce\Marketplace\DTO\MarketplacePullResult;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Sales\DTO\SalesOrderData;
use App\Modules\Commerce\Sales\DTO\SalesOrderLineData;
use App\Modules\Commerce\Sales\Services\SalesOrderMaterializer;
use Illuminate\Support\Carbon;

class EbayMarketplaceChannel implements MarketplaceChannel
{
    public function __construct(
        private readonly EbayConfiguration $configuration,
        private readonly EbayOAuthService $oauth,
        private readonly IntegrationGateway $integration,
        private readonly SalesOrderMaterializer $salesOrders,
    ) {}

    public function key(): string
    {
        return EbayConfiguration::CHANNEL;
    }

    public function pullListings(int $companyId): MarketplacePullResult
    {
        $config = $this->configuration->forCompany($companyId);
        $accessToken = $this->oauth->accessToken($companyId);
        $marketplaceId = (string) $config['marketplace_id'];

        $fetched = 0;
        $created = 0;
        $updated = 0;
        $linked = 0;
        $offset = 0;
        $limit = 100;

        do {
            $inventoryResponse = $this->ebayGet(
                config: $config,
                companyId: $companyId,
                accessToken: $accessToken,
                operation: 'listings.inventory_items.pull',
                path: '/sell/inventory/v1/inventory_item',
                query: [
                    'limit' => $limit,
                    'offset' => $offset,
                ],
                headers: ['X-EBAY-C-MARKETPLACE-ID' => $marketplaceId],
            );

            $inventoryItems = $inventoryResponse['inventoryItems'] ?? [];

            foreach ($inventoryItems as $inventoryItem) {
                $delta = $this->processInventoryItemOffers(
                    config: $config,
                    companyId: $companyId,
                    accessToken: $accessToken,
                    inventoryItem: $inventoryItem,
                    marketplaceId: $marketplaceId,
                );

                $fetched += $delta['fetched'];
                $created += $delta['created'];
                $updated += $delta['updated'];
                $linked += $delta['linked'];
            }

            $total = (int) ($inventoryResponse['total'] ?? count($inventoryItems));
            $offset += $limit;
        } while ($offset < $total);

        return new MarketplacePullResult($this->key(), $fetched, $created, $updated, $linked);
    }

    public function pullOrders(int $companyId): MarketplacePullResult
    {
        $config = $this->configuration->forCompany($companyId);
        $accessToken = $this->oauth->accessToken($companyId);

        $fetched = 0;
        $created = 0;
        $updated = 0;
        $linked = 0;
        $offset = 0;
        $limit = 100;

        do {
            $response = $this->ebayGet(
                config: $config,
                companyId: $companyId,
                accessToken: $accessToken,
                operation: 'orders.pull',
                path: '/sell/fulfillment/v1/order',
                query: [
                    'limit' => $limit,
                    'offset' => $offset,
                ],
            );

            $orders = $response['orders'] ?? [];

            foreach ($orders as $orderPayload) {
                $fetched++;
                $result = $this->salesOrders->materialize($companyId, $this->salesOrderData($orderPayload));
                $result->created ? $created++ : $updated++;
                $linked += $result->linkedCount;
            }

            $total = (int) ($response['total'] ?? count($orders));
            $offset += $limit;
        } while ($offset < $total);

        return new MarketplacePullResult(
            $this->key(),
            $fetched,
            $created,
            $updated,
            $linked,
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

    /**
     * @param  array<string, mixed>  $inventoryItem
     * @return array{fetched: int, created: int, updated: int, linked: int}
     */
    private function processInventoryItemOffers(
        array $config,
        int $companyId,
        string $accessToken,
        array $inventoryItem,
        string $marketplaceId,
    ): array {
        $sku = (string) ($inventoryItem['sku'] ?? '');
        if ($sku === '') {
            return ['fetched' => 0, 'created' => 0, 'updated' => 0, 'linked' => 0];
        }

        $offerResponse = $this->ebayGet(
            config: $config,
            companyId: $companyId,
            accessToken: $accessToken,
            operation: 'listings.offers.pull',
            path: '/sell/inventory/v1/offer',
            query: [
                'sku' => $sku,
                'marketplace_id' => $marketplaceId,
            ],
            headers: ['X-EBAY-C-MARKETPLACE-ID' => $marketplaceId],
        );

        $fetched = 0;
        $created = 0;
        $updated = 0;
        $linked = 0;

        foreach ($offerResponse['offers'] ?? [] as $offer) {
            $fetched++;
            $listing = $this->upsertOffer($companyId, $offer, $inventoryItem);
            $listing->wasRecentlyCreated ? $created++ : $updated++;

            if ($listing->item_id !== null) {
                $linked++;
            }
        }

        return compact('fetched', 'created', 'updated', 'linked');
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function ebayGet(
        array $config,
        int $companyId,
        string $accessToken,
        string $operation,
        string $path,
        array $query = [],
        array $headers = [],
    ): array {
        $response = $this->integration->send(new IntegrationRequest(
            system: EbayConfiguration::CHANNEL,
            operation: 'commerce.marketplace.ebay.'.$operation,
            method: 'GET',
            endpoint: rtrim((string) $config['api_base_url'], '/').$path,
            protocolOperation: 'GET '.$path,
            provider: EbayConfiguration::CHANNEL,
            headers: array_merge(['Authorization' => 'Bearer '.$accessToken], $headers),
            query: $query,
            ownerType: 'company',
            ownerId: $companyId,
            timeoutSeconds: 30,
            retryTimes: 1,
            metadata: ['marketplace_id' => $config['marketplace_id'] ?? null],
        ));

        if ($response->failed()) {
            throw MarketplaceOperationException::requestFailed(
                $this->key(),
                $operation,
                $response->status,
                $response->exchange?->id,
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function salesOrderData(array $order): SalesOrderData
    {
        $total = $this->amount($order['pricingSummary']['total'] ?? null);
        $lineItems = $order['lineItems'] ?? [];

        return new SalesOrderData(
            channel: $this->key(),
            externalOrderId: (string) ($order['orderId'] ?? ''),
            marketplaceId: $this->orderMarketplaceId($order),
            buyerUsername: $this->nullableString($order['buyer']['username'] ?? null),
            buyerEmail: $this->nullableString($order['buyer']['email'] ?? $order['buyer']['buyerRegistrationAddress']['email'] ?? null),
            status: $this->nullableString($order['orderPaymentStatus'] ?? $order['orderFulfillmentStatus'] ?? null),
            orderedAt: $this->date($order['creationDate'] ?? null),
            paidAt: $this->paymentDate($order),
            fulfilledAt: ($order['orderFulfillmentStatus'] ?? null) === 'FULFILLED'
                ? $this->date($order['lastModifiedDate'] ?? null)
                : null,
            totalAmount: $total?->minorAmount,
            currencyCode: $total?->currencyCode,
            lines: array_map(fn (array $lineItem): SalesOrderLineData => $this->salesOrderLineData($lineItem), $lineItems),
            rawPayload: $order,
        );
    }

    /**
     * @param  array<string, mixed>  $lineItem
     */
    private function salesOrderLineData(array $lineItem): SalesOrderLineData
    {
        $unitPrice = $this->amount($lineItem['lineItemCost'] ?? null);
        $lineTotal = $this->amount($lineItem['total'] ?? null) ?? $unitPrice;

        return new SalesOrderLineData(
            externalLineItemId: $this->nullableString($lineItem['lineItemId'] ?? null),
            externalListingId: $this->nullableString($lineItem['legacyItemId'] ?? $lineItem['listingId'] ?? $lineItem['itemId'] ?? null),
            externalSku: $this->nullableString($lineItem['sku'] ?? null),
            title: $this->nullableString($lineItem['title'] ?? null),
            quantity: max(1, (int) ($lineItem['quantity'] ?? 1)),
            unitPriceAmount: $unitPrice?->minorAmount,
            lineTotalAmount: $lineTotal?->minorAmount,
            currencyCode: $lineTotal?->currencyCode ?? $unitPrice?->currencyCode,
            rawPayload: $lineItem,
        );
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function orderMarketplaceId(array $order): ?string
    {
        foreach ($order['lineItems'] ?? [] as $lineItem) {
            $marketplaceId = $this->nullableString($lineItem['listingMarketplaceId'] ?? null);

            if ($marketplaceId !== null) {
                return $marketplaceId;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function paymentDate(array $order): ?Carbon
    {
        foreach ($order['paymentSummary']['payments'] ?? [] as $payment) {
            $paidAt = $this->date($payment['paymentDate'] ?? null);

            if ($paidAt !== null) {
                return $paidAt;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|mixed  $amount
     */
    private function amount(mixed $amount): ?Money
    {
        if (! is_array($amount)) {
            return null;
        }

        $value = $this->nullableString($amount['value'] ?? null);
        $currency = $this->nullableString($amount['currency'] ?? null);

        return $value !== null && $currency !== null
            ? Money::fromDecimalString($value, $currency)
            : null;
    }

    private function date(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
