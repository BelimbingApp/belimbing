<?php

use App\Base\AI\Exceptions\ProviderDiscoveryException;
use App\Base\AI\Services\ProviderDiscoveryService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('discovers models without sending bearer auth for not-required keys and sorts them by display name', function (): void {
    Http::fake([
        'https://example.test/models' => Http::response([
            'data' => [
                ['id' => 'zeta-model'],
                ['id' => 'alpha-model'],
                ['name' => 'missing-id'],
            ],
        ], 200),
    ]);

    $service = new ProviderDiscoveryService;
    $models = $service->discoverModels('https://example.test/', 'not-required');

    expect($models)->toBe([
        ['model_id' => 'alpha-model', 'display_name' => 'Alpha Model'],
        ['model_id' => 'zeta-model', 'display_name' => 'Zeta Model'],
    ]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://example.test/models'
            && ! $request->hasHeader('Authorization');
    });
});

it('prefers non-empty models array over data when both are present (Codex + OpenAI shim)', function (): void {
    Http::fake([
        'https://chatgpt.example/codex/models*' => Http::response([
            'data' => [['id' => 'gpt-5.2']],
            'models' => [
                ['slug' => 'gpt-5.2', 'display_name' => 'GPT-5.2'],
                ['slug' => 'gpt-5.4', 'display_name' => 'GPT-5.4'],
                ['slug' => 'gpt-5.2-codex', 'display_name' => 'GPT-5.2 Codex'],
            ],
        ], 200),
    ]);

    $service = new ProviderDiscoveryService;

    expect($service->discoverModels('https://chatgpt.example/codex', 'token', [], ['client_version' => '1.0']))
        ->toHaveCount(3);
});

it('parses Codex models list shape with slug and optional display_name', function (): void {
    Http::fake([
        'https://chatgpt.example/codex/models*' => Http::response([
            'models' => [
                ['slug' => 'zeta-model', 'display_name' => 'Zeta Display'],
                ['slug' => 'alpha-model'],
            ],
        ], 200),
    ]);

    $service = new ProviderDiscoveryService;
    $models = $service->discoverModels('https://chatgpt.example/codex', '', [], ['client_version' => '1.0.0']);

    expect($models)->toBe([
        ['model_id' => 'alpha-model', 'display_name' => 'Alpha Model'],
        ['model_id' => 'zeta-model', 'display_name' => 'Zeta Display'],
    ]);

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), '/codex/models')
            && str_contains($request->url(), 'client_version=1.0.0');
    });
});

it('throws a dedicated exception when provider model discovery fails', function (): void {
    Http::fake([
        'https://example.test/models' => Http::response([], 502),
    ]);

    $service = new ProviderDiscoveryService;

    expect(fn () => $service->discoverModels('https://example.test'))
        ->toThrow(function (ProviderDiscoveryException $exception): void {
            expect($exception->getMessage())->toBe('Model discovery failed: HTTP 502')
                ->and($exception->context['status'] ?? null)->toBe(502);
        });
});
