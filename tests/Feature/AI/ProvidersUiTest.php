<?php

use App\Modules\Core\AI\Livewire\Providers\GithubCopilotSetup;
use App\Modules\Core\AI\Livewire\Providers\Providers;
use App\Modules\Core\AI\Livewire\Providers\ProviderSetup;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\ProviderAuthFlowService;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Livewire\Livewire;

const AI_PROVIDERS_SAVED_KEY = 'sk-test-1234567890abcd';
const AI_GITHUB_DEVICE_FLOW_USER_CODE = 'ABCD-1234';
const AI_GITHUB_DEVICE_FLOW_VERIFICATION_URI = 'https://github.com/login/device';

test('edit provider modal shows the current masked api key in the label', function (): void {
    $user = createAiProvidersTestUser();
    $provider = createAiProvidersTestProvider($user, AI_PROVIDERS_SAVED_KEY);

    $this->actingAs($user);

    Livewire::test(Providers::class)
        ->call('openEditProvider', $provider->id)
        ->assertSee('Current key:')
        ->assertSee('sk-test***********abcd');
});

test('edit provider modal shows current key not set when no api key is saved', function (): void {
    $user = createAiProvidersTestUser();
    $provider = createAiProvidersTestProvider($user, '');

    $this->actingAs($user);

    Livewire::test(Providers::class)
        ->call('openEditProvider', $provider->id)
        ->assertSee('Current key:')
        ->assertSee('not set');
});

test('github copilot setup starts device flow for a company-scoped user without employee link', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => null,
    ]);

    $this->actingAs($user);

    $flowService = Mockery::mock(ProviderAuthFlowService::class);
    $flowService
        ->shouldReceive('startFlow')
        ->once()
        ->with('github-copilot', $company->id, 0)
        ->andReturn([
            'status' => 'pending',
            'user_code' => AI_GITHUB_DEVICE_FLOW_USER_CODE,
            'verification_uri' => AI_GITHUB_DEVICE_FLOW_VERIFICATION_URI,
            'error' => null,
        ]);

    app()->instance(ProviderAuthFlowService::class, $flowService);

    Livewire::test(GithubCopilotSetup::class, ['providerKey' => 'github-copilot'])
        ->assertSet('deviceFlow.status', 'pending')
        ->assertSee(AI_GITHUB_DEVICE_FLOW_USER_CODE)
        ->assertSee('Copy');
});

test('generic oauth provider setup is honest about missing dedicated sign-in support', function (): void {
    $user = createAiProvidersTestUser();

    $this->actingAs($user);

    Livewire::test(ProviderSetup::class, ['providerKey' => 'qwen-portal'])
        ->assertSee('requires a dedicated OAuth sign-in flow')
        ->assertDontSee('API Key (optional)')
        ->set('baseUrl', 'https://portal.qwen.ai/v1')
        ->call('connect')
        ->assertSet('connectError', 'This provider requires a dedicated OAuth sign-in flow. Belimbing does not implement a generic OAuth connector yet.');

    expect(AiProvider::query()
        ->where('company_id', $user->company_id)
        ->where('name', 'qwen-portal')
        ->exists())->toBeFalse();
});

function createAiProvidersTestUser(): User
{
    $company = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $company->id]);

    return User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
    ]);
}

function createAiProvidersTestProvider(User $user, string $apiKey): AiProvider
{
    return AiProvider::query()->create([
        'company_id' => $user->company_id,
        'name' => 'openai',
        'display_name' => 'OpenAI',
        'base_url' => 'https://api.openai.com/v1',
        'auth_type' => 'api_key',
        'credentials' => $apiKey !== '' ? ['api_key' => $apiKey] : [],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
        'created_by' => $user->employee_id,
    ]);
}
