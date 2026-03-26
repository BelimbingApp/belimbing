<?php

use App\Base\AI\Exceptions\ModelCatalogSyncException;
use App\Base\AI\Services\ModelCatalogService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('throws a dedicated exception when catalog sync returns a failed response', function (): void {
    Http::fake([
        'https://models.dev/api.json' => Http::response([], 503),
    ]);

    $service = new ModelCatalogService;

    expect(fn () => $service->sync())
        ->toThrow(function (ModelCatalogSyncException $exception): void {
            expect($exception->getMessage())->toBe('Catalog sync failed: HTTP 503')
                ->and($exception->context['status'] ?? null)->toBe(503);
        });
});

it('throws a dedicated exception when catalog sync returns invalid payload data', function (): void {
    Http::fake([
        'https://models.dev/api.json' => Http::response([], 200),
    ]);

    $service = new ModelCatalogService;

    expect(fn () => $service->sync())
        ->toThrow(ModelCatalogSyncException::class, 'Catalog sync returned empty or invalid data');
});

it('ensureSynced delegates to sync so the catalog file is populated on demand', function (): void {
    Http::fake([
        'https://models.dev/api.json' => Http::response([
            'openai' => [
                'api' => 'https://api.openai.com/v1',
                'name' => 'OpenAI',
                'models' => ['gpt-4o' => ['id' => 'gpt-4o']],
            ],
        ], 200, ['ETag' => '"test-etag"']),
    ]);

    $service = new ModelCatalogService;
    $service->ensureSynced();

    expect($service->getCatalog())->not->toBeEmpty()
        ->and($service->getModels('openai'))->not->toBeEmpty();
});
