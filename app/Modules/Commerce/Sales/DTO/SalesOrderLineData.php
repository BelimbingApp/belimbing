<?php
namespace App\Modules\Commerce\Sales\DTO;

final readonly class SalesOrderLineData
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public ?string $externalLineItemId,
        public ?string $externalListingId,
        public ?string $externalSku,
        public ?string $title,
        public int $quantity,
        public ?int $unitPriceAmount,
        public ?int $lineTotalAmount,
        public ?string $currencyCode,
        public array $rawPayload = [],
    ) {}
}
