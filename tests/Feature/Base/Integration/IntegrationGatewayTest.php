<?php

use App\Base\Authz\Capability\CapabilityRegistry;
use App\Base\Integration\Models\OutboundExchange;
use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('records redacted successful external exchanges', function (): void {
    Http::fake([
        'https://api.example.test/v1/models?limit=2' => Http::response([
            'data' => [['id' => 'alpha']],
            'access_token' => 'provider-secret',
            'APIKey' => 'response-secret',
        ], 200, ['X-Request-Id' => 'req-1']),
    ]);

    $response = app(IntegrationGateway::class)->send(new IntegrationRequest(
        system: 'example',
        operation: 'example.models.discover',
        method: 'POST',
        endpoint: 'https://api.example.test/v1/models',
        protocolOperation: 'POST /v1/models',
        provider: 'example',
        headers: [
            'Authorization' => 'Bearer sk-test',
            'X-Trace' => 'trace-1',
        ],
        query: ['limit' => 2],
        body: [
            'api_key' => 'sk-test',
            'APIKey' => 'sk-test-caps',
            'XAuthToken' => 'token-test',
            'prompt' => 'hello',
        ],
        ownerType: 'company',
        ownerId: 123,
        correlationId: 'corr-1',
        retryTimes: 0,
    ));

    expect($response->successful())->toBeTrue()
        ->and($response->exchange)->toBeInstanceOf(OutboundExchange::class);

    $exchange = OutboundExchange::query()->firstOrFail();

    expect($exchange->system)->toBe('example')
        ->and($exchange->operation)->toBe('example.models.discover')
        ->and($exchange->transport)->toBe('http')
        ->and($exchange->protocol)->toBe('rest')
        ->and($exchange->protocol_operation)->toBe('POST /v1/models')
        ->and($exchange->endpoint)->toBe('https://api.example.test/v1/models?limit=2')
        ->and($exchange->metadata['http_method'])->toBe('POST')
        ->and($exchange->outcome)->toBe('success')
        ->and($exchange->response_status)->toBe(200)
        ->and($exchange->request_headers['Authorization'])->toBe('[redacted]')
        ->and($exchange->request_headers['X-Trace'])->toBe('trace-1')
        ->and($exchange->request_body['value']['api_key'])->toBe('[redacted]')
        ->and($exchange->request_body['value']['APIKey'])->toBe('[redacted]')
        ->and($exchange->request_body['value']['XAuthToken'])->toBe('[redacted]')
        ->and($exchange->request_body['value']['prompt'])->toBe('hello')
        ->and($exchange->response_body['value']['access_token'])->toBe('[redacted]')
        ->and($exchange->response_body['value']['APIKey'])->toBe('[redacted]')
        ->and($exchange->response_body['value']['data'][0]['id'])->toBe('alpha');
});

it('records failed external exchanges and payload truncation', function (): void {
    Http::fake([
        'https://api.example.test/fail' => Http::response(str_repeat('x', IntegrationGateway::DEFAULT_PAYLOAD_PREVIEW_BYTES + 10), 503),
    ]);

    $response = app(IntegrationGateway::class)->send(new IntegrationRequest(
        system: 'example',
        operation: 'example.failure',
        method: 'GET',
        endpoint: 'https://api.example.test/fail',
    ));

    $exchange = OutboundExchange::query()->firstOrFail();

    expect($response->failed())->toBeTrue()
        ->and($exchange->outcome)->toBe('http_error')
        ->and($exchange->response_status)->toBe(503)
        ->and($exchange->response_body_truncated)->toBeTrue()
        ->and($exchange->response_body['kind'])->toBe('truncated');
});

it('records actual retries rather than configured retry allowance', function (): void {
    $attempts = 0;

    Http::fake([
        'https://api.example.test/retry' => function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                throw new ConnectionException('temporary outage');
            }

            return Http::response(['ok' => true], 200);
        },
    ]);

    $response = app(IntegrationGateway::class)->send(new IntegrationRequest(
        system: 'example',
        operation: 'example.retry',
        method: 'GET',
        endpoint: 'https://api.example.test/retry',
        retryTimes: 2,
        retrySleepMilliseconds: 0,
    ));

    $exchange = OutboundExchange::query()->firstOrFail();

    expect($response->successful())->toBeTrue()
        ->and($attempts)->toBe(2)
        ->and($exchange->retry_count)->toBe(1);
});

it('keeps truncated multibyte payload previews valid UTF-8', function (): void {
    Http::fake([
        'https://api.example.test/utf8' => Http::response(json_encode([
            'message' => str_repeat('€', 30_000),
        ], JSON_UNESCAPED_UNICODE), 200),
    ]);

    app(IntegrationGateway::class)->send(new IntegrationRequest(
        system: 'example',
        operation: 'example.utf8',
        method: 'GET',
        endpoint: 'https://api.example.test/utf8',
    ));

    $exchange = OutboundExchange::query()->firstOrFail();

    expect($exchange->response_body_truncated)->toBeTrue()
        ->and(mb_check_encoding($exchange->response_body['preview'], 'UTF-8'))->toBeTrue();
});

it('registers integration exchange authz capabilities', function (): void {
    $registry = app(CapabilityRegistry::class);

    expect($registry->has('admin.integration_exchange.list'))->toBeTrue()
        ->and($registry->has('admin.integration_payload.view'))->toBeTrue()
        ->and($registry->has('admin.integration_exchange.delete'))->toBeTrue();
});
