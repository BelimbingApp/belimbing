<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use App\Base\Integration\Services\IntegrationResponse;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class EbayListingOperationService
{
    private const INVENTORY_ITEM_PATH = '/sell/inventory/v1/inventory_item/';

    private const OFFER_PATH = '/sell/inventory/v1/offer/';

    public function __construct(
        private readonly EbayConfiguration $configuration,
        private readonly EbayOAuthService $oauth,
        private readonly IntegrationGateway $integration,
        private readonly EbayListingReadinessService $readiness,
        private readonly EbayListingPayloadBuilder $payloads,
        private readonly EbayProductReferenceImporter $productReferences,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function createListing(Item $item): array
    {
        $draft = $this->readyDraft($item, 'publish');
        $existingListing = $draft->listing ?? $item->marketplaceListings()
            ->where('channel', EbayConfiguration::CHANNEL)
            ->latest('updated_at')
            ->first();

        return $this->publishDraft($draft, $existingListing);
    }

    /**
     * @return array<string, mixed>
     */
    public function reviseListing(Listing $listing): array
    {
        $listing->loadMissing('item');

        if ($listing->item === null) {
            throw MarketplaceOperationException::listingNotWritable(
                EbayConfiguration::CHANNEL,
                $listing->id,
                'Linked inventory item is missing.',
            );
        }

        if ($listing->external_offer_id === null || trim($listing->external_offer_id) === '') {
            throw MarketplaceOperationException::listingNotWritable(
                EbayConfiguration::CHANNEL,
                $listing->id,
                'Missing eBay offer id.',
            );
        }

        $draft = $this->readyDraft($listing->item, 'revise');
        $payload = $this->payloads->build($draft);
        $config = $this->configuration->forCompany($listing->company_id);
        $accessToken = $this->oauth->accessToken($listing->company_id);
        $operationLog = [];

        try {
            $operationLog[] = $this->upsertInventoryItem($listing->company_id, $config, $accessToken, $payload);
            $operationLog[] = $this->syncCompatibility($listing->company_id, $config, $accessToken, $payload, deleteWhenEmpty: true);
            $operationLog[] = $this->updateOffer($listing->company_id, $config, $accessToken, $payload, (string) $listing->external_offer_id);
        } catch (MarketplaceOperationException $exception) {
            $this->recordDraftFailure($draft, $exception);

            throw $exception;
        }

        $listing = $this->storeListing(
            draft: $draft,
            existing: $listing,
            operationLog: $operationLog,
            publication: [
                'external_listing_id' => $listing->external_listing_id,
                'external_offer_id' => (string) $listing->external_offer_id,
                'status' => 'ACTIVE',
                'listed_at' => $listing->listed_at ?? Carbon::now(),
                'ended_at' => null,
            ],
        );

        $this->markSuccess($draft, $listing, 'published');

        return [
            'draft_id' => $draft->id,
            'listing_id' => $listing->id,
            'external_listing_id' => $listing->external_listing_id,
            'external_offer_id' => $listing->external_offer_id,
            'operations' => $operationLog,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function endListing(Listing $listing): array
    {
        $listing->loadMissing('item');

        if ($listing->external_offer_id === null || trim($listing->external_offer_id) === '') {
            throw MarketplaceOperationException::listingNotWritable(
                EbayConfiguration::CHANNEL,
                $listing->id,
                'Missing eBay offer id.',
            );
        }

        $config = $this->configuration->forCompany($listing->company_id);
        $accessToken = $this->oauth->accessToken($listing->company_id);
        $operation = $this->withdrawOffer(
            companyId: $listing->company_id,
            config: $config,
            accessToken: $accessToken,
            offerId: (string) $listing->external_offer_id,
        );

        $listing->update([
            'status' => 'UNPUBLISHED',
            'ended_at' => Carbon::now(),
            'last_synced_at' => Carbon::now(),
            'raw_payload' => array_filter([
                ...($listing->raw_payload ?? []),
                'last_operation' => [
                    'type' => 'withdraw',
                    'exchange_id' => $operation['exchange_id'] ?? null,
                    'status' => $operation['http_status'] ?? null,
                    'warnings' => $operation['warnings'] ?? [],
                    'listing_id' => $operation['listing_id'] ?? $listing->external_listing_id,
                ],
            ]),
        ]);

        if ($listing->item !== null && $listing->item->status === Item::STATUS_LISTED) {
            $listing->item->update(['status' => Item::STATUS_READY]);
        }

        ListingDraft::query()
            ->where('company_id', $listing->company_id)
            ->where('channel', EbayConfiguration::CHANNEL)
            ->where(function ($query) use ($listing): void {
                $query->where('listing_id', $listing->id);

                if ($listing->item_id !== null) {
                    $query->orWhere('item_id', $listing->item_id);
                }
            })
            ->update([
                'status' => 'withdrawn',
                'publish_intent' => 'withdraw',
                'last_failure_summary' => null,
            ]);

        return [
            'listing_id' => $listing->id,
            'external_listing_id' => $listing->external_listing_id,
            'external_offer_id' => $listing->external_offer_id,
            'operations' => [$operation],
        ];
    }

    private function readyDraft(Item $item, string $intent): ListingDraft
    {
        $draft = $this->readiness->refreshForItem($item->fresh());

        if ($draft->readiness_status !== EbayListingReadinessService::STATUS_READY) {
            throw MarketplaceOperationException::draftNotReady(
                EbayConfiguration::CHANNEL,
                $draft->id,
                $draft->readiness_snapshot['blockers'] ?? [],
            );
        }

        $draft->update([
            'publish_intent' => $intent,
            'last_failure_summary' => null,
        ]);

        return $draft->fresh(['item', 'listing']);
    }

    /**
     * @return array<string, mixed>
     */
    private function publishDraft(ListingDraft $draft, ?Listing $existingListing): array
    {
        $payload = $this->payloads->build($draft);
        $config = $this->configuration->forCompany($draft->company_id);
        $accessToken = $this->oauth->accessToken($draft->company_id);
        $operationLog = [];

        try {
            $operationLog[] = $this->upsertInventoryItem($draft->company_id, $config, $accessToken, $payload);
            $operationLog[] = $this->syncCompatibility(
                $draft->company_id,
                $config,
                $accessToken,
                $payload,
                deleteWhenEmpty: $existingListing !== null,
            );

            $offerId = $existingListing?->external_offer_id;
            if ($offerId !== null && trim($offerId) !== '') {
                $operationLog[] = $this->updateOffer($draft->company_id, $config, $accessToken, $payload, $offerId);
            } else {
                $offerOperation = $this->createOffer($draft->company_id, $config, $accessToken, $payload);
                $operationLog[] = $offerOperation;
                $offerId = $offerOperation['offer_id'];
            }

            $publishOperation = $this->publishOffer($draft->company_id, $config, $accessToken, (string) $offerId);
            $operationLog[] = $publishOperation;
        } catch (MarketplaceOperationException $exception) {
            $this->recordDraftFailure($draft, $exception);

            throw $exception;
        }

        $listing = $this->storeListing(
            draft: $draft,
            existing: $existingListing,
            operationLog: $operationLog,
            publication: [
                'external_listing_id' => (string) ($publishOperation['listing_id'] ?? $existingListing?->external_listing_id ?? ''),
                'external_offer_id' => (string) $offerId,
                'status' => 'ACTIVE',
                'listed_at' => Carbon::now(),
                'ended_at' => null,
            ],
        );

        $this->markSuccess($draft, $listing, 'published');

        return [
            'draft_id' => $draft->id,
            'listing_id' => $listing->id,
            'external_listing_id' => $listing->external_listing_id,
            'external_offer_id' => $listing->external_offer_id,
            'operations' => $operationLog,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function upsertInventoryItem(int $companyId, array $config, string $accessToken, array $payload): array
    {
        $sku = (string) ($payload['sku'] ?? '');
        $response = $this->request(
            companyId: $companyId,
            config: $config,
            accessToken: $accessToken,
            request: [
                'operation' => 'listing.inventory_item.upsert',
                'method' => 'PUT',
                'path' => self::INVENTORY_ITEM_PATH.rawurlencode($sku),
                'body' => $payload['inventory_item'] ?? [],
                'headers' => [
                    'Content-Language' => 'en-US',
                ],
            ],
        );

        return $this->operationResult('inventory_item_upsert', $response);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function syncCompatibility(int $companyId, array $config, string $accessToken, array $payload, bool $deleteWhenEmpty): array
    {
        $sku = (string) ($payload['sku'] ?? '');
        $applications = $payload['compatibility']['applications'] ?? [];
        $universal = (bool) ($payload['compatibility']['universal'] ?? false);

        if ($applications === [] || $universal) {
            if (! $deleteWhenEmpty) {
                return [
                    'name' => 'compatibility_skip',
                    'http_status' => null,
                    'warnings' => [],
                ];
            }

            $response = $this->request(
                companyId: $companyId,
                config: $config,
                accessToken: $accessToken,
                request: [
                    'operation' => 'listing.compatibility.delete',
                    'method' => 'DELETE',
                    'path' => self::INVENTORY_ITEM_PATH.rawurlencode($sku).'/product_compatibility',
                ],
            );

            return $this->operationResult('compatibility_delete', $response);
        }

        $response = $this->request(
            companyId: $companyId,
            config: $config,
            accessToken: $accessToken,
            request: [
                'operation' => 'listing.compatibility.upsert',
                'method' => 'PUT',
                'path' => self::INVENTORY_ITEM_PATH.rawurlencode($sku).'/product_compatibility',
                'body' => [
                    'compatibleProducts' => $applications,
                ],
                'headers' => [
                    'Content-Language' => 'en-US',
                ],
            ],
        );

        return $this->operationResult('compatibility_upsert', $response);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createOffer(int $companyId, array $config, string $accessToken, array $payload): array
    {
        $response = $this->request(
            companyId: $companyId,
            config: $config,
            accessToken: $accessToken,
            request: [
                'operation' => 'listing.offer.create',
                'method' => 'POST',
                'path' => '/sell/inventory/v1/offer',
                'body' => $payload['offer'] ?? [],
                'headers' => [
                    'Content-Language' => 'en-US',
                ],
            ],
        );

        $result = $this->operationResult('offer_create', $response);
        $result['offer_id'] = (string) $response->json('offerId', '');

        return $result;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function updateOffer(int $companyId, array $config, string $accessToken, array $payload, string $offerId): array
    {
        $response = $this->request(
            companyId: $companyId,
            config: $config,
            accessToken: $accessToken,
            request: [
                'operation' => 'listing.offer.update',
                'method' => 'PUT',
                'path' => self::OFFER_PATH.rawurlencode($offerId),
                'body' => $payload['offer'] ?? [],
                'headers' => [
                    'Content-Language' => 'en-US',
                ],
            ],
        );

        return [
            ...$this->operationResult('offer_update', $response),
            'offer_id' => $offerId,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function publishOffer(int $companyId, array $config, string $accessToken, string $offerId): array
    {
        $response = $this->request(
            companyId: $companyId,
            config: $config,
            accessToken: $accessToken,
            request: [
                'operation' => 'listing.offer.publish',
                'method' => 'POST',
                'path' => self::OFFER_PATH.rawurlencode($offerId).'/publish',
            ],
        );

        return [
            ...$this->operationResult('offer_publish', $response),
            'offer_id' => $offerId,
            'listing_id' => $response->json('listingId'),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function withdrawOffer(int $companyId, array $config, string $accessToken, string $offerId): array
    {
        $response = $this->request(
            companyId: $companyId,
            config: $config,
            accessToken: $accessToken,
            request: [
                'operation' => 'listing.offer.withdraw',
                'method' => 'POST',
                'path' => self::OFFER_PATH.rawurlencode($offerId).'/withdraw',
            ],
        );

        return [
            ...$this->operationResult('offer_withdraw', $response),
            'offer_id' => $offerId,
            'listing_id' => $response->json('listingId'),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{operation: string, method: string, path: string, body?: array<string, mixed>, headers?: array<string, string>}  $request
     */
    private function request(
        int $companyId,
        array $config,
        string $accessToken,
        array $request,
    ): IntegrationResponse {
        $operation = $request['operation'];
        $method = $request['method'];
        $path = $request['path'];
        $response = $this->integration->send(new IntegrationRequest(
            system: EbayConfiguration::CHANNEL,
            operation: 'commerce.marketplace.ebay.'.$operation,
            method: $method,
            endpoint: rtrim((string) $config['api_base_url'], '/').$path,
            protocolOperation: strtoupper($method).' '.$path,
            provider: EbayConfiguration::CHANNEL,
            headers: array_merge([
                'Authorization' => 'Bearer '.$accessToken,
            ], $request['headers'] ?? []),
            body: ($request['body'] ?? []) === [] ? null : $request['body'],
            ownerType: 'company',
            ownerId: $companyId,
            timeoutSeconds: 30,
            retryTimes: 1,
            metadata: [
                'marketplace_id' => $config['marketplace_id'] ?? null,
            ],
        ));

        if ($response->failed()) {
            throw MarketplaceOperationException::requestFailed(
                EbayConfiguration::CHANNEL,
                $operation,
                $response->status,
                $response->exchange?->id,
            );
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function operationResult(string $name, IntegrationResponse $response): array
    {
        return array_filter([
            'name' => $name,
            'http_status' => $response->status,
            'exchange_id' => $response->exchange?->id,
            'warnings' => $response->json('warnings', []),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  list<array<string, mixed>>  $operationLog
     * @param  array{external_listing_id: string|null, external_offer_id: string, status: string, listed_at: Carbon|null, ended_at: Carbon|null}  $publication
     */
    private function storeListing(
        ListingDraft $draft,
        ?Listing $existing,
        array $operationLog,
        array $publication,
    ): Listing {
        $externalListingId = is_string($publication['external_listing_id']) && trim($publication['external_listing_id']) !== ''
            ? trim($publication['external_listing_id'])
            : $existing?->external_listing_id;

        $item = $draft->item;
        $payload = $this->payloads->build($draft);
        $title = $draft->title ?? $item?->title;
        $price = data_get($payload, 'offer.pricingSummary.price.value');
        $currency = data_get($payload, 'offer.pricingSummary.price.currency');

        $listing = Listing::query()->updateOrCreate(
            [
                'company_id' => $draft->company_id,
                'channel' => EbayConfiguration::CHANNEL,
                'external_listing_id' => $externalListingId ?? $publication['external_offer_id'],
            ],
            [
                'item_id' => $draft->item_id,
                'external_offer_id' => $publication['external_offer_id'],
                'external_sku' => $payload['sku'] ?? $draft->external_sku,
                'marketplace_id' => $draft->marketplace_id,
                'title' => $title,
                'status' => $publication['status'],
                'management_state' => Listing::MANAGEMENT_BELIMBING_MANAGED,
                'drift_status' => Listing::DRIFT_IN_SYNC,
                'drift_summary' => null,
                'price_amount' => is_string($price) ? (int) round(((float) $price) * 100) : null,
                'currency_code' => is_string($currency) ? strtoupper($currency) : null,
                'listing_url' => $externalListingId !== null && $externalListingId !== '' ? 'https://www.ebay.com/itm/'.$externalListingId : null,
                'listed_at' => $publication['listed_at'],
                'ended_at' => $publication['ended_at'],
                'last_synced_at' => Carbon::now(),
                'raw_payload' => [
                    'publish_contract' => $payload,
                    'operations' => $operationLog,
                    'metadata_marketplace_id' => $draft->metadata_marketplace_id,
                ],
            ],
        );

        $this->productReferences->importFromListing($listing);

        return $listing;
    }

    private function markSuccess(ListingDraft $draft, Listing $listing, string $status): void
    {
        $draft->update([
            'listing_id' => $listing->id,
            'status' => $status,
            'management_state' => ListingDraft::MANAGEMENT_BELIMBING_MANAGED,
            'publish_intent' => null,
            'last_failure_summary' => null,
        ]);

        if ($listing->item !== null && $listing->item->status !== Item::STATUS_SOLD) {
            $listing->item->update(['status' => Item::STATUS_LISTED]);
        }
    }

    private function recordDraftFailure(ListingDraft $draft, MarketplaceOperationException $exception): void
    {
        $exchangeId = $exception->context['exchange_id'] ?? null;
        $suffix = is_string($exchangeId) && $exchangeId !== '' ? ' ['.$exchangeId.']' : '';

        $draft->update([
            'last_failure_summary' => Str::limit($exception->getMessage().$suffix, 1000, ''),
        ]);
    }
}
