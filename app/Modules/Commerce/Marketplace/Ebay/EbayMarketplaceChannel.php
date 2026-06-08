<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Foundation\ValueObjects\Money;
use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Contracts\MarketplaceChannel;
use App\Modules\Commerce\Marketplace\DTO\MarketplacePullResult;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;
use App\Modules\Commerce\Sales\Services\SalesOrderMaterializer;
use Illuminate\Support\Carbon;

class EbayMarketplaceChannel implements MarketplaceChannel
{
    public function __construct(
        private readonly EbayConfiguration $configuration,
        private readonly EbayOAuthService $oauth,
        private readonly IntegrationGateway $integration,
        private readonly SalesOrderMaterializer $salesOrders,
        private readonly EbayProductReferenceImporter $productReferences,
        private readonly EbayListingOperationService $listingOperations,
        private readonly EbayListingReadinessService $readiness,
        private readonly EbayOrderMapper $orderMapper,
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
                $result = $this->salesOrders->materialize($companyId, $this->orderMapper->orderData($this->key(), $orderPayload));
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
        return $this->listingOperations->createListing($item);
    }

    public function reviseListing(Listing $listing): array
    {
        return $this->listingOperations->reviseListing($listing);
    }

    public function endListing(Listing $listing): array
    {
        return $this->listingOperations->endListing($listing);
    }

    public function refreshListingDraft(Item $item): ListingDraft
    {
        return $this->readiness->refreshForItem($item);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $offer
     * @param  array<string, mixed>  $inventoryItem
     */
    private function upsertOffer(array $config, int $companyId, array $offer, array $inventoryItem): Listing
    {
        $sku = (string) ($offer['sku'] ?? $inventoryItem['sku'] ?? '');
        $listingId = $offer['listing']['listingId'] ?? null;
        $externalKey = $listingId ?? (string) ($offer['offerId'] ?? $sku);
        $webBaseUrl = rtrim((string) $config['web_base_url'], '/');
        $existing = Listing::query()
            ->where('company_id', $companyId)
            ->where('channel', $this->key())
            ->where('external_listing_id', $externalKey)
            ->first();
        $item = $sku !== ''
            ? Item::query()->where('company_id', $companyId)->where('sku', strtoupper($sku))->first()
            : null;
        $price = $offer['pricingSummary']['price']['value'] ?? null;
        $currency = $offer['pricingSummary']['price']['currency'] ?? null;
        $drift = $this->externalDriftState($existing, $offer, $inventoryItem);

        return Listing::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'channel' => $this->key(),
                'external_listing_id' => $externalKey,
            ],
            [
                'item_id' => $item?->id,
                'external_offer_id' => $offer['offerId'] ?? null,
                'external_sku' => $sku !== '' ? strtoupper($sku) : null,
                'marketplace_id' => $offer['marketplaceId'] ?? null,
                'title' => $inventoryItem['product']['title'] ?? null,
                'status' => $offer['listing']['listingStatus'] ?? $offer['status'] ?? null,
                'management_state' => $existing?->management_state ?? Listing::MANAGEMENT_IMPORTED,
                'drift_status' => $drift['status'],
                'drift_summary' => $drift['summary'],
                'price_amount' => is_string($price) && is_string($currency) ? Money::fromDecimalString($price, $currency)?->minorAmount : null,
                'currency_code' => is_string($currency) ? strtoupper($currency) : null,
                'listing_url' => $listingId !== null ? $webBaseUrl.'/itm/'.$listingId : null,
                'last_synced_at' => Carbon::now(),
                'raw_payload' => [
                    'offer' => $offer,
                    'inventory_item' => $inventoryItem,
                ],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $offer
     * @param  array<string, mixed>  $inventoryItem
     * @return array{status: string, summary: string|null}
     */
    private function externalDriftState(?Listing $existing, array $offer, array $inventoryItem): array
    {
        if (! $existing instanceof Listing) {
            return ['status' => Listing::DRIFT_UNKNOWN, 'summary' => null];
        }

        if (! $existing->isBelimbingManaged()) {
            return ['status' => Listing::DRIFT_UNKNOWN, 'summary' => null];
        }

        $contract = $existing->raw_payload['publish_contract'] ?? null;

        if (! is_array($contract)) {
            return ['status' => Listing::DRIFT_UNKNOWN, 'summary' => null];
        }

        $driftedFields = [];
        $expectedTitle = data_get($contract, 'inventory_item.product.title');
        $actualTitle = data_get($inventoryItem, 'product.title');

        if (is_string($expectedTitle) && is_string($actualTitle) && trim($expectedTitle) !== trim($actualTitle)) {
            $driftedFields[] = 'title';
        }

        $expectedPrice = data_get($contract, 'offer.pricingSummary.price.value');
        $actualPrice = data_get($offer, 'pricingSummary.price.value');

        if ((string) $expectedPrice !== '' && (string) $actualPrice !== '' && (string) $expectedPrice !== (string) $actualPrice) {
            $driftedFields[] = 'price';
        }

        $expectedCurrency = data_get($contract, 'offer.pricingSummary.price.currency');
        $actualCurrency = data_get($offer, 'pricingSummary.price.currency');

        if ((string) $expectedCurrency !== '' && (string) $actualCurrency !== '' && strtoupper((string) $expectedCurrency) !== strtoupper((string) $actualCurrency)) {
            $driftedFields[] = 'currency';
        }

        $expectedQuantity = (string) data_get($contract, 'offer.availableQuantity', '');
        $actualQuantity = (string) ($offer['availableQuantity'] ?? data_get($inventoryItem, 'availability.shipToLocationAvailability.quantity', ''));

        if ($expectedQuantity !== '' && $actualQuantity !== '' && $expectedQuantity !== $actualQuantity) {
            $driftedFields[] = 'quantity';
        }

        if ($driftedFields === []) {
            return ['status' => Listing::DRIFT_IN_SYNC, 'summary' => null];
        }

        return [
            'status' => Listing::DRIFT_DRIFTED,
            'summary' => 'Externally changed: '.implode(', ', $driftedFields).'.',
        ];
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
            $listing = $this->upsertOffer($config, $companyId, $offer, $inventoryItem);
            $draft = $this->syncImportedListingDraft($listing);
            $this->productReferences->importFromListing($listing, $draft);
            $listing->wasRecentlyCreated ? $created++ : $updated++;

            if ($listing->item_id !== null) {
                $linked++;
            }
        }

        return compact('fetched', 'created', 'updated', 'linked');
    }

    private function syncImportedListingDraft(Listing $listing): ?ListingDraft
    {
        $item = $listing->item;

        if ($item === null) {
            return null;
        }

        $draft = $this->readiness->refreshForItem($item->fresh());
        $listingAspects = $this->listingAspectValues($listing);

        $draft->update([
            'listing_id' => $listing->id,
            'external_sku' => $listing->external_sku ?? $item->sku,
            'title' => $listing->title ?? $item->title,
            'status' => $listing->isBelimbingManaged() ? ListingDraft::STATUS_PUBLISHED : ListingDraft::STATUS_IMPORTED,
            'management_state' => $listing->management_state,
            'aspect_values' => $listingAspects !== [] ? $listingAspects : $draft->aspect_values,
            'readiness_snapshot' => $this->mergeListingFactsIntoReadinessSnapshot($draft->readiness_snapshot, $listing),
            'publish_intent' => null,
            'last_failure_summary' => null,
        ]);

        return $draft->fresh();
    }

    /**
     * @return array<string, array{value: string|list<string>, source: string}>
     */
    private function listingAspectValues(Listing $listing): array
    {
        $aspects = data_get($listing->raw_payload, 'inventory_item.product.aspects');

        if (! is_array($aspects)) {
            return [];
        }

        return collect($aspects)
            ->mapWithKeys(fn (mixed $value, mixed $name): array => $this->listingAspectValue($value, $name))
            ->all();
    }

    /**
     * @return array<string, array{value: string|list<string>, source: string}>
     */
    private function listingAspectValue(mixed $value, mixed $name): array
    {
        if (! is_string($name) || trim($name) === '') {
            return [];
        }

        if (is_array($value)) {
            return $this->listingAspectArrayValue($value, trim($name));
        }

        if (is_scalar($value) && trim((string) $value) !== '') {
            return [trim($name) => ['value' => trim((string) $value), 'source' => 'ebay_listing']];
        }

        return [];
    }

    /**
     * @param  array<int, mixed>  $value
     * @return array<string, array{value: list<string>, source: string}>
     */
    private function listingAspectArrayValue(array $value, string $name): array
    {
        $normalized = collect($value)
            ->map(fn (mixed $entry): ?string => is_scalar($entry) && trim((string) $entry) !== '' ? trim((string) $entry) : null)
            ->filter()
            ->values()
            ->all();

        return $normalized === []
            ? []
            : [$name => ['value' => $normalized, 'source' => 'ebay_listing']];
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>
     */
    private function mergeListingFactsIntoReadinessSnapshot(?array $snapshot, Listing $listing): array
    {
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $facts = is_array($snapshot['facts'] ?? null) ? $snapshot['facts'] : [];

        $snapshot['facts'] = array_merge($facts, [
            'inventory_api_visible' => $listing->hasInventoryItemSnapshot(),
            'inventory_api_writable' => $listing->hasInventoryApiWritePath(),
            'adoption_state' => $listing->adoptionState(),
        ]);

        return $snapshot;
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
}
