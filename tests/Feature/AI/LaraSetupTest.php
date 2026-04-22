<?php

use App\Modules\Core\AI\Livewire\Setup\Lara;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('ai.workspace_path', storage_path('framework/testing/lara-setup-'.Str::random(16)));
});

afterEach(function (): void {
    $workspacePath = config('ai.workspace_path');

    if (is_string($workspacePath)) {
        File::deleteDirectory($workspacePath);
    }
});

test('lara provider change selects the new provider default model', function (): void {
    Company::query()->find(Company::LICENSEE_ID)
        ?? Company::factory()->create(['id' => Company::LICENSEE_ID]);

    $primaryProvider = AiProvider::query()->create([
        'company_id' => Company::LICENSEE_ID,
        'name' => 'provider-one',
        'display_name' => 'Provider One',
        'base_url' => 'https://provider-one.example.test',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'provider-one-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
    ]);

    $secondaryProvider = AiProvider::query()->create([
        'company_id' => Company::LICENSEE_ID,
        'name' => 'provider-two',
        'display_name' => 'Provider Two',
        'base_url' => 'https://provider-two.example.test',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'provider-two-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 2,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $primaryProvider->id,
        'model_id' => 'shared-model',
        'is_active' => true,
        'is_default' => true,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $secondaryProvider->id,
        'model_id' => 'shared-model',
        'is_active' => true,
        'is_default' => false,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $secondaryProvider->id,
        'model_id' => 'provider-two-default',
        'is_active' => true,
        'is_default' => true,
    ]);

    Livewire::test(Lara::class)
        ->assertSet('selectedProviderId', $primaryProvider->id)
        ->assertSet('selectedModelId', 'shared-model')
        ->set('selectedProviderId', $secondaryProvider->id)
        ->assertSet('selectedModelId', 'provider-two-default');
});

test('lara activation works with no task config present', function (): void {
    Company::query()->find(Company::LICENSEE_ID)
        ?? Company::factory()->create(['id' => Company::LICENSEE_ID]);

    Employee::provisionLara();

    $provider = AiProvider::query()->create([
        'company_id' => Company::LICENSEE_ID,
        'name' => 'openai',
        'display_name' => 'OpenAI',
        'base_url' => 'https://openai.example.test',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'openai-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => 'gpt-primary',
        'is_active' => true,
        'is_default' => true,
    ]);

    Livewire::test(Lara::class)
        ->set('selectedProviderId', $provider->id)
        ->call('activateLara');

    $workspaceConfig = app(ConfigResolver::class)->readWorkspaceConfig(Employee::LARA_ID);

    expect($workspaceConfig)->not->toBeNull()
        ->and($workspaceConfig['llm']['models'][0]['provider'] ?? null)->toBe('openai')
        ->and($workspaceConfig['llm']['models'][0]['model'] ?? null)->toBe('gpt-primary')
        ->and($workspaceConfig['llm']['tasks'] ?? null)->toBeNull();
});

test('lara setup preserves execution controls and timeout when the model changes', function (): void {
    Company::query()->find(Company::LICENSEE_ID)
        ?? Company::factory()->create(['id' => Company::LICENSEE_ID]);

    Employee::provisionLara();

    $primaryProvider = AiProvider::query()->create([
        'company_id' => Company::LICENSEE_ID,
        'name' => 'openai',
        'display_name' => 'OpenAI',
        'base_url' => 'https://openai.example.test',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'openai-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
    ]);

    $secondaryProvider = AiProvider::query()->create([
        'company_id' => Company::LICENSEE_ID,
        'name' => 'anthropic',
        'display_name' => 'Anthropic',
        'base_url' => 'https://anthropic.example.test',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'anthropic-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 2,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $primaryProvider->id,
        'model_id' => 'gpt-primary',
        'is_active' => true,
        'is_default' => true,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $secondaryProvider->id,
        'model_id' => 'claude-primary',
        'is_active' => true,
        'is_default' => true,
    ]);

    app(ConfigResolver::class)->writeWorkspaceConfig(Employee::LARA_ID, [
        'llm' => [
            'models' => [[
                'provider' => 'openai',
                'model' => 'gpt-primary',
                'execution_controls' => [
                    'limits' => [
                        'max_output_tokens' => 4096,
                    ],
                    'reasoning' => [
                        'mode' => 'auto',
                        'visibility' => 'summary',
                    ],
                ],
                'timeout' => 120,
            ]],
        ],
    ]);

    Livewire::test(Lara::class)
        ->set('selectedProviderId', $secondaryProvider->id)
        ->call('activateLara');

    $workspaceConfig = app(ConfigResolver::class)->readWorkspaceConfig(Employee::LARA_ID);

    expect($workspaceConfig)->not->toBeNull()
        ->and($workspaceConfig['llm']['models'][0]['provider'] ?? null)->toBe('anthropic')
        ->and($workspaceConfig['llm']['models'][0]['model'] ?? null)->toBe('claude-primary')
        ->and($workspaceConfig['llm']['models'][0]['execution_controls']['limits']['max_output_tokens'] ?? null)->toBe(4096)
        ->and($workspaceConfig['llm']['models'][0]['execution_controls']['reasoning']['visibility'] ?? null)->toBe('summary')
        ->and($workspaceConfig['llm']['models'][0]['timeout'] ?? null)->toBe(120);
});

test('lara activation persists edited execution controls for reasoning-capable models', function (): void {
    Company::query()->find(Company::LICENSEE_ID)
        ?? Company::factory()->create(['id' => Company::LICENSEE_ID]);

    Employee::provisionLara();

    $provider = AiProvider::query()->create([
        'company_id' => Company::LICENSEE_ID,
        'name' => 'openai',
        'display_name' => 'OpenAI',
        'base_url' => 'https://openai.example.test',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'openai-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => 'gpt-5.4',
        'is_active' => true,
        'is_default' => true,
    ]);

    Livewire::test(Lara::class)
        ->set('selectedProviderId', $provider->id)
        ->set('selectedModelId', 'gpt-5.4')
        ->set('primaryExecutionControls.limits.max_output_tokens', 3072)
        ->set('primaryExecutionControls.reasoning.visibility', 'summary')
        ->set('primaryExecutionControls.reasoning.effort', 'high')
        ->set('primaryExecutionControls.reasoning.budget', 1536)
        ->set('primaryExecutionControls.tools.preserve_reasoning_context', true)
        ->call('activateLara');

    $workspaceConfig = app(ConfigResolver::class)->readWorkspaceConfig(Employee::LARA_ID);

    expect($workspaceConfig)->not->toBeNull()
        ->and($workspaceConfig['llm']['models'][0]['execution_controls']['limits']['max_output_tokens'] ?? null)->toBe(3072)
        ->and($workspaceConfig['llm']['models'][0]['execution_controls']['reasoning']['visibility'] ?? null)->toBe('summary')
        ->and($workspaceConfig['llm']['models'][0]['execution_controls']['reasoning']['effort'] ?? null)->toBe('high')
        ->and($workspaceConfig['llm']['models'][0]['execution_controls']['reasoning']['budget'] ?? null)->toBe(1536)
        ->and($workspaceConfig['llm']['models'][0]['execution_controls']['tools']['preserve_reasoning_context'] ?? null)->toBeTrue();
});

test('lara setup shows provider-enforced values but keeps canonical execution controls when switching model families', function (): void {
    Company::query()->find(Company::LICENSEE_ID)
        ?? Company::factory()->create(['id' => Company::LICENSEE_ID]);

    Employee::provisionLara();

    $openAiProvider = AiProvider::query()->create([
        'company_id' => Company::LICENSEE_ID,
        'name' => 'openai',
        'display_name' => 'OpenAI',
        'base_url' => 'https://openai.example.test',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'openai-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
    ]);

    $moonshotProvider = AiProvider::query()->create([
        'company_id' => Company::LICENSEE_ID,
        'name' => 'moonshotai',
        'display_name' => 'Moonshot',
        'base_url' => 'https://moonshot.example.test',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'moonshot-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 2,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $openAiProvider->id,
        'model_id' => 'gpt-5.4',
        'is_active' => true,
        'is_default' => true,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $moonshotProvider->id,
        'model_id' => 'kimi-k2.5',
        'is_active' => true,
        'is_default' => true,
    ]);

    app(ConfigResolver::class)->writeWorkspaceConfig(Employee::LARA_ID, [
        'llm' => [
            'models' => [[
                'provider' => 'openai',
                'model' => 'gpt-5.4',
                'execution_controls' => [
                    'sampling' => [
                        'temperature' => 0.3,
                    ],
                    'reasoning' => [
                        'mode' => 'enabled',
                        'visibility' => 'summary',
                    ],
                ],
            ]],
        ],
    ]);

    Livewire::test(Lara::class)
        ->assertSee('Reasoning visibility')
        ->set('selectedProviderId', $moonshotProvider->id)
        ->assertSee('Provider-enforced value')
        ->assertSee('This model family also enforces top-p 0.95')
        ->call('activateLara');

    $workspaceConfig = app(ConfigResolver::class)->readWorkspaceConfig(Employee::LARA_ID);

    expect($workspaceConfig)->not->toBeNull()
        ->and($workspaceConfig['llm']['models'][0]['provider'] ?? null)->toBe('moonshotai')
        ->and($workspaceConfig['llm']['models'][0]['model'] ?? null)->toBe('kimi-k2.5')
        ->and($workspaceConfig['llm']['models'][0]['execution_controls']['sampling']['temperature'] ?? null)->toBe(0.3)
        ->and($workspaceConfig['llm']['models'][0]['execution_controls']['reasoning']['visibility'] ?? null)->toBe('summary');
});
