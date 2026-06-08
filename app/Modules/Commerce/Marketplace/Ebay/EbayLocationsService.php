<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use App\Modules\Commerce\Marketplace\Ebay\DTO\EbayInventoryLocation;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;
use Illuminate\Support\Collection;

/**
 * Reads the seller's inventory locations from the eBay Sell Inventory API.
 *
 * The publish path needs at least one ENABLED location (so the
 * `merchantLocationKey` can be referenced from an Offer's availability).
 * Authentication, base-URL resolution, and marketplace scoping mirror
 * `EbayPoliciesService` — the seller's stored OAuth token must include
 * `sell.inventory.readonly` (or `sell.inventory`).
 */
class EbayLocationsService
{
    public function __construct(
        private readonly EbayConfiguration $configuration,
        private readonly EbayOAuthService $oauth,
        private readonly IntegrationGateway $integration,
    ) {}

    /**
     * @return Collection<int, EbayInventoryLocation>
     */
    public function pullInventoryLocations(int $companyId): Collection
    {
        $config = $this->configuration->forCompany($companyId);
        $response = $this->integration->send(new IntegrationRequest(
            system: EbayConfiguration::CHANNEL,
            operation: 'commerce.marketplace.ebay.locations.pull',
            method: 'GET',
            endpoint: rtrim((string) $config['api_base_url'], '/').'/sell/inventory/v1/location',
            protocolOperation: 'GET /sell/inventory/v1/location',
            provider: EbayConfiguration::CHANNEL,
            headers: ['Authorization' => 'Bearer '.$this->oauth->accessToken($companyId)],
            query: ['limit' => 100],
            ownerType: 'company',
            ownerId: $companyId,
            timeoutSeconds: 30,
            retryTimes: 1,
            metadata: ['marketplace_id' => $config['marketplace_id'] ?? null],
        ));

        if ($response->failed()) {
            throw MarketplaceOperationException::requestFailed(
                EbayConfiguration::CHANNEL,
                'locations.pull',
                $response->status,
                $response->exchange?->id,
            );
        }

        $response = $response->json();
        $response = is_array($response) ? $response : [];

        $items = is_array($response['locations'] ?? null) ? $response['locations'] : [];

        return collect($items)
            ->map(fn (array $item): EbayInventoryLocation => new EbayInventoryLocation(
                merchantLocationKey: (string) ($item['merchantLocationKey'] ?? ''),
                name: isset($item['name']) ? (string) $item['name'] : null,
                status: isset($item['merchantLocationStatus']) ? (string) $item['merchantLocationStatus'] : null,
                country: isset($item['location']['address']['country']) ? (string) $item['location']['address']['country'] : null,
                postalCode: isset($item['location']['address']['postalCode']) ? (string) $item['location']['address']['postalCode'] : null,
                city: isset($item['location']['address']['city']) ? (string) $item['location']['address']['city'] : null,
                locationTypes: array_values(array_map(
                    static fn ($type): string => (string) $type,
                    is_array($item['locationTypes'] ?? null) ? $item['locationTypes'] : [],
                )),
            ))
            ->values();
    }

    /**
     * Ensure a warehouse merchant location exists, creating it when absent.
     *
     * A merchant location is an Inventory API concept (not something sellers
     * usually configure in Seller Hub), so account setup has to be able to
     * create one. Idempotent: an existing key is left untouched (it does not
     * overwrite an address the seller may have changed). Use {@see saveLocation}
     * when the caller wants the supplied address to win.
     *
     * @param  array{country: string, stateOrProvince: string, postalCode: string, city: string}  $address
     */
    public function ensureLocation(int $companyId, string $merchantLocationKey, string $name, array $address): string
    {
        if (! $this->locationExists($companyId, $merchantLocationKey)) {
            $this->createLocation($companyId, $merchantLocationKey, $name, $address);
        }

        return $merchantLocationKey;
    }

    /**
     * Create the location, or update its name/address when the key already
     * exists. The merchantLocationKey is immutable on eBay, but the address can
     * be changed afterwards (e.g. the seller relocates) via the Inventory API's
     * update_location_details operation.
     *
     * @param  array{country: string, stateOrProvince: string, postalCode: string, city: string}  $address
     * @return 'created'|'updated'
     */
    public function saveLocation(int $companyId, string $merchantLocationKey, string $name, array $address): string
    {
        if ($this->locationExists($companyId, $merchantLocationKey)) {
            $this->updateLocationDetails($companyId, $merchantLocationKey, $name, $address);

            return 'updated';
        }

        $this->createLocation($companyId, $merchantLocationKey, $name, $address);

        return 'created';
    }

    private function locationExists(int $companyId, string $merchantLocationKey): bool
    {
        return $this->pullInventoryLocations($companyId)
            ->contains(fn (EbayInventoryLocation $location): bool => $location->merchantLocationKey === $merchantLocationKey);
    }

    /**
     * @param  array{country: string, stateOrProvince: string, postalCode: string, city: string}  $address
     */
    private function createLocation(int $companyId, string $merchantLocationKey, string $name, array $address): void
    {
        $config = $this->configuration->forCompany($companyId);
        $response = $this->integration->send(new IntegrationRequest(
            system: EbayConfiguration::CHANNEL,
            operation: 'commerce.marketplace.ebay.locations.create',
            method: 'POST',
            endpoint: rtrim((string) $config['api_base_url'], '/').'/sell/inventory/v1/location/'.rawurlencode($merchantLocationKey),
            protocolOperation: 'POST /sell/inventory/v1/location/{key}',
            provider: EbayConfiguration::CHANNEL,
            headers: [
                'Authorization' => 'Bearer '.$this->oauth->accessToken($companyId),
                'Content-Type' => 'application/json',
            ],
            body: [
                'location' => ['address' => $address],
                'name' => $name,
                'merchantLocationStatus' => 'ENABLED',
                'locationTypes' => ['WAREHOUSE'],
            ],
            ownerType: 'company',
            ownerId: $companyId,
            timeoutSeconds: 30,
            retryTimes: 0,
            metadata: ['merchant_location_key' => $merchantLocationKey],
        ));

        if ($response->failed()) {
            throw MarketplaceOperationException::requestFailed(
                EbayConfiguration::CHANNEL,
                'locations.create',
                $response->status,
                $response->exchange?->id,
            );
        }
    }

    /**
     * @param  array{country: string, stateOrProvince: string, postalCode: string, city: string}  $address
     */
    private function updateLocationDetails(int $companyId, string $merchantLocationKey, string $name, array $address): void
    {
        $config = $this->configuration->forCompany($companyId);
        $response = $this->integration->send(new IntegrationRequest(
            system: EbayConfiguration::CHANNEL,
            operation: 'commerce.marketplace.ebay.locations.update',
            method: 'POST',
            endpoint: rtrim((string) $config['api_base_url'], '/').'/sell/inventory/v1/location/'.rawurlencode($merchantLocationKey).'/update_location_details',
            protocolOperation: 'POST /sell/inventory/v1/location/{key}/update_location_details',
            provider: EbayConfiguration::CHANNEL,
            headers: [
                'Authorization' => 'Bearer '.$this->oauth->accessToken($companyId),
                'Content-Type' => 'application/json',
            ],
            body: [
                'location' => ['address' => $address],
                'name' => $name,
            ],
            ownerType: 'company',
            ownerId: $companyId,
            timeoutSeconds: 30,
            retryTimes: 0,
            metadata: ['merchant_location_key' => $merchantLocationKey],
        ));

        if ($response->failed()) {
            throw MarketplaceOperationException::requestFailed(
                EbayConfiguration::CHANNEL,
                'locations.update',
                $response->status,
                $response->exchange?->id,
            );
        }
    }
}
