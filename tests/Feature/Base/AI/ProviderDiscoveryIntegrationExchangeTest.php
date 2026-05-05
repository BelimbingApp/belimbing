<?php

use App\Base\AI\Exceptions\ProviderDiscoveryException;
use App\Base\AI\Services\ModelCatalogService;
use App\Base\AI\Services\ProviderDiscoveryService;
use App\Base\Integration\Models\OutboundExchange;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\ModelDiscoveryService;
use App\Modules\Core\AI\Services\ProviderDefinitionRegistry;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('records provider model discovery through the integration gateway', function (): void {
    Http::fake([
        'https://provider.example.test/models' => Http::response([
            'data' => [
                ['id' => 'zeta-model'],
                ['id' => 'alpha-model'],
            ],
        ], 200),
    ]);

    $models = app(ProviderDiscoveryService::class)->discoverModels('https://provider.example.test', 'not-required');

    $exchange = OutboundExchange::query()->firstOrFail();

    expect($models)->toBe([
        ['model_id' => 'alpha-model', 'display_name' => 'Alpha Model'],
        ['model_id' => 'zeta-model', 'display_name' => 'Zeta Model'],
    ])->and($exchange->system)->toBe('ai_provider')
        ->and($exchange->provider)->toBe('provider.example.test')
        ->and($exchange->operation)->toBe('ai.provider.models.discover')
        ->and($exchange->transport)->toBe('http')
        ->and($exchange->protocol)->toBe('rest')
        ->and($exchange->protocol_operation)->toBe('GET /models')
        ->and($exchange->metadata['http_method'])->toBe('GET')
        ->and($exchange->request_headers)->toBe([])
        ->and($exchange->response_body['value']['data'][0]['id'])->toBe('zeta-model');
});

it('includes the exchange id when provider discovery fails', function (): void {
    Http::fake([
        'https://provider.example.test/models' => Http::response(['error' => 'bad gateway'], 502),
    ]);

    expect(fn () => app(ProviderDiscoveryService::class)->discoverModels('https://provider.example.test', 'sk-secret'))
        ->toThrow(function (ProviderDiscoveryException $exception): void {
            $exchange = OutboundExchange::query()->firstOrFail();

            expect($exception->context['exchange_id'] ?? null)->toBe($exchange->id)
                ->and($exchange->outcome)->toBe('http_error')
                ->and($exchange->response_status)->toBe(502)
                ->and($exchange->request_headers['Authorization'])->toBe('[redacted]');
        });
});

it('records provider discovery connection failures', function (): void {
    Http::fake([
        'https://provider.example.test/models' => fn () => throw new ConnectionException('connection refused'),
    ]);

    expect(fn () => app(ProviderDiscoveryService::class)->discoverModels('https://provider.example.test'))
        ->toThrow(ProviderDiscoveryException::class);

    $exchange = OutboundExchange::query()->firstOrFail();

    expect($exchange->outcome)->toBe('connection_error')
        ->and($exchange->response_status)->toBeNull()
        ->and($exchange->error_class)->toBe(ConnectionException::class)
        ->and($exchange->error_message)->toBe('connection refused');
});

it('marks provider discovery exchanges when model sync falls back to the catalog', function (): void {
    $company = Company::factory()->create();
    $provider = AiProvider::query()->create([
        'company_id' => $company->id,
        'name' => 'openai',
        'display_name' => 'OpenAI',
        'base_url' => 'https://provider.example.test',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'sk-secret'],
        'is_active' => true,
    ]);

    Http::fake([
        'https://provider.example.test/models' => Http::response(['data' => []], 200),
    ]);

    $catalog = Mockery::mock(ModelCatalogService::class);
    $catalog->shouldReceive('ensureSynced')->once();
    $catalog->shouldReceive('getModels')->once()->with('openai')->andReturn([
        'fallback-model' => ['id' => 'fallback-model'],
    ]);

    $service = new ModelDiscoveryService(
        app(ProviderDefinitionRegistry::class),
        app(ProviderDiscoveryService::class),
        $catalog,
    );

    $result = $service->syncModels($provider);
    $exchange = OutboundExchange::query()->firstOrFail();

    expect($result['source'])->toBe('catalog')
        ->and($result['exchange_id'] ?? null)->toBe($exchange->id)
        ->and($exchange->fallback_used)->toBeTrue()
        ->and($exchange->fallback_reason)->toBe('empty_provider_discovery')
        ->and($exchange->metadata['fallback_provider'])->toBe('models.dev')
        ->and($exchange->metadata)->not()->toHaveKey('fallback_used')
        ->and($exchange->metadata)->not()->toHaveKey('fallback_reason');
});
