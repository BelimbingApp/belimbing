<?php

use App\Modules\Core\AI\Definitions\OpenAiCodexDefinition;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\OpenAiCodexAuth\OpenAiCodexAuthManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('openai codex auth manager starts an OpenClaw-compatible browser flow', function (): void {
    $user = createAdminUser();
    $provider = AiProvider::query()->create([
        'company_id' => $user->company_id,
        'name' => OpenAiCodexDefinition::KEY,
        'display_name' => 'OpenAI Codex',
        'base_url' => 'https://chatgpt.com/backend-api',
        'auth_type' => 'oauth',
        'credentials' => [],
        'connection_config' => [
            OpenAiCodexDefinition::AUTH_STATE_KEY => [
                'status' => 'disconnected',
                'mode' => 'browser_pkce',
            ],
        ],
        'is_active' => true,
        'priority' => 1,
        'created_by' => $user->employee_id,
    ]);

    $result = app(OpenAiCodexAuthManager::class)->startLogin($provider);

    expect($result['authorize_url'])->toStartWith('https://auth.openai.com/oauth/authorize?')
        ->and($result['state'])->not->toBe('');

    parse_str((string) parse_url($result['authorize_url'], PHP_URL_QUERY), $query);
    $pending = Cache::get('openai_codex_oauth:'.$result['state']);

    expect($query['client_id'] ?? null)->toBe('app_EMoamEEZ73f0CkXaXp7hrann')
        ->and($query['redirect_uri'] ?? null)->toBe('http://localhost:1455/auth/callback')
        ->and($query['scope'] ?? null)->toBe('openid profile email offline_access')
        ->and($query['originator'] ?? null)->toBe('openclaw')
        ->and($query['state'] ?? null)->toBe($result['state'])
        ->and($pending)->toBeArray()
        ->and($pending['redirect_uri'] ?? null)->toBe('http://localhost:1455/auth/callback')
        ->and($pending['provider_id'] ?? null)->toBe($provider->id);
});
