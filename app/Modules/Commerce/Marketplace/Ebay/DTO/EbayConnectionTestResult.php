<?php

namespace App\Modules\Commerce\Marketplace\Ebay\DTO;

use Illuminate\Support\Carbon;

final readonly class EbayConnectionTestResult
{
    public function __construct(
        public string $status,
        public string $message,
        public Carbon $testedAt,
        public ?int $httpStatus = null,
        public ?string $exchangeId = null,
    ) {}

    /**
     * @return array{status: string, message: string, tested_at: string, http_status: int|null, exchange_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'tested_at' => $this->testedAt->toIso8601String(),
            'http_status' => $this->httpStatus,
            'exchange_id' => $this->exchangeId,
        ];
    }
}
