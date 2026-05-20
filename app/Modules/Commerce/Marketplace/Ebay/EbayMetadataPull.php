<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

final readonly class EbayMetadataPull
{
    public function __construct(
        public int $companyId,
        public string $marketplaceId,
        public string $kind,
        public string $key,
        public string $operation,
        public string $path,
        public ?EbayMetadataPullOptions $options = null,
    ) {}
}
