<?php

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ExecutionControls;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\LlmClient;
use App\Modules\Core\AI\Definitions\OpenAiCodexDefinition;
use App\Modules\Core\AI\DTO\ProviderTestResult;
use App\Modules\Core\AI\Livewire\Providers\OpenAiCodexSetup;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\OpenAiCodexAuth\OpenAiCodexAuthManager;
use App\Modules\Core\AI\Services\ProviderTestService;
use App\Modules\Core\AI\Services\RuntimeCredentialResolver;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('openai codex setup surfaces connected auth state and diagnostic action', function (): void {
    $user = createAdminUser();
    config()->set('ai.provider_overlay.openai-codex.curated_models', [
        'gpt-5.4',
        'gpt-5.4-mini',
        'gpt-5.2',
    ]);
    $provider = createOpenAiCodexProvider($user, [
        'status' => 'connected',
        'mode' => 'browser_pkce',
        'completed_at' => now()->subMinutes(5)->toIso8601String(),
        'last_refresh_at' => now()->subMinute()->toIso8601String(),
        'plan_type' => 'codex_pro',
        'last_error_code' => null,
        'last_error_message' => null,
    ]);
    createOpenAiCodexModel($provider, 'gpt-5.1-codex-mini');

    $this->actingAs($user);

    Livewire::test(OpenAiCodexSetup::class, ['providerKey' => OpenAiCodexDefinition::KEY])
        ->assertSet('connectedProviderId', $provider->id)
        ->assertSee('Verify connection')
        ->assertSee('Connection status')
        ->assertSee('Browser OAuth')
        ->assertSee('codex_pro');

    expect($provider->fresh()?->models()->pluck('model_id')->all())
        ->toContain('gpt-5.4', 'gpt-5.4-mini', 'gpt-5.2');
});

test('openai codex setup deactivates stale models and resets the default to the preferred curated model', function (): void {
    config()->set('ai.provider_overlay.openai-codex.curated_models', [
        'gpt-5.4',
        'gpt-5.4-mini',
        'gpt-5.2',
    ]);
    config()->set('ai.provider_overlay.openai-codex.default_model', 'gpt-5.4');

    $user = createAdminUser();
    $provider = createOpenAiCodexProvider($user, [
        'status' => 'connected',
        'mode' => 'browser_pkce',
        'completed_at' => now()->subMinutes(5)->toIso8601String(),
        'last_refresh_at' => now()->subMinute()->toIso8601String(),
        'plan_type' => 'codex_pro',
        'last_error_code' => null,
        'last_error_message' => null,
    ]);
    createOpenAiCodexModel($provider, 'gpt-5.1-codex-mini')->setAsDefault();

    $this->actingAs($user);

    Livewire::test(OpenAiCodexSetup::class, ['providerKey' => OpenAiCodexDefinition::KEY])
        ->assertSet('connectedProviderId', $provider->id);

    $staleModel = AiProviderModel::query()
        ->where('ai_provider_id', $provider->id)
        ->where('model_id', 'gpt-5.1-codex-mini')
        ->firstOrFail();
    $preferredModel = AiProviderModel::query()
        ->where('ai_provider_id', $provider->id)
        ->where('model_id', 'gpt-5.4')
        ->firstOrFail();

    expect($staleModel->fresh()?->is_active)->toBeFalse()
        ->and($staleModel->fresh()?->is_default)->toBeFalse()
        ->and($preferredModel->fresh()?->is_active)->toBeTrue()
        ->and($preferredModel->fresh()?->is_default)->toBeTrue();
});

test('openai codex setup sync message is honest about curated model reconciliation', function (): void {
    config()->set('ai.provider_overlay.openai-codex.curated_models', [
        'gpt-5.4',
        'gpt-5.4-mini',
        'gpt-5.2',
    ]);
    config()->set('ai.provider_overlay.openai-codex.default_model', 'gpt-5.4');

    $user = createAdminUser();
    $provider = createOpenAiCodexProvider($user, [
        'status' => 'connected',
        'mode' => 'browser_pkce',
        'completed_at' => now()->subMinutes(5)->toIso8601String(),
        'last_refresh_at' => now()->subMinute()->toIso8601String(),
        'plan_type' => 'codex_pro',
        'last_error_code' => null,
        'last_error_message' => null,
    ]);
    createOpenAiCodexModel($provider, 'gpt-5.1-codex-mini')->setAsDefault();

    $this->actingAs($user);

    Livewire::test(OpenAiCodexSetup::class, ['providerKey' => OpenAiCodexDefinition::KEY])
        ->call('syncProviderModels', $provider->id)
        ->assertSet('syncMessage', 'BLB checked this provider against its curated model list: 3 supported models are active locally.')
        ->assertDontSee('Updated 3 models.');
});

test('openai codex setup records successful verification diagnostics', function (): void {
    $user = createAdminUser();
    config()->set('ai.provider_overlay.openai-codex.curated_models', ['gpt-5.4']);
    config()->set('ai.provider_overlay.openai-codex.default_model', 'gpt-5.4');
    $provider = createOpenAiCodexProvider($user, [
        'status' => 'connected',
        'mode' => 'browser_pkce',
        'completed_at' => now()->subMinutes(5)->toIso8601String(),
        'last_error_code' => 'stale_error',
        'last_error_message' => 'Old diagnostic',
    ]);
    createOpenAiCodexModel($provider, 'gpt-5.1-codex-mini');

    app()->instance(ProviderTestService::class, makeCodexProviderTestService(
        providerId: $provider->id,
        modelId: 'gpt-5.4',
        result: ProviderTestResult::success(
            providerName: OpenAiCodexDefinition::KEY,
            model: 'gpt-5.4',
            latencyMs: 84,
        ),
    ));
    $this->actingAs($user);

    Livewire::test(OpenAiCodexSetup::class, ['providerKey' => OpenAiCodexDefinition::KEY])
        ->call('verifyConnection')
        ->assertSet('verificationResult.connected', true)
        ->assertSet('verificationResult.model', 'gpt-5.4')
        ->assertSee('Verification succeeded for gpt-5.4 in 84 ms.');

    $auth = $provider->fresh()->connection_config[OpenAiCodexDefinition::AUTH_STATE_KEY] ?? [];

    expect($auth['status'] ?? null)->toBe('connected')
        ->and($auth['last_error_code'] ?? null)->toBeNull()
        ->and($auth['last_error_message'] ?? null)->toBeNull();
});

test('openai codex setup marks provider expired when verification returns auth error', function (): void {
    $user = createAdminUser();
    config()->set('ai.provider_overlay.openai-codex.curated_models', ['gpt-5.4']);
    config()->set('ai.provider_overlay.openai-codex.default_model', 'gpt-5.4');
    $provider = createOpenAiCodexProvider($user, [
        'status' => 'connected',
        'mode' => 'browser_pkce',
        'completed_at' => now()->subMinutes(5)->toIso8601String(),
        'last_error_code' => null,
        'last_error_message' => null,
    ]);
    createOpenAiCodexModel($provider, 'gpt-5.1-codex-mini');

    app()->instance(ProviderTestService::class, makeCodexProviderTestService(
        providerId: $provider->id,
        modelId: 'gpt-5.4',
        result: ProviderTestResult::failure(
            providerName: OpenAiCodexDefinition::KEY,
            model: 'gpt-5.4',
            error: AiRuntimeError::fromType(
                AiErrorType::AuthError,
                'ChatGPT session was rejected.',
            ),
        ),
    ));
    $this->actingAs($user);

    Livewire::test(OpenAiCodexSetup::class, ['providerKey' => OpenAiCodexDefinition::KEY])
        ->call('verifyConnection')
        ->assertSet('verificationResult.connected', false)
        ->assertSet('verificationResult.error_type', AiErrorType::AuthError->value);

    $auth = $provider->fresh()->connection_config[OpenAiCodexDefinition::AUTH_STATE_KEY] ?? [];

    expect($auth['status'] ?? null)->toBe('expired')
        ->and($auth['last_error_code'] ?? null)->toBe(AiErrorType::AuthError->value)
        ->and($auth['last_error_message'] ?? null)->toContain('OpenAI Codex rejected the ChatGPT backend session.');
});

test('openai codex setup disconnect clears credentials and resets auth state', function (): void {
    $user = createAdminUser();
    $provider = createOpenAiCodexProvider($user, [
        'status' => 'connected',
        'mode' => 'browser_pkce',
        'completed_at' => now()->subMinutes(5)->toIso8601String(),
        'last_refresh_at' => now()->subMinute()->toIso8601String(),
        'plan_type' => 'codex_pro',
        'last_error_code' => null,
        'last_error_message' => null,
    ]);

    $this->actingAs($user);

    Livewire::test(OpenAiCodexSetup::class, ['providerKey' => OpenAiCodexDefinition::KEY])
        ->call('disconnect')
        ->assertSet('connectedProviderId', null)
        ->assertSet('authState.status', 'disconnected');

    $provider->refresh();
    $auth = $provider->connection_config[OpenAiCodexDefinition::AUTH_STATE_KEY] ?? [];

    expect($provider->credentials)->toBe([])
        ->and($auth['status'] ?? null)->toBe('disconnected')
        ->and($auth['plan_type'] ?? null)->toBeNull()
        ->and($auth['last_error_code'] ?? null)->toBeNull()
        ->and($auth['last_error_message'] ?? null)->toBeNull();
});

test('openai codex setup completes pasted localhost callback URLs', function (): void {
    $user = createAdminUser();
    $provider = createOpenAiCodexProvider($user, [
        'status' => 'pending',
        'mode' => 'browser_pkce',
        'started_at' => now()->subMinute()->toIso8601String(),
        'completed_at' => null,
        'last_error_code' => null,
        'last_error_message' => null,
    ]);
    $provider->update(['credentials' => []]);

    $state = 'state-123';
    Cache::put('openai_codex_oauth:'.$state, [
        'provider_id' => $provider->id,
        'company_id' => $user->company_id,
        'verifier' => 'verifier-xyz',
        'redirect_uri' => app(OpenAiCodexAuthManager::class)->redirectUri(),
    ], 600);

    $payload = base64_encode(json_encode([
        'https://api.openai.com/auth' => [
            'chatgpt_account_id' => 'acct_manual',
        ],
    ], JSON_THROW_ON_ERROR));
    $payload = rtrim(strtr($payload, '+/', '-_'), '=');
    $accessToken = 'aaa.'.$payload.'.zzz';

    Http::fake([
        'https://auth.openai.com/oauth/token' => Http::response([
            'access_token' => $accessToken,
            'refresh_token' => 'refresh-manual',
            'expires_in' => 3600,
        ]),
    ]);

    $this->actingAs($user);

    Livewire::test(OpenAiCodexSetup::class, ['providerKey' => OpenAiCodexDefinition::KEY])
        ->set('manualRedirectInput', app(OpenAiCodexAuthManager::class)->redirectUri().'?code=code-1&state='.$state)
        ->call('completeOauthLogin')
        ->assertSet('connectedProviderId', $provider->id)
        ->assertSet('authState.status', 'connected')
        ->assertSet('manualCompletionError', null);

    $provider->refresh();
    expect($provider->credentials[OpenAiCodexDefinition::CRED_REFRESH_TOKEN] ?? null)->toBe('refresh-manual')
        ->and($provider->credentials[OpenAiCodexDefinition::CRED_ACCOUNT_ID] ?? null)->toBe('acct_manual');
});

test('openai codex setup rejects pasted callback values without state', function (): void {
    $user = createAdminUser();
    createOpenAiCodexProvider($user, [
        'status' => 'pending',
        'mode' => 'browser_pkce',
        'started_at' => now()->subMinute()->toIso8601String(),
        'completed_at' => null,
        'last_error_code' => null,
        'last_error_message' => null,
    ])->update(['credentials' => []]);

    $this->actingAs($user);

    Livewire::test(OpenAiCodexSetup::class, ['providerKey' => OpenAiCodexDefinition::KEY])
        ->set('manualRedirectInput', 'code=code-1')
        ->call('completeOauthLogin')
        ->assertSet('connectedProviderId', null)
        ->assertSet('manualCompletionError', 'Paste the full redirect URL from http://localhost:1455/auth/callback so BLB can read both code and state.');
});

test('openai codex setup shows reconnect guidance when verification returns a hint', function (): void {
    $user = createAdminUser();
    config()->set('ai.provider_overlay.openai-codex.curated_models', ['gpt-5.4']);
    config()->set('ai.provider_overlay.openai-codex.default_model', 'gpt-5.4');
    $provider = createOpenAiCodexProvider($user, [
        'status' => 'connected',
        'mode' => 'browser_pkce',
        'completed_at' => now()->subMinutes(5)->toIso8601String(),
        'last_error_code' => null,
        'last_error_message' => null,
    ]);
    createOpenAiCodexModel($provider, 'gpt-5.1-codex-mini');

    app()->instance(ProviderTestService::class, makeCodexProviderTestService(
        providerId: $provider->id,
        modelId: 'gpt-5.4',
        result: ProviderTestResult::failure(
            providerName: OpenAiCodexDefinition::KEY,
            model: 'gpt-5.4',
            error: new AiRuntimeError(
                errorType: AiErrorType::BadRequest,
                userMessage: 'OpenAI Codex rejected the ChatGPT backend session.',
                diagnostic: 'HTTP 400: missing chatgpt-account-id',
                hint: 'Reconnect OpenAI Codex. If the failure persists, disable this provider because the external ChatGPT backend contract may have changed.',
            ),
        ),
    ));
    $this->actingAs($user);

    Livewire::test(OpenAiCodexSetup::class, ['providerKey' => OpenAiCodexDefinition::KEY])
        ->call('verifyConnection')
        ->assertSet('verificationResult.connected', false)
        ->assertSet('verificationResult.hint', 'Reconnect OpenAI Codex. If the failure persists, disable this provider because the external ChatGPT backend contract may have changed.')
        ->assertSee('Reconnect OpenAI Codex. If the failure persists, disable this provider because the external ChatGPT backend contract may have changed.');
});

/**
 * @param  array<string, mixed>  $authState
 */
function createOpenAiCodexProvider(User $user, array $authState): AiProvider
{
    return AiProvider::query()->create([
        'company_id' => $user->company_id,
        'name' => OpenAiCodexDefinition::KEY,
        'display_name' => 'OpenAI Codex',
        'base_url' => 'https://chatgpt.com/backend-api',
        'auth_type' => 'oauth',
        'credentials' => [
            OpenAiCodexDefinition::CRED_ACCESS_TOKEN => 'aaa.bbb.ccc',
            OpenAiCodexDefinition::CRED_REFRESH_TOKEN => 'refresh-token',
            OpenAiCodexDefinition::CRED_EXPIRES_AT => now()->addHour()->toIso8601String(),
            OpenAiCodexDefinition::CRED_ACCOUNT_ID => 'acct_test',
        ],
        'connection_config' => [
            OpenAiCodexDefinition::AUTH_STATE_KEY => $authState,
        ],
        'is_active' => true,
        'priority' => 1,
        'created_by' => $user->employee_id,
    ]);
}

function createOpenAiCodexModel(AiProvider $provider, string $modelId): AiProviderModel
{
    return AiProviderModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => $modelId,
        'is_active' => true,
        'is_default' => true,
    ]);
}

function makeCodexProviderTestService(int $providerId, ProviderTestResult $result, string $modelId = 'gpt-5.4'): ProviderTestService
{
    $configResolver = Mockery::mock(ConfigResolver::class);
    $configResolver->shouldReceive('resolveForProvider')
        ->once()
        ->with($providerId, $modelId)
        ->andReturn([
            'api_key' => '',
            'base_url' => 'https://chatgpt.com/backend-api',
            'model' => $modelId,
            'execution_controls' => ExecutionControls::defaults(),
            'timeout' => 60,
            'provider_name' => OpenAiCodexDefinition::KEY,
        ]);

    $credentialResolver = Mockery::mock(RuntimeCredentialResolver::class);
    $credentialResolver->shouldReceive('resolve')
        ->once()
        ->andReturn([
            'api_key' => 'aaa.bbb.ccc',
            'base_url' => 'https://chatgpt.com/backend-api',
            'headers' => ['chatgpt-account-id' => 'acct_test'],
        ]);

    $llmClient = Mockery::mock(LlmClient::class);
    $llmClient->shouldReceive('chat')
        ->once()
        ->with(Mockery::type(ChatRequest::class))
        ->andReturn($result->connected
            ? ['latency_ms' => $result->latencyMs]
            : ['runtime_error' => $result->error]);

    return new ProviderTestService($configResolver, $credentialResolver, $llmClient);
}
