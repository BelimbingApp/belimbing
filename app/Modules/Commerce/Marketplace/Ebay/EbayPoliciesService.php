<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use App\Modules\Commerce\Marketplace\Ebay\DTO\EbayBusinessPolicy;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;
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
        private readonly IntegrationGateway $integration,
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
     * Ensure one of each business-policy kind exists, creating a sensible
     * default only when the seller has none of that kind. Returns the selected
     * policy id per kind (existing first, otherwise the just-created one).
     *
     * Defaults target eBay Motors *parts* sold by a US seller: policies live on
     * the account marketplace (EBAY_US) with category type
     * `ALL_EXCLUDING_MOTORS_VEHICLES` (parts are not whole vehicles). This is the
     * exact shape proven to publish in sandbox on 2026-06-07.
     *
     * @return array{payment: string, fulfillment: string, return: string}
     */
    public function ensureDefaultPolicies(int $companyId): array
    {
        $marketplaceId = (string) $this->configuration->forCompany($companyId)['marketplace_id'];
        $categoryType = [['name' => 'ALL_EXCLUDING_MOTORS_VEHICLES']];

        $payment = $this->pullPaymentPolicies($companyId)->first()?->id
            ?? $this->create($companyId, '/sell/account/v1/payment_policy', 'paymentPolicyId', EbayBusinessPolicy::KIND_PAYMENT, [
                'name' => 'Default Payment Policy',
                'marketplaceId' => $marketplaceId,
                'categoryTypes' => $categoryType,
                'immediatePay' => true,
            ]);

        $return = $this->pullReturnPolicies($companyId)->first()?->id
            ?? $this->create($companyId, '/sell/account/v1/return_policy', 'returnPolicyId', EbayBusinessPolicy::KIND_RETURN, [
                'name' => 'Default Return Policy',
                'marketplaceId' => $marketplaceId,
                'categoryTypes' => $categoryType,
                'returnsAccepted' => true,
                'returnPeriod' => ['value' => 30, 'unit' => 'DAY'],
                'returnShippingCostPayer' => 'SELLER',
                'returnMethod' => 'REPLACEMENT',
            ]);

        $fulfillment = $this->pullFulfillmentPolicies($companyId)->first()?->id
            ?? $this->create($companyId, '/sell/account/v1/fulfillment_policy', 'fulfillmentPolicyId', EbayBusinessPolicy::KIND_FULFILLMENT, [
                'name' => 'Default Fulfillment Policy',
                'marketplaceId' => $marketplaceId,
                'categoryTypes' => $categoryType,
                'handlingTime' => ['value' => 2, 'unit' => 'DAY'],
                'shippingOptions' => [[
                    'optionType' => 'DOMESTIC',
                    'costType' => 'FLAT_RATE',
                    'shippingServices' => [[
                        'sortOrder' => 1,
                        'shippingCarrierCode' => 'USPS',
                        'shippingServiceCode' => 'USPSPriority',
                        'shippingCost' => ['value' => '9.99', 'currency' => 'USD'],
                        'freeShipping' => false,
                    ]],
                ]],
            ]);

        return ['payment' => $payment, 'fulfillment' => $fulfillment, 'return' => $return];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function create(int $companyId, string $path, string $idKey, string $kind, array $body): string
    {
        $config = $this->configuration->forCompany($companyId);

        $response = $this->integration->send(new IntegrationRequest(
            system: EbayConfiguration::CHANNEL,
            operation: 'commerce.marketplace.ebay.policies.create.'.$kind,
            method: 'POST',
            endpoint: rtrim((string) $config['api_base_url'], '/').$path,
            protocolOperation: 'POST '.$path,
            provider: EbayConfiguration::CHANNEL,
            headers: [
                'Authorization' => 'Bearer '.$this->oauth->accessToken($companyId),
                'Content-Type' => 'application/json',
                'Content-Language' => 'en-US',
            ],
            body: $body,
            ownerType: 'company',
            ownerId: $companyId,
            timeoutSeconds: 30,
            retryTimes: 0,
            metadata: ['policy_kind' => $kind],
        ));

        if ($response->failed()) {
            throw MarketplaceOperationException::requestFailed(
                EbayConfiguration::CHANNEL,
                'policies.create.'.$kind,
                $response->status,
                $response->exchange?->id,
            );
        }

        return (string) $response->json($idKey, '');
    }

    /**
     * @return Collection<int, EbayBusinessPolicy>
     */
    private function fetch(int $companyId, string $path, string $listKey, string $idKey, string $kind): Collection
    {
        $config = $this->configuration->forCompany($companyId);
        $marketplaceId = (string) $config['marketplace_id'];

        $response = $this->integration->send(new IntegrationRequest(
            system: EbayConfiguration::CHANNEL,
            operation: 'commerce.marketplace.ebay.policies.pull.'.$kind,
            method: 'GET',
            endpoint: rtrim((string) $config['api_base_url'], '/').$path,
            protocolOperation: 'GET '.$path,
            provider: EbayConfiguration::CHANNEL,
            headers: ['Authorization' => 'Bearer '.$this->oauth->accessToken($companyId)],
            query: ['marketplace_id' => $marketplaceId],
            ownerType: 'company',
            ownerId: $companyId,
            timeoutSeconds: 30,
            retryTimes: 1,
            metadata: ['policy_kind' => $kind, 'marketplace_id' => $marketplaceId],
        ));

        if ($response->failed()) {
            throw MarketplaceOperationException::requestFailed(
                EbayConfiguration::CHANNEL,
                'policies.pull.'.$kind,
                $response->status,
                $response->exchange?->id,
            );
        }

        $response = $response->json();
        $response = is_array($response) ? $response : [];

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
