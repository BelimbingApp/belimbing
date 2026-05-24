<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Modules\Commerce\Marketplace\Ebay\DTO\EbayAccountSetupImportResult;
use App\Modules\Commerce\Marketplace\Ebay\DTO\EbayBusinessPolicy;
use App\Modules\Commerce\Marketplace\Ebay\DTO\EbayInventoryLocation;
use App\Modules\Commerce\Marketplace\Models\AccountResource;
use Illuminate\Support\Carbon;

class EbayAccountSetupImporter
{
    public function __construct(
        private readonly EbayConfiguration $configuration,
        private readonly EbayPoliciesService $policies,
        private readonly EbayLocationsService $locations,
    ) {}

    public function import(int $companyId): EbayAccountSetupImportResult
    {
        $marketplaceId = (string) $this->configuration->forCompany($companyId)['marketplace_id'];
        $importedAt = Carbon::now();

        $paymentPolicies = $this->policies->pullPaymentPolicies($companyId);
        $fulfillmentPolicies = $this->policies->pullFulfillmentPolicies($companyId);
        $returnPolicies = $this->policies->pullReturnPolicies($companyId);
        $locations = $this->locations->pullInventoryLocations($companyId);

        foreach ($paymentPolicies as $policy) {
            $this->upsertPolicy($companyId, $marketplaceId, $policy, $importedAt);
        }

        foreach ($fulfillmentPolicies as $policy) {
            $this->upsertPolicy($companyId, $marketplaceId, $policy, $importedAt);
        }

        foreach ($returnPolicies as $policy) {
            $this->upsertPolicy($companyId, $marketplaceId, $policy, $importedAt);
        }

        foreach ($locations as $location) {
            $this->upsertLocation($companyId, $marketplaceId, $location, $importedAt);
        }

        return new EbayAccountSetupImportResult(
            paymentPolicies: $paymentPolicies->count(),
            fulfillmentPolicies: $fulfillmentPolicies->count(),
            returnPolicies: $returnPolicies->count(),
            inventoryLocations: $locations->count(),
        );
    }

    private function upsertPolicy(int $companyId, string $marketplaceId, EbayBusinessPolicy $policy, Carbon $importedAt): void
    {
        AccountResource::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'channel' => EbayConfiguration::CHANNEL,
                'marketplace_id' => $policy->marketplaceId !== '' ? $policy->marketplaceId : $marketplaceId,
                'kind' => $policy->kind,
                'external_id' => $policy->id,
            ],
            [
                'name' => $policy->name !== '' ? $policy->name : $policy->id,
                'status' => null,
                'payload' => [
                    'description' => $policy->description,
                ],
                'imported_at' => $importedAt,
            ],
        );
    }

    private function upsertLocation(int $companyId, string $marketplaceId, EbayInventoryLocation $location, Carbon $importedAt): void
    {
        AccountResource::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'channel' => EbayConfiguration::CHANNEL,
                'marketplace_id' => $marketplaceId,
                'kind' => AccountResource::KIND_INVENTORY_LOCATION,
                'external_id' => $location->merchantLocationKey,
            ],
            [
                'name' => $location->name ?? $location->merchantLocationKey,
                'status' => $location->status,
                'payload' => [
                    'country' => $location->country,
                    'postal_code' => $location->postalCode,
                    'city' => $location->city,
                    'location_types' => $location->locationTypes,
                ],
                'imported_at' => $importedAt,
            ],
        );
    }
}
