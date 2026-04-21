<?php

use App\Modules\Core\AI\Definitions\OpenAiCodexDefinition;
use App\Modules\Core\AI\Models\AiProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

test('openai codex oauth callback persists credentials and marks provider connected', function (): void {
    $user = createAdminUser();
    $companyId = $user->company_id;

    $provider = AiProvider::query()->create([
        'company_id' => $companyId,
        'name' => OpenAiCodexDefinition::KEY,
        'display_name' => 'OpenAI Codex',
        'base_url' => 'https://chatgpt.com/backend-api',
        'auth_type' => 'oauth',
        'credentials' => [],
        'connection_config' => [
            OpenAiCodexDefinition::AUTH_STATE_KEY => [
                'status' => 'pending',
                'mode' => 'browser_pkce',
                'started_at' => null,
            ],
        ],
        'is_active' => true,
        'priority' => 1,
        'created_by' => $user->employee_id,
    ]);

    $state = 'state-123';
    Cache::put('openai_codex_oauth:'.$state, [
        'provider_id' => $provider->id,
        'company_id' => $companyId,
        'verifier' => 'verifier-xyz',
        'redirect_uri' => route('admin.ai.providers.openai-codex.callback'),
    ], 600);

    // Build a JWT payload containing the account id.
    $payload = base64_encode(json_encode([
        'https://api.openai.com/auth' => [
            'chatgpt_account_id' => 'acct_abc123',
        ],
    ], JSON_THROW_ON_ERROR));
    $payload = rtrim(strtr($payload, '+/', '-_'), '=');
    $accessToken = 'aaa.'.$payload.'.zzz';

    Http::fake([
        'https://auth.openai.com/oauth/token' => Http::response([
            'access_token' => $accessToken,
            'refresh_token' => 'refresh-1',
            'expires_in' => 3600,
        ]),
    ]);

    $this->actingAs($user)
        ->get(route('admin.ai.providers.openai-codex.callback', ['code' => 'code-1', 'state' => $state]))
        ->assertRedirect(route('admin.ai.providers.setup', ['providerKey' => OpenAiCodexDefinition::KEY]));

    $provider = $provider->fresh();

    expect($provider->credentials)->toHaveKey(OpenAiCodexDefinition::CRED_ACCESS_TOKEN)
        ->and($provider->credentials[OpenAiCodexDefinition::CRED_REFRESH_TOKEN])->toBe('refresh-1')
        ->and($provider->credentials[OpenAiCodexDefinition::CRED_ACCOUNT_ID])->toBe('acct_abc123');

    $auth = $provider->connection_config[OpenAiCodexDefinition::AUTH_STATE_KEY] ?? [];
    expect($auth['status'] ?? null)->toBe('connected');
});

test('openai codex oauth callback fails when state is missing', function (): void {
    $user = createAdminUser();

    $this->actingAs($user)
        ->get(route('admin.ai.providers.openai-codex.callback', ['code' => 'code-1']))
        ->assertRedirect(route('admin.ai.providers.setup', ['providerKey' => OpenAiCodexDefinition::KEY]));
});

