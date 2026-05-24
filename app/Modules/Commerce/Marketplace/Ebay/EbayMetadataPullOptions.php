<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

final readonly class EbayMetadataPullOptions
{
    /**
     * @param  array<string, string>  $query
     * @param  array<string, mixed>  $metadata
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public array $query,
        public bool $forceRefresh,
        public array $metadata = [],
        public array $headers = [],
    ) {}
}
