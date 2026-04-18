<?php

use App\Base\AI\Services\LlmClient;
use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\Enums\AiErrorType;
use App\Modules\Core\AI\DTO\LaraTaskDefinition;
use App\Modules\Core\AI\Enums\LaraTaskType;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\LaraTaskRegistry;
use App\Modules\Core\AI\Services\RuntimeCredentialResolver;
use App\Modules\Core\AI\Services\TaskModelRecommendationService;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const TASK_MODEL_RECO_BASE_URL = 'https://api.example.test/v1';
const TASK_MODEL_RECO_FAST_REASON = 'Fast for short labels.';

function makeTaskRecommendationService(string $responseContent): TaskModelRecommendationService
{
    $configResolver = Mockery::mock(ConfigResolver::class);
    $configResolver->shouldReceive('resolvePrimaryWithDefaultFallback')
        ->once()
        ->andReturn([
            'api_key' => 'primary-key',
            'base_url' => TASK_MODEL_RECO_BASE_URL,
            'model' => 'gpt-primary',
            'provider_name' => 'openai',
        ]);

    $llmClient = Mockery::mock(LlmClient::class);
    $llmClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => $responseContent,
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            'latency_ms' => 100,
        ]);

    $taskRegistry = Mockery::mock(LaraTaskRegistry::class);
    $taskRegistry->shouldReceive('find')
        ->once()
        ->with('titling')
        ->andReturn(new LaraTaskDefinition(
            key: 'titling',
            label: 'Titling',
            type: LaraTaskType::Simple,
            description: 'Generate concise session titles.',
            workloadDescription: 'Short labels with low latency and tiny output.',
            runtimeReady: true,
        ));

    $credentialResolver = Mockery::mock(RuntimeCredentialResolver::class);
    $credentialResolver->shouldReceive('resolve')
        ->once()
        ->andReturn([
            'api_key' => 'primary-key',
            'base_url' => TASK_MODEL_RECO_BASE_URL,
        ]);

    return new TaskModelRecommendationService(
        $configResolver,
        $llmClient,
        $taskRegistry,
        $credentialResolver,
    );
}

function makeTaskRecommendationServiceWithRuntimeError(AiRuntimeError $runtimeError): TaskModelRecommendationService
{
    $configResolver = Mockery::mock(ConfigResolver::class);
    $configResolver->shouldReceive('resolvePrimaryWithDefaultFallback')
        ->once()
        ->andReturn([
            'api_key' => 'primary-key',
            'base_url' => TASK_MODEL_RECO_BASE_URL,
            'model' => 'gpt-primary',
            'provider_name' => 'openai',
        ]);

    $llmClient = Mockery::mock(LlmClient::class);
    $llmClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'runtime_error' => $runtimeError,
            'latency_ms' => 100,
        ]);

    $taskRegistry = Mockery::mock(LaraTaskRegistry::class);
    $taskRegistry->shouldReceive('find')
        ->once()
        ->with('coding')
        ->andReturn(new LaraTaskDefinition(
            key: 'coding',
            label: 'Coding',
            type: LaraTaskType::Agentic,
            description: 'Handle code-focused work.',
            workloadDescription: 'Multi-step code and CLI work.',
            runtimeReady: false,
        ));

    $credentialResolver = Mockery::mock(RuntimeCredentialResolver::class);
    $credentialResolver->shouldReceive('resolve')
        ->once()
        ->andReturn([
            'api_key' => 'primary-key',
            'base_url' => TASK_MODEL_RECO_BASE_URL,
        ]);

    return new TaskModelRecommendationService(
        $configResolver,
        $llmClient,
        $taskRegistry,
        $credentialResolver,
    );
}

function seedRecommendationCandidates(): void
{
    $company = Company::query()->find(Company::LICENSEE_ID)
        ?? Company::factory()->create(['id' => Company::LICENSEE_ID]);

    $openai = AiProvider::query()->create([
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
        'ai_provider_id' => $openai->id,
        'model_id' => 'gpt-primary',
        'is_active' => true,
        'is_default' => true,
    ]);

    $anthropic = AiProvider::query()->create([
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
        'ai_provider_id' => $anthropic->id,
        'model_id' => 'claude-quick-title',
        'is_active' => true,
        'is_default' => true,
    ]);
}

test('recommendation parses fenced json responses', function (): void {
    seedRecommendationCandidates();

    $service = makeTaskRecommendationService(
        "```json\n"
        .'{"provider":"anthropic","model":"claude-quick-title","reason":"'.TASK_MODEL_RECO_FAST_REASON."\"}\n"
        ."```\n"
    );

    $result = $service->recommend(1, 'titling');

    expect($result)->toBe([
        'provider' => 'anthropic',
        'model' => 'claude-quick-title',
        'reason' => TASK_MODEL_RECO_FAST_REASON,
    ]);
});

test('recommendation parses structured plain text responses', function (): void {
    seedRecommendationCandidates();

    $service = makeTaskRecommendationService(
        "provider: anthropic\n"
        ."model: claude-quick-title\n"
        .'reason: '.TASK_MODEL_RECO_FAST_REASON."\n"
    );

    $result = $service->recommend(1, 'titling');

    expect($result)->toBe([
        'provider' => 'anthropic',
        'model' => 'claude-quick-title',
        'reason' => TASK_MODEL_RECO_FAST_REASON,
    ]);
});

test('recommendation parses provider and model pairs from free text', function (): void {
    seedRecommendationCandidates();

    $service = makeTaskRecommendationService(
        'I recommend anthropic/claude-quick-title because it is fast and sufficient for short titles.'
    );

    $result = $service->recommend(1, 'titling');

    expect($result['provider'])->toBe('anthropic')
        ->and($result['model'])->toBe('claude-quick-title')
        ->and($result['reason'])->toContain('fast and sufficient');
});

test('recommendation falls back to lara primary when the model returns an empty reply', function (): void {
    seedRecommendationCandidates();

    $service = makeTaskRecommendationServiceWithRuntimeError(
        AiRuntimeError::fromType(AiErrorType::EmptyResponse, 'Model "gpt-primary" produced no text content')
    );

    $result = $service->recommend(1, 'coding');

    expect($result)->toBe([
        'provider' => 'openai',
        'model' => 'gpt-primary',
        'reason' => 'Kept Lara primary after empty recommendation reply.',
    ]);
});
