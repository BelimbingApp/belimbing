<?php
namespace App\Base\Integration\Services;

final readonly class IntegrationRequest
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|string|null  $body
     * @param  array<string, mixed>  $metadata
     * @param  array{0: string, 1: string}|null  $basicAuth
     */
    public function __construct(
        public string $system,
        public string $operation,
        public string $method,
        public string $endpoint,
        public string $transport = 'http',
        public string $protocol = 'rest',
        public ?string $protocolOperation = null,
        public ?string $provider = null,
        public array $headers = [],
        public array $query = [],
        public array|string|null $body = null,
        public ?string $ownerType = null,
        public ?int $ownerId = null,
        public ?string $correlationId = null,
        public ?string $traceparent = null,
        public ?string $tracestate = null,
        public int $timeoutSeconds = 30,
        public int $retryTimes = 0,
        public int $retrySleepMilliseconds = 250,
        public bool $asJson = true,
        public bool $asForm = false,
        public ?array $basicAuth = null,
        public array $metadata = [],
    ) {}
}
