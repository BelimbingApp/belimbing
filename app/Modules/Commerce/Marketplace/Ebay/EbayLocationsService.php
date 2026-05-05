<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Integration\Services\IntegrationHttpClientFactory;
use App\Modules\Commerce\Marketplace\Ebay\DTO\EbayInventoryLocation;
use Illuminate\Support\Collection;

/**
 * Reads the seller's inventory locations from the eBay Sell Account API.
 *
 * The publish path needs at least one ENABLED location (so the
 * `merchantLocationKey` can be referenced from an Offer's availability).
 * Authentication, base-URL resolution, and marketplace scoping mirror
 * `EbayPoliciesService` — the seller's stored OAuth token must include
 * `sell.account.readonly` (or `sell.account` for write operations like
 * creating a location).
 */
class EbayLocationsService
{
    public function __construct(
        private readonly EbayConfiguration $configuration,
        private readonly EbayOAuthService $oauth,
        private readonly IntegrationHttpClientFactory $http,
    ) {}

    /**
     * @return Collection<int, EbayInventoryLocation>
     */
    public function pullInventoryLocations(int $companyId): Collection
    {
        $config = $this->configuration->forCompany($companyId);
        $client = $this->http->json(
            (string) $config['api_base_url'],
            $this->oauth->accessToken($companyId),
        );

        $response = $client
            ->get('/sell/account/v1/location', ['limit' => 100])
            ->throw()
            ->json();

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
}
