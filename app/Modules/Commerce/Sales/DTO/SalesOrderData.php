<?php
namespace App\Modules\Commerce\Sales\DTO;

use Illuminate\Support\Carbon;

final readonly class SalesOrderData
{
    /**
     * @param  list<SalesOrderLineData>  $lines
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $channel,
        public string $externalOrderId,
        public ?string $marketplaceId,
        public ?string $buyerUsername,
        public ?string $buyerEmail,
        public ?string $status,
        public ?Carbon $orderedAt,
        public ?Carbon $paidAt,
        public ?Carbon $fulfilledAt,
        public ?int $totalAmount,
        public ?string $currencyCode,
        public array $lines,
        public array $rawPayload = [],
    ) {}
}
