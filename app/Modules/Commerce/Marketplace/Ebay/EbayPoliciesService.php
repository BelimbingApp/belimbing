<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Integration\Services\IntegrationHttpClientFactory;
use App\Modules\Commerce\Marketplace\Ebay\DTO\EbayBusinessPolicy;
use Illuminate\Support\Collection;

/**
 * Reads the seller's reusable business policies from the eBay Sell Account API.
 *
 * Three policy kinds exist (payment, fulfillment, return) and each lives at
 * its own endpoint with its own ID field name. The publish flow (Phase 6
 * `createListing`) needs the seller to have selected one of each; this
 * service is the pull-side of the onboarding wizard that surfaces them.
 *
 * Authentication and base-URL resolution reuse the same per-company OAuth
 * token + sandbox/live switch as the Inventory + Fulfillment reads, so the
 * scope set on the seller's stored token must include
 * `sell.account.readonly` (or `sell.account`) for these calls to succeed.
 */
class EbayPoliciesService
{
    public function __construct(
        private readonly EbayConfiguration $configuration,
        private readonly EbayOAuthService $oauth,
        private readonly IntegrationHttpClientFactory $http,
    ) {}

    /**
     * @return Collection<int, EbayBusinessPolicy>
     */
    public function pullPaymentPolicies(int $companyId): Collection
    {
        return $this->fetch(
            companyId: $companyId,
            path: '/sell/account/v1/payment_policy',
            listKey: 'paymentPolicies',
            idKey: 'paymentPolicyId',
            kind: EbayBusinessPolicy::KIND_PAYMENT,
        );
    }

    /**
     * @return Collection<int, EbayBusinessPolicy>
     */
    public function pullFulfillmentPolicies(int $companyId): Collection
    {
        return $this->fetch(
            companyId: $companyId,
            path: '/sell/account/v1/fulfillment_policy',
            listKey: 'fulfillmentPolicies',
            idKey: 'fulfillmentPolicyId',
            kind: EbayBusinessPolicy::KIND_FULFILLMENT,
        );
    }

    /**
     * @return Collection<int, EbayBusinessPolicy>
     */
    public function pullReturnPolicies(int $companyId): Collection
    {
        return $this->fetch(
            companyId: $companyId,
            path: '/sell/account/v1/return_policy',
            listKey: 'returnPolicies',
            idKey: 'returnPolicyId',
            kind: EbayBusinessPolicy::KIND_RETURN,
        );
    }

    /**
     * @return Collection<int, EbayBusinessPolicy>
     */
    private function fetch(int $companyId, string $path, string $listKey, string $idKey, string $kind): Collection
    {
        $config = $this->configuration->forCompany($companyId);
        $client = $this->http->json(
            (string) $config['api_base_url'],
            $this->oauth->accessToken($companyId),
        );
        $marketplaceId = (string) $config['marketplace_id'];

        $response = $client
            ->get($path, ['marketplace_id' => $marketplaceId])
            ->throw()
            ->json();

        $items = is_array($response[$listKey] ?? null) ? $response[$listKey] : [];

        return collect($items)
            ->map(fn (array $item): EbayBusinessPolicy => new EbayBusinessPolicy(
                kind: $kind,
                id: (string) ($item[$idKey] ?? ''),
                name: (string) ($item['name'] ?? ''),
                marketplaceId: (string) ($item['marketplaceId'] ?? $marketplaceId),
                description: isset($item['description']) ? (string) $item['description'] : null,
            ))
            ->values();
    }
}
