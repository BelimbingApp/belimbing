<?php

use App\Modules\Core\AI\Livewire\Setup\Lara as LaraSetup;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

use function Pest\Laravel\get;

test('ai menu exposes lara entry point', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    get(route('admin.ai.providers'))
        ->assertOk()
        ->assertSee('Lara')
        ->assertSee(route('admin.setup.lara'), false);
});

test('lara setup shows provider onboarding step when no providers exist', function (): void {
    $user = createAdminUser();
    Employee::provisionLara();
    resetLaraWorkspace();

    AiProvider::query()
        ->where('company_id', Company::LICENSEE_ID)
        ->delete();

    $this->actingAs($user);

    get(route('admin.setup.lara'))
        ->assertOk()
        ->assertSee('Connect a Provider')
        ->assertDontSee('<button href="'.route('admin.ai.providers').'"', false)
        ->assertSee(route('admin.ai.providers'), false);
});

test('lara setup shows activation step when providers exist but no model is ready', function (): void {
    $user = createAdminUser();
    Employee::provisionLara();
    resetLaraWorkspace();

    $provider = AiProvider::query()->create([
        'company_id' => Company::LICENSEE_ID,
        'name' => 'openai',
        'display_name' => 'OpenAI',
        'base_url' => 'https://api.openai.com/v1',
        'api_key' => 'test-key',
        'is_active' => true,
        'priority' => 1,
        'created_by' => null,
    ]);

    $this->actingAs($user);

    get(route('admin.setup.lara'))
        ->assertOk()
        ->assertSee('Activate Lara')
        ->assertSee('No active models found for this provider. Add one in provider connections, then come back.')
        ->assertSee('Manage Providers');
});

test('lara activation rejects inactive provider and does not write config', function (): void {
    $user = createAdminUser();
    Employee::provisionLara();
    resetLaraWorkspace();

    $provider = AiProvider::query()->create([
        'company_id' => Company::LICENSEE_ID,
        'name' => 'inactive-provider',
        'display_name' => 'Inactive Provider',
        'base_url' => 'https://example.test/v1',
        'api_key' => 'test-key',
        'is_active' => false,
        'priority' => 1,
        'created_by' => null,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => 'inactive-model',
        'is_active' => true,
        'is_default' => true,
    ]);

    $this->actingAs($user);

    Livewire::test(LaraSetup::class)
        ->set('selectedProviderId', $provider->id)
        ->set('selectedModelId', 'inactive-model')
        ->call('activateLara')
        ->assertHasErrors(['selectedProviderId']);

    expect(File::exists(config('ai.workspace_path').'/'.Employee::LARA_ID.'/config.json'))->toBeFalse();
});

function resetLaraWorkspace(): void
{
    $path = config('ai.workspace_path').'/'.Employee::LARA_ID;

    if (File::isDirectory($path)) {
        File::deleteDirectory($path);
    }
}
