<?php

use App\Modules\Core\AI\Livewire\Providers\Providers;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Livewire\Livewire;

function createModelNotificationProvider(): AiProviderModel
{
    $company = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $user = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
    ]);

    test()->actingAs($user);

    $provider = AiProvider::query()->create([
        'company_id' => $company->id,
        'name' => 'openai',
        'display_name' => 'OpenAI',
        'base_url' => 'https://api.openai.com/v1',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'sk-test-1234567890abcd'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
        'created_by' => $employee->id,
    ]);

    return AiProviderModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => 'gpt-test',
        'is_active' => false,
        'is_default' => false,
    ]);
}

test('toggling a model dispatches a success notification instead of a session flash', function (): void {
    $model = createModelNotificationProvider();

    Livewire::test(Providers::class)
        ->call('toggleModelActive', $model->id)
        ->assertDispatched(
            'notify',
            variant: 'success',
            message: __('Model availability updated.'),
        );

    expect(session()->has('success'))->toBeFalse();
});

test('saving a model without a provider dispatches an error notification', function (): void {
    createModelNotificationProvider();

    Livewire::test(Providers::class)
        ->set('modelProviderId', null)
        ->call('saveModel')
        ->assertDispatched(
            'notify',
            variant: 'error',
            message: __('Choose a provider before adding a model.'),
        );
});
