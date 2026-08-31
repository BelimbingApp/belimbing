<?php

use App\Base\Integration\Models\OutboundExchange;
use App\Core\AI\Definitions\OpenAiCodexDefinition;
use App\Core\AI\Models\AiProvider;
use App\Core\AI\Services\OpenAiCodexAuth\OpenAiCodexAuthManager;
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
        'created_by_user_id' => $user->id,
    ]);

    $state = 'state-123';
    Cache::put('openai_codex_oauth:'.$state, [
        'provider_id' => $provider->id,
        'company_id' => $companyId,
        'verifier' => 'verifier-xyz',
        'redirect_uri' => app(OpenAiCodexAuthManager::class)->redirectUri(),
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

    $response = $this->actingAs($user)
        ->get(route('admin.ai.providers.openai-codex.callback', ['code' => 'code-1', 'state' => $state]))
        ->assertRedirect(route('admin.ai.providers.setup', ['providerKey' => OpenAiCodexDefinition::KEY]));

    $response->assertSessionHas('success', 'OpenAI Codex connected.');

    $provider = $provider->fresh();

    expect($provider->credentials)->toHaveKey(OpenAiCodexDefinition::CRED_ACCESS_TOKEN)
        ->and($provider->credentials[OpenAiCodexDefinition::CRED_REFRESH_TOKEN])->toBe('refresh-1')
        ->and($provider->credentials[OpenAiCodexDefinition::CRED_ACCOUNT_ID])->toBe('acct_abc123')
        ->and(Cache::get('openai_codex_oauth:'.$state))->toBeNull();

    $exchange = OutboundExchange::query()->firstOrFail();
    expect($exchange->operation)->toBe('ai.openai_codex.oauth.authorization_code.exchange')
        ->and($exchange->protocol)->toBe('oauth2')
        // OAuth request bodies are suppressed in telemetry to protect
        // authorization codes, code verifiers, and client secrets.
        ->and($exchange->request_body)->toBeNull()
        // Response bodies have sensitive fields redacted (access_token, refresh_token).
        ->and($exchange->response_body['value']['access_token'])->toBe('[redacted]')
        ->and($exchange->response_body['value']['refresh_token'])->toBe('[redacted]')
        ->and($exchange->response_body['value']['expires_in'])->toBe(3600);

    $auth = $provider->connection_config[OpenAiCodexDefinition::AUTH_STATE_KEY] ?? [];
    expect($auth['status'] ?? null)->toBe('connected');
});

test('openai codex oauth callback fails when state is missing', function (): void {
    $user = createAdminUser();

    $this->actingAs($user)
        ->get(route('admin.ai.providers.openai-codex.callback', ['code' => 'code-1']))
        ->assertRedirect(route('admin.ai.providers.setup', ['providerKey' => OpenAiCodexDefinition::KEY]))
        ->assertSessionHas('error', 'OpenAI Codex sign-in failed: Missing authorization code or state.');
});
