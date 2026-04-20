<?php

use App\Modules\Core\AI\Livewire\Setup\Lara;
use App\Modules\Core\AI\Livewire\TaskModels;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\TaskModelRecommendationService;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;

const TASK_MODELS_FAST_REASON = 'Fast for short labels.';

beforeEach(function (): void {
    config()->set('ai.workspace_path', storage_path('framework/testing/task-models-'.Str::random(16)));
});

afterEach(function (): void {
    $workspacePath = config('ai.workspace_path');

    if (is_string($workspacePath)) {
        File::deleteDirectory($workspacePath);
    }
});

test('task models page shows activation notice when lara is not activated', function (): void {
    $user = createTaskModelsTestUser();
    $this->actingAs($user);

    $this->get(route('admin.ai.task-models'))
        ->assertOk()
        ->assertSee('Task models become available after Lara has been activated');
});

test('recommendation saves a stable recommended task model choice', function (): void {
    activateLaraForTaskModels();
    $user = createTaskModelsTestUser();
    $this->actingAs($user);

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
        'ai_provider_id' => $secondaryProvider->id,
        'model_id' => 'claude-quick-title',
        'is_active' => true,
        'is_default' => true,
    ]);

    $service = Mockery::mock(TaskModelRecommendationService::class);
    $service->shouldReceive('recommend')
        ->once()
        ->with(Employee::LARA_ID, 'titling')
        ->andReturn([
            'provider' => 'anthropic',
            'model' => 'claude-quick-title',
            'reason' => TASK_MODELS_FAST_REASON,
        ]);
    app()->instance(TaskModelRecommendationService::class, $service);

    Livewire::test(TaskModels::class)
        ->call('recommendTask', 'titling')
        ->assertSet('taskModes.titling', 'recommended')
        ->assertSet('taskProviderIds.titling', $secondaryProvider->id)
        ->assertSet('taskModelIds.titling', 'claude-quick-title')
        ->assertSet('taskReasons.titling', TASK_MODELS_FAST_REASON);

    $config = app(ConfigResolver::class)->readTaskConfig(Employee::LARA_ID, 'titling');

    expect($config)->toMatchArray([
        'mode' => 'recommended',
        'provider' => 'anthropic',
        'model' => 'claude-quick-title',
        'reason' => TASK_MODELS_FAST_REASON,
    ]);
});

test('manual task model selection auto-picks the provider default model and persists it', function (): void {
    activateLaraForTaskModels();
    $user = createTaskModelsTestUser();
    $this->actingAs($user);

    $codingProvider = AiProvider::query()->create([
        'company_id' => Company::LICENSEE_ID,
        'name' => 'openrouter',
        'display_name' => 'OpenRouter',
        'base_url' => 'https://openrouter.example.test',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'openrouter-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 2,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $codingProvider->id,
        'model_id' => 'coder-default',
        'is_active' => true,
        'is_default' => true,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $codingProvider->id,
        'model_id' => 'coder-large',
        'is_active' => true,
        'is_default' => false,
    ]);

    Livewire::test(TaskModels::class)
        ->set('taskModes.coding', 'manual')
        ->set('taskProviderIds.coding', $codingProvider->id)
        ->assertSet('taskModelIds.coding', 'coder-default');

    $config = app(ConfigResolver::class)->readTaskConfig(Employee::LARA_ID, 'coding');

    expect($config)->toMatchArray([
        'mode' => 'manual',
        'provider' => 'openrouter',
        'model' => 'coder-default',
    ]);
});

test('lara setup preserves task config when primary model changes', function (): void {
    [$primaryProvider] = activateLaraForTaskModels();
    $user = createTaskModelsTestUser();
    $this->actingAs($user);

    $secondaryProvider = AiProvider::query()->create([
        'company_id' => Company::LICENSEE_ID,
        'name' => 'openai-alt',
        'display_name' => 'OpenAI Alt',
        'base_url' => 'https://openai-alt.example.test',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'openai-alt-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 2,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $secondaryProvider->id,
        'model_id' => 'gpt-alt-default',
        'is_active' => true,
        'is_default' => true,
    ]);

    app(ConfigResolver::class)->writeTaskConfig(Employee::LARA_ID, 'titling', [
        'mode' => 'recommended',
        'provider' => $primaryProvider->name,
        'model' => 'gpt-primary',
        'reason' => 'Existing task config',
    ]);

    Livewire::test(Lara::class)
        ->set('selectedProviderId', $secondaryProvider->id)
        ->call('activateLara');

    $config = app(ConfigResolver::class)->readTaskConfig(Employee::LARA_ID, 'titling');

    expect($config)->toMatchArray([
        'mode' => 'recommended',
        'provider' => 'openai',
        'model' => 'gpt-primary',
        'reason' => 'Existing task config',
    ]);
});

test('task model save preserves existing execution controls', function (): void {
    activateLaraForTaskModels();
    $user = createTaskModelsTestUser();
    $this->actingAs($user);

    app(ConfigResolver::class)->writeTaskConfig(Employee::LARA_ID, 'coding', [
        'mode' => 'manual',
        'provider' => 'openai',
        'model' => 'gpt-primary',
        'execution_controls' => [
            'limits' => [
                'max_output_tokens' => 1024,
            ],
            'reasoning' => [
                'mode' => 'auto',
                'visibility' => 'summary',
            ],
        ],
    ]);

    Livewire::test(TaskModels::class)
        ->set('taskModes.coding', 'primary');

    $config = app(ConfigResolver::class)->readTaskConfig(Employee::LARA_ID, 'coding');

    expect($config)->toMatchArray([
        'mode' => 'primary',
        'execution_controls' => [
            'limits' => [
                'max_output_tokens' => 1024,
            ],
            'reasoning' => [
                'mode' => 'auto',
                'visibility' => 'summary',
            ],
        ],
    ]);
});

function createTaskModelsTestUser(): User
{
    setupAuthzRoles();

    $company = Company::query()->find(Company::LICENSEE_ID)
        ?? Company::factory()->create(['id' => Company::LICENSEE_ID]);

    $employee = Employee::factory()->create(['company_id' => $company->id]);

    $user = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
    ]);

    $role = Role::query()->where('code', 'ai_operator')->whereNull('company_id')->firstOrFail();

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);

    return $user;
}

/**
 * @return array{AiProvider}
 */
function activateLaraForTaskModels(): array
{
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

    app(ConfigResolver::class)->writeWorkspaceConfig(Employee::LARA_ID, [
        'llm' => [
            'models' => [[
                'provider' => 'openai',
                'model' => 'gpt-primary',
            ]],
        ],
    ]);

    return [$primaryProvider];
}
