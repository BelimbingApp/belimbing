<?php

use App\Base\AI\Exceptions\ModelCatalogSyncException;
use App\Base\AI\Services\ModelCatalogService;
use App\Base\Integration\Models\OutboundExchange;
use Illuminate\Support\Facades\Http;

it('records models.dev catalog sync through the integration gateway', function (): void {
    Http::fake([
        'https://models.dev/api.json' => Http::response([
            'openai' => [
                'api' => 'https://api.openai.com/v1',
                'name' => 'OpenAI',
                'models' => ['gpt-4o' => ['id' => 'gpt-4o']],
            ],
        ], 200, ['ETag' => '"catalog-etag"']),
    ]);

    $originalStoragePath = app()->storagePath();
    $testingStoragePath = sys_get_temp_dir().'/blb-testing-models-dev-'.bin2hex(random_bytes(8));
    app('files')->ensureDirectoryExists($testingStoragePath);
    app()->useStoragePath($testingStoragePath);

    try {
        $result = app(ModelCatalogService::class)->sync();
    } finally {
        app()->useStoragePath($originalStoragePath);
        app('files')->deleteDirectory($testingStoragePath);
    }

    $exchange = OutboundExchange::query()->firstOrFail();

    expect($result->updated)->toBeTrue()
        ->and($exchange->system)->toBe('ai_catalog')
        ->and($exchange->provider)->toBe('models.dev')
        ->and($exchange->operation)->toBe('ai.catalog.models_dev.sync')
        ->and($exchange->response_status)->toBe(200);
});

it('includes exchange id when models.dev catalog sync fails', function (): void {
    Http::fake([
        'https://models.dev/api.json' => Http::response(['error' => 'down'], 503),
    ]);

    expect(fn () => app(ModelCatalogService::class)->sync())
        ->toThrow(function (ModelCatalogSyncException $exception): void {
            $exchange = OutboundExchange::query()->firstOrFail();

            expect($exception->context['exchange_id'] ?? null)->toBe($exchange->id)
                ->and($exchange->outcome)->toBe('http_error')
                ->and($exchange->response_status)->toBe(503);
        });
});
