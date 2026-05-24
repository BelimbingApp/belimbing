<?php

namespace App\Modules\Commerce\Marketplace\Ebay\DTO;

final readonly class EbayAccountSetupImportResult
{
    public function __construct(
        public int $paymentPolicies,
        public int $fulfillmentPolicies,
        public int $returnPolicies,
        public int $inventoryLocations,
    ) {}

    public function total(): int
    {
        return $this->paymentPolicies
            + $this->fulfillmentPolicies
            + $this->returnPolicies
            + $this->inventoryLocations;
    }
}
