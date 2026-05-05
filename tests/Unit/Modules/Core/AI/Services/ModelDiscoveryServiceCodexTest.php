<?php

use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\ModelDiscoveryService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

test('ModelDiscoveryService uses provider discovery for openai-codex', function (): void {
    Http::fake([
        'https://chatgpt.com/backend-api/codex/models*' => Http::response([
            'models' => [
                ['slug' => 'gpt-5.4', 'display_name' => 'gpt-5.4'],
                ['slug' => 'gpt-5.2', 'display_name' => 'gpt-5.2'],
            ],
        ], 200),
    ]);

    $provider = new AiProvider([
        'name' => 'openai-codex',
        'base_url' => 'https://chatgpt.com/backend-api',
        'credentials' => [
            'access_token' => 'jwt-token',
            'account_id' => 'acct_1',
        ],
        'auth_type' => 'oauth',
    ]);

    $service = app(ModelDiscoveryService::class);
    $models = $service->discoverModels($provider);

    expect($models)->toBe([
        ['model_id' => 'gpt-5.2', 'display_name' => 'gpt-5.2'],
        ['model_id' => 'gpt-5.4', 'display_name' => 'gpt-5.4'],
    ]);
});
