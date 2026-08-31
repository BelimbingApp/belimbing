<?php

use App\Base\Support\DetachedProcessLauncher;
use App\Core\AI\Definitions\OpenAiCodexDefinition;
use App\Core\AI\Livewire\Providers\ImageProviderSetup;
use App\Core\AI\Livewire\Providers\OpenAiCodexSetup;
use App\Core\AI\Livewire\Providers\Providers;
use App\Core\AI\Livewire\Providers\ProviderSetup;
use App\Core\AI\Models\AiProvider;
use App\Core\AI\Services\ModelDiscoveryService;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use Livewire\Livewire;

/**
 * #451/#453 review (codex-gpt-5): the migration-ambiguity test alone cannot
 * fail if any one of the four write sites regresses back to reading
 * `Auth::user()->employee?->id` — each is exercised here directly, with a
 * company-scoped user whose employee_id is null (the exact state that made
 * the original bug silent), asserting created_by_user_id on the row it
 * actually wrote.
 */
function companyScopedUserWithNoEmployee(): User
{
    $company = Company::factory()->create();

    return User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => null,
    ]);
}

it('ManagesProviders::saveProvider persists the filer, not an employee link', function (): void {
    $user = companyScopedUserWithNoEmployee();
    $this->actingAs($user);

    Livewire::test(Providers::class)
        ->call('openCreateProvider')
        ->set('providerName', 'custom-provider')
        ->set('providerDisplayName', 'Custom Provider')
        ->set('providerBaseUrl', 'https://api.custom-provider.example/v1')
        ->set('providerApiKey', 'sk-test-custom')
        ->call('saveProvider')
        ->assertHasNoErrors();

    $provider = AiProvider::query()
        ->where('company_id', $user->company_id)
        ->where('name', 'custom-provider')
        ->sole();

    expect($provider->created_by_user_id)->toBe($user->id);
});

it('ImageProviderSetup::save persists the filer, not an employee link', function (): void {
    $user = companyScopedUserWithNoEmployee();
    $this->actingAs($user);

    Livewire::test(ImageProviderSetup::class)
        ->call('open', 'photoroom')
        ->set('values.apiKey', 'live-key-xyz')
        ->call('save')
        ->assertHasNoErrors();

    $provider = AiProvider::query()
        ->forCompany($user->company_id)
        ->image()
        ->where('name', 'photoroom')
        ->sole();

    expect($provider->created_by_user_id)->toBe($user->id);
});

it('ProviderSetup::connect persists the filer, not an employee link', function (): void {
    $user = companyScopedUserWithNoEmployee();
    $this->actingAs($user);

    $discovery = Mockery::mock(ModelDiscoveryService::class);
    $discovery->shouldReceive('syncModels')->andReturn([
        'added' => 0, 'updated' => 0, 'total' => 0, 'deactivated' => 0, 'source' => 'test',
    ]);
    app()->instance(ModelDiscoveryService::class, $discovery);

    Livewire::test(ProviderSetup::class, ['providerKey' => 'openai'])
        ->set('apiKey', 'sk-test-openai')
        ->call('connect')
        ->assertSet('connectError', null);

    $provider = AiProvider::query()
        ->forCompany($user->company_id)
        ->llm()
        ->where('name', 'openai')
        ->sole();

    expect($provider->created_by_user_id)->toBe($user->id);
});

it('OpenAiCodexSetup::startOauthLogin persists the filer, not an employee link', function (): void {
    $user = companyScopedUserWithNoEmployee();
    $this->actingAs($user);

    // startOauthLogin spawns a detached listener process as a side effect
    // unrelated to provider creation; faked so the test never shells out.
    $launcher = Mockery::mock(DetachedProcessLauncher::class);
    $launcher->shouldReceive('launch')->andReturn(false);
    app()->instance(DetachedProcessLauncher::class, $launcher);

    Livewire::test(OpenAiCodexSetup::class, ['providerKey' => OpenAiCodexDefinition::KEY])
        ->call('startOauthLogin');

    $provider = AiProvider::query()
        ->forCompany($user->company_id)
        ->where('name', OpenAiCodexDefinition::KEY)
        ->sole();

    expect($provider->created_by_user_id)->toBe($user->id);
});
