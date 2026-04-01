<?php

use App\Modules\Core\AI\Livewire\Setup\Lara;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('ai.workspace_path', storage_path('framework/testing/lara-setup-'.Str::random(16)));
});

afterEach(function (): void {
    $workspacePath = config('ai.workspace_path');

    if (is_string($workspacePath)) {
        File::deleteDirectory($workspacePath);
    }
});

test('lara provider change selects the new provider default model', function (): void {
    Company::query()->find(Company::LICENSEE_ID)
        ?? Company::factory()->create(['id' => Company::LICENSEE_ID]);

    $primaryProvider = AiProvider::query()->create([
        'company_id' => Company::LICENSEE_ID,
        'name' => 'provider-one',
        'display_name' => 'Provider One',
        'base_url' => 'https://provider-one.example.test',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'provider-one-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
    ]);

    $secondaryProvider = AiProvider::query()->create([
        'company_id' => Company::LICENSEE_ID,
        'name' => 'provider-two',
        'display_name' => 'Provider Two',
        'base_url' => 'https://provider-two.example.test',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'provider-two-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 2,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $primaryProvider->id,
        'model_id' => 'shared-model',
        'is_active' => true,
        'is_default' => true,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $secondaryProvider->id,
        'model_id' => 'shared-model',
        'is_active' => true,
        'is_default' => false,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $secondaryProvider->id,
        'model_id' => 'provider-two-default',
        'is_active' => true,
        'is_default' => true,
    ]);

    Livewire::test(Lara::class)
        ->assertSet('selectedProviderId', $primaryProvider->id)
        ->assertSet('selectedModelId', 'shared-model')
        ->set('selectedProviderId', $secondaryProvider->id)
        ->assertSet('selectedModelId', 'provider-two-default');
});
