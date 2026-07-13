<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\AI\Livewire\Providers\CatalogBrowser;
use App\Modules\Core\AI\Livewire\Providers\GithubCopilotSetup;
use App\Modules\Core\AI\Livewire\Providers\Providers;
use App\Modules\Core\AI\Livewire\Providers\ProviderSetup;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\ProviderAuthFlowService;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Livewire\Livewire;

const PROVIDER_CATALOG_SEARCH_PLACEHOLDER = 'Search providers...';

const AI_PROVIDERS_SAVED_KEY = 'sk-test-1234567890abcd';
const AI_GITHUB_DEVICE_FLOW_USER_CODE = 'ABCD-1234';
const AI_GITHUB_DEVICE_FLOW_VERIFICATION_URI = 'https://github.com/login/device';
const AI_PROVIDERS_VISIBLE_TEST_KEY = 'visible-provider-key-123';

test('edit provider modal hydrates the api key when one exists', function (): void {
    $user = createAiProvidersTestUser();
    $provider = createAiProvidersTestProvider($user, AI_PROVIDERS_SAVED_KEY);

    $this->actingAs($user);

    Livewire::test(Providers::class)
        ->call('openEditProvider', $provider->id)
        ->assertSee('Focus to replace')
        ->assertSet('providerApiKey', AI_PROVIDERS_SAVED_KEY);
});

test('edit provider api key field remounts with the stored key available for reveal', function (): void {
    $user = createAiProvidersTestUser();
    $provider = createAiProvidersTestProvider($user, AI_PROVIDERS_VISIBLE_TEST_KEY);

    $this->actingAs($user);

    Livewire::test(Providers::class)
        ->call('openCreateProvider')
        ->call('openEditProvider', $provider->id)
        ->assertSee('wire:key="provider-api-key-edit-'.$provider->id.'-stored"', false)
        ->assertSee('id="provider-api-key-edit-'.$provider->id.'"', false)
        ->assertSee("pendingSecret: '".AI_PROVIDERS_VISIBLE_TEST_KEY."'", false);
});

test('edit provider modal stays empty when no api key is saved', function (): void {
    $user = createAiProvidersTestUser();
    $provider = createAiProvidersTestProvider($user, '');

    $this->actingAs($user);

    Livewire::test(Providers::class)
        ->call('openEditProvider', $provider->id)
        ->assertSet('providerApiKey', '');
});

test('edit provider advanced settings can be saved and reset (global)', function (): void {
    $user = createAiProvidersTestUser();
    $provider = AiProvider::query()->create([
        'company_id' => $user->company_id,
        'name' => 'openai-codex',
        'display_name' => 'OpenAI Codex',
        'base_url' => 'https://chatgpt.com/backend-api',
        'auth_type' => 'oauth',
        'credentials' => [],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
        'created_by' => $user->employee_id,
    ]);

    $this->actingAs($user);

    $settingsKey = 'ai.openai_codex.models_discovery_client_version';

    Livewire::test(Providers::class)
        ->call('openEditProvider', $provider->id)
        ->assertSet('advancedSettingsSchema', fn (array $schema): bool => $schema !== [])
        ->set('advancedSettings.modelsDiscoveryClientVersion', '0.129.0')
        ->call('saveAdvancedSettings')
        ->assertHasNoErrors();

    $settings = app(SettingsService::class);
    expect($settings->get($settingsKey, default: null, scope: null))->toBe('0.129.0');

    Livewire::test(Providers::class)
        ->call('openEditProvider', $provider->id)
        ->call('resetAdvancedSettings')
        ->assertHasNoErrors();

    expect($settings->has($settingsKey, scope: null))->toBeFalse();
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

test('provider setup resolves base url from catalog on demand and labels the field', function (): void {
    Http::fake([
        'https://models.dev/api.json' => Http::response([
            'moonshotai' => [
                'api' => 'https://api.moonshot.ai/v1',
                'name' => 'Moonshot AI',
                'models' => [],
            ],
        ], 200, ['ETag' => '"moonshot-etag"']),
    ]);

    $user = createAiProvidersTestUser();
    $this->actingAs($user);

    Livewire::test(ProviderSetup::class, ['providerKey' => 'moonshotai'])
        ->assertSet('baseUrl', 'https://api.moonshot.ai/v1')
        ->assertSee('Base URL (from provider catalog)')
        ->assertSee('Get API Key');
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

test('providers page explains how to activate Lara when no active model is configured', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    $this->get(route('admin.ai.providers'))
        ->assertOk()
        ->assertSee('Lara stays inactive until one connected provider has an active model available to Agents.')
        ->assertSee('Connect a provider below and enable at least one model. If Lara still needs provisioning afterward, finish it on the')
        ->assertSee('href="'.route('admin.setup.lara').'"', false);
});

test('provider catalog is a lazy island kept out of the page initial paint', function (): void {
    $user = createAiProvidersTestUser();
    $this->actingAs($user);

    // This test's contract is the lazy island itself, so observe real placeholder
    // rendering instead of the suite-wide eager render.
    $this->withRealLazyLoading();

    // The full-page component must defer the ~100-row models.dev catalog: its
    // initial HTML carries only the lazy placeholder, not the catalog UI.
    Livewire::test(Providers::class)
        ->assertSee('Loading provider catalog')
        ->assertDontSee(PROVIDER_CATALOG_SEARCH_PLACEHOLDER);
});

test('provider catalog island renders the catalog once lazily loaded', function (): void {
    $user = createAiProvidersTestUser();
    $this->actingAs($user);

    $this->withRealLazyLoading();

    $component = Livewire::test(CatalogBrowser::class);
    $component->assertDontSee(PROVIDER_CATALOG_SEARCH_PLACEHOLDER);

    // Hydrate the lazy island the way Alpine's x-intersect would in the browser.
    preg_match('/__lazyLoad\(&#039;([^&]+)&#039;\)/', $component->html(), $matches);
    expect($matches[1] ?? null)->not->toBeNull();

    $component->call('__lazyLoad', $matches[1])
        ->assertSee(PROVIDER_CATALOG_SEARCH_PLACEHOLDER)
        ->assertSee('Connect');
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
