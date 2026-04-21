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
use App\Modules\Core\AI\Services\ProviderTestService;
use App\Modules\Core\AI\Services\RuntimeCredentialResolver;
use App\Modules\Core\User\Models\User;
use Livewire\Livewire;

test('openai codex setup surfaces connected auth state and diagnostic action', function (): void {
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
    createOpenAiCodexModel($provider, 'gpt-5.1-codex-mini');

    $this->actingAs($user);

    Livewire::test(OpenAiCodexSetup::class, ['providerKey' => OpenAiCodexDefinition::KEY])
        ->assertSet('connectedProviderId', $provider->id)
        ->assertSee('Verify connection')
        ->assertSee('Connection status')
        ->assertSee('Browser OAuth')
        ->assertSee('codex_pro');
});

test('openai codex setup records successful verification diagnostics', function (): void {
    $user = createAdminUser();
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
        result: ProviderTestResult::success(
            providerName: OpenAiCodexDefinition::KEY,
            model: 'gpt-5.1-codex-mini',
            latencyMs: 84,
        ),
    ));
    $this->actingAs($user);

    Livewire::test(OpenAiCodexSetup::class, ['providerKey' => OpenAiCodexDefinition::KEY])
        ->call('verifyConnection')
        ->assertSet('verificationResult.connected', true)
        ->assertSet('verificationResult.model', 'gpt-5.1-codex-mini')
        ->assertSee('Verification succeeded for gpt-5.1-codex-mini in 84 ms.');

    $auth = $provider->fresh()->connection_config[OpenAiCodexDefinition::AUTH_STATE_KEY] ?? [];

    expect($auth['status'] ?? null)->toBe('connected')
        ->and($auth['last_error_code'] ?? null)->toBeNull()
        ->and($auth['last_error_message'] ?? null)->toBeNull();
});

test('openai codex setup marks provider expired when verification returns auth error', function (): void {
    $user = createAdminUser();
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
        result: ProviderTestResult::failure(
            providerName: OpenAiCodexDefinition::KEY,
            model: 'gpt-5.1-codex-mini',
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

test('openai codex setup shows reconnect guidance when verification returns a hint', function (): void {
    $user = createAdminUser();
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
        result: ProviderTestResult::failure(
            providerName: OpenAiCodexDefinition::KEY,
            model: 'gpt-5.1-codex-mini',
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

function makeCodexProviderTestService(int $providerId, ProviderTestResult $result): ProviderTestService
{
    $configResolver = Mockery::mock(ConfigResolver::class);
    $configResolver->shouldReceive('resolveForProvider')
        ->once()
        ->with($providerId, 'gpt-5.1-codex-mini')
        ->andReturn([
            'api_key' => '',
            'base_url' => 'https://chatgpt.com/backend-api',
            'model' => 'gpt-5.1-codex-mini',
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
