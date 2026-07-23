<?php

use App\Base\Locale\Services\LocaleSettings;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Livewire\Settings\Appearance;
use App\Modules\Core\User\Models\User;
use Livewire\Livewire;

it('gives a user without an employee complete appearance preferences', function (): void {
    $user = User::factory()->create(['employee_id' => null]);
    app(SettingsService::class)->set(LocaleSettings::LOCALE_KEY, 'en-GB');

    $this->actingAs($user);

    Livewire::test(Appearance::class)
        ->assertSet('theme', 'system')
        ->assertSet('locale', '')
        ->assertSee('Use installation default');

    expect(app(LocaleSettings::class)->forUser($user))->toBe('en-GB');
});

it('stores theme and locale against the authenticated user and restores inheritance', function (): void {
    $user = User::factory()->create(['employee_id' => null]);
    $scope = Scope::user((int) $user->getKey(), $user->getCompanyId());
    $settings = app(SettingsService::class);

    $this->actingAs($user);

    Livewire::test(Appearance::class)
        ->set('theme', 'dark')
        ->set('locale', 'en-US')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('theme-changed', theme: 'dark');

    expect($settings->get('ui.theme', $scope))->toBe('dark')
        ->and($settings->get(LocaleSettings::LOCALE_KEY, $scope))->toBe('en-US');

    Livewire::test(Appearance::class)
        ->set('theme', 'system')
        ->set('locale', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($settings->has('ui.theme', $scope))->toBeFalse()
        ->and($settings->has(LocaleSettings::LOCALE_KEY, $scope))->toBeFalse()
        ->and($settings->get('ui.theme', $scope))->toBe('system');
});

it('persists the compact top-bar theme control to user scope', function (): void {
    $user = User::factory()->create(['employee_id' => null]);
    $scope = Scope::user((int) $user->getKey(), $user->getCompanyId());
    $settings = app(SettingsService::class);

    $this->actingAs($user)
        ->postJson(route('theme.set'), ['theme' => 'dark'])
        ->assertOk()
        ->assertJson(['theme' => 'dark']);

    expect($settings->get('ui.theme', $scope))->toBe('dark');

    $this->postJson(route('theme.set'), ['theme' => 'system'])
        ->assertOk()
        ->assertJson(['theme' => 'system']);

    expect($settings->has('ui.theme', $scope))->toBeFalse();
});

it('requires authentication for the theme endpoint', function (): void {
    $this->postJson(route('theme.set'), ['theme' => 'dark'])
        ->assertRedirect(route('login'));
});

it('keeps last-used AI model hints with the user rather than an employee scope', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => null,
    ]);
    $scope = Scope::user((int) $user->getKey(), $user->getCompanyId());
    $provider = AiProvider::query()->create([
        'company_id' => $user->getCompanyId(),
        'name' => 'openai',
        'family' => AiProvider::FAMILY_LLM,
        'display_name' => 'OpenAI',
        'base_url' => 'https://api.openai.com/v1',
        'auth_type' => 'api_key',
        'credentials' => [],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
        'created_by' => null,
    ]);
    AiProviderModel::query()->create([
        'ai_provider_id' => $provider->getKey(),
        'model_id' => 'gpt-5',
        'is_active' => true,
        'is_default' => false,
    ]);

    $user->setLastUsedModel(42, 'openai', 'gpt-5');

    expect($user->getLastUsedModel(42))->toBe([
        'provider' => 'openai',
        'model' => 'gpt-5',
    ])->and(app(SettingsService::class)->has('ai.last_used_model_hints', $scope))->toBeTrue();

    $user->setLastUsedModel(42, null, null);

    expect($user->getLastUsedModel(42))->toBeNull()
        ->and(app(SettingsService::class)->has('ai.last_used_model_hints', $scope))->toBeFalse();
});
