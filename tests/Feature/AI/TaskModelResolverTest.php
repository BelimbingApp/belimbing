<?php

use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('ai.workspace_path', storage_path('framework/testing/task-resolver-'.Str::random(16)));
});

afterEach(function (): void {
    $workspacePath = config('ai.workspace_path');

    if (is_string($workspacePath)) {
        File::deleteDirectory($workspacePath);
    }
});

test('resolve task uses the saved task model when it is valid', function (): void {
    $company = Company::query()->find(Company::LICENSEE_ID)
        ?? Company::factory()->create(['id' => Company::LICENSEE_ID]);
    Employee::provisionLara();

    AiProvider::query()->create([
        'company_id' => $company->id,
        'name' => 'openai',
        'display_name' => 'OpenAI',
        'base_url' => 'https://openai.example.test',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'openai-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
    ]);

    $researchProvider = AiProvider::query()->create([
        'company_id' => $company->id,
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
        'ai_provider_id' => $researchProvider->id,
        'model_id' => 'claude-research',
        'is_active' => true,
        'is_default' => true,
    ]);

    $resolver = app(ConfigResolver::class);
    $resolver->writeWorkspaceConfig(Employee::LARA_ID, [
        'llm' => [
            'models' => [[
                'provider' => 'openai',
                'model' => 'gpt-primary',
            ]],
            'tasks' => [
                'research' => [
                    'mode' => 'manual',
                    'provider' => 'anthropic',
                    'model' => 'claude-research',
                ],
            ],
        ],
    ]);

    $resolved = $resolver->resolveTaskWithPrimaryFallback(Employee::LARA_ID, 'research');

    expect($resolved)->not->toBeNull()
        ->and($resolved['provider_name'])->toBe('anthropic')
        ->and($resolved['model'])->toBe('claude-research');
});

test('resolve task falls back to lara primary when the saved task model is incomplete', function (): void {
    $company = Company::query()->find(Company::LICENSEE_ID)
        ?? Company::factory()->create(['id' => Company::LICENSEE_ID]);
    Employee::provisionLara();

    $primaryProvider = AiProvider::query()->create([
        'company_id' => $company->id,
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
        'ai_provider_id' => $primaryProvider->id,
        'model_id' => 'gpt-primary',
        'is_active' => true,
        'is_default' => true,
    ]);

    $resolver = app(ConfigResolver::class);
    $resolver->writeWorkspaceConfig(Employee::LARA_ID, [
        'llm' => [
            'models' => [[
                'provider' => 'openai',
                'model' => 'gpt-primary',
            ]],
            'tasks' => [
                'coding' => [
                    'mode' => 'recommended',
                ],
            ],
        ],
    ]);

    $resolved = $resolver->resolveTaskWithPrimaryFallback(Employee::LARA_ID, 'coding');

    expect($resolved)->not->toBeNull()
        ->and($resolved['provider_name'])->toBe('openai')
        ->and($resolved['model'])->toBe('gpt-primary');
});

test('resolve task falls back to lara primary when the saved recommended model is invalid', function (): void {
    $company = Company::query()->find(Company::LICENSEE_ID)
        ?? Company::factory()->create(['id' => Company::LICENSEE_ID]);
    Employee::provisionLara();

    $primaryProvider = AiProvider::query()->create([
        'company_id' => $company->id,
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
        'ai_provider_id' => $primaryProvider->id,
        'model_id' => 'gpt-primary',
        'is_active' => true,
        'is_default' => true,
    ]);

    $resolver = app(ConfigResolver::class);
    $resolver->writeWorkspaceConfig(Employee::LARA_ID, [
        'llm' => [
            'models' => [[
                'provider' => 'openai',
                'model' => 'gpt-primary',
            ]],
            'tasks' => [
                'coding' => [
                    'mode' => 'recommended',
                    'provider' => 'anthropic',
                    'model' => 'missing-model',
                ],
            ],
        ],
    ]);

    $resolved = $resolver->resolveTaskWithPrimaryFallback(Employee::LARA_ID, 'coding');

    expect($resolved)->not->toBeNull()
        ->and($resolved['provider_name'])->toBe('openai')
        ->and($resolved['model'])->toBe('gpt-primary');
});

test('resolve task uses lara primary when the task mode is primary', function (): void {
    $company = Company::query()->find(Company::LICENSEE_ID)
        ?? Company::factory()->create(['id' => Company::LICENSEE_ID]);
    Employee::provisionLara();

    $primaryProvider = AiProvider::query()->create([
        'company_id' => $company->id,
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
        'ai_provider_id' => $primaryProvider->id,
        'model_id' => 'gpt-primary',
        'is_active' => true,
        'is_default' => true,
    ]);

    $resolver = app(ConfigResolver::class);
    $resolver->writeWorkspaceConfig(Employee::LARA_ID, [
        'llm' => [
            'models' => [[
                'provider' => 'openai',
                'model' => 'gpt-primary',
            ]],
            'tasks' => [
                'titling' => [
                    'mode' => 'primary',
                ],
            ],
        ],
    ]);

    $resolved = $resolver->resolveTaskWithPrimaryFallback(Employee::LARA_ID, 'titling');

    expect($resolved)->not->toBeNull()
        ->and($resolved['provider_name'])->toBe('openai')
        ->and($resolved['model'])->toBe('gpt-primary');
});
