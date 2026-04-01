<?php

use App\Modules\Core\AI\Livewire\Providers\Providers;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Livewire\Livewire;

const AI_PROVIDERS_SAVED_KEY = 'sk-test-1234567890abcd';

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
