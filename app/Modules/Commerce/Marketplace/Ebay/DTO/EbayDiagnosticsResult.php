<?php

namespace App\Modules\Commerce\Marketplace\Ebay\DTO;

use Illuminate\Support\Carbon;

/**
 * Outcome of running a single eBay diagnostic probe.
 *
 * Carries enough request/response context to explain the result on the
 * settings page (method, endpoint, query, HTTP status, a compact response
 * excerpt) while linking to the full outbound exchange for authorized viewers.
 */
final readonly class EbayDiagnosticsResult
{
    /**
     * @param  string  $status  One of EbayDiagnosticsService::STATUS_*.
     * @param  array<string, scalar>  $query  Query parameters sent with the probe.
     */
    public function __construct(
        public string $probeKey,
        public string $status,
        public string $message,
        public Carbon $testedAt,
        public string $method = 'GET',
        public ?string $endpoint = null,
        public array $query = [],
        public ?int $httpStatus = null,
        public ?string $exchangeId = null,
        public ?string $responseExcerpt = null,
    ) {}

    /**
     * @return array{
     *     probe_key: string,
     *     status: string,
     *     message: string,
     *     tested_at: string,
     *     method: string,
     *     endpoint: string|null,
     *     query: array<string, scalar>,
     *     http_status: int|null,
     *     exchange_id: string|null,
     *     response_excerpt: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'probe_key' => $this->probeKey,
            'status' => $this->status,
            'message' => $this->message,
            'tested_at' => $this->testedAt->toIso8601String(),
            'method' => $this->method,
            'endpoint' => $this->endpoint,
            'query' => $this->query,
            'http_status' => $this->httpStatus,
            'exchange_id' => $this->exchangeId,
            'response_excerpt' => $this->responseExcerpt,
        ];
    }
}
