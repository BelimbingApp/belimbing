<?php
namespace App\Modules\Commerce\Marketplace\DTO;

final readonly class MarketplacePullResult
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $channel,
        public int $fetched,
        public int $created,
        public int $updated,
        public int $linked,
        public array $warnings = [],
    ) {}
}
