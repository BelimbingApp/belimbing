<?php

use App\Base\Media\PhotoCleanup\Contracts\ImageProviderCredentialStore;
use App\Base\Media\PhotoCleanup\PhotoRoomConfiguration;
use App\Base\Media\PhotoCleanup\StabilityConfiguration;
use App\Modules\Core\AI\Livewire\Providers\Providers;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\AiProviderFamilyRegistry;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Livewire\Livewire;

function aiProviderFamily(string $key)
{
    return app(AiProviderFamilyRegistry::class)->family($key);
}

it('registers the llm and image provider families through the container tag', function (): void {
    $keys = collect(app(AiProviderFamilyRegistry::class)->families())
        ->map(fn ($family): string => $family->key())
        ->all();

    expect($keys)->toContain('llm')
        ->and($keys)->toContain('image');
});

it('catalogs photoroom plus the credential-only image providers', function (): void {
    $company = Company::factory()->create();
    $summaries = aiProviderFamily('image')->providers($company->id);

    $photoRoom = collect($summaries)->firstWhere('providerKey', PhotoRoomConfiguration::PROVIDER);
    expect($photoRoom->familyKey)->toBe('image')
        ->and($photoRoom->displayName)->toBe(PhotoRoomConfiguration::PROVIDER_LABEL)
        ->and($photoRoom->connected)->toBeFalse()
        ->and($photoRoom->configured)->toBeFalse();

    expect($summaries[0]->displayName)->toBe('Alibaba Model Studio');

    $keys = collect($summaries)->map(fn ($summary) => $summary->providerKey)->all();
    expect($keys)->toContain('alibaba')->toContain('claid')->toContain('poof')
        ->toContain('stability')->toContain('bedrock');

    foreach (['alibaba', 'claid', 'poof', 'stability', 'bedrock'] as $key) {
        $summary = collect($summaries)->firstWhere('providerKey', $key);
        expect($summary->connected)->toBeFalse()
            ->and($summary->configured)->toBeFalse();
    }
});

it('reports photoroom as connected once an api key is configured', function (): void {
    $companyId = configurePhotoRoom();

    $summary = collect(aiProviderFamily('image')->providers($companyId))
        ->firstWhere('providerKey', PhotoRoomConfiguration::PROVIDER);

    expect($summary->connected)->toBeTrue()
        ->and($summary->configured)->toBeTrue();
});

it('maps connected llm providers into family summaries', function (): void {
    $company = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $company->id]);

    AiProvider::query()->create([
        'company_id' => $company->id,
        'name' => 'openai',
        'family' => AiProvider::FAMILY_LLM,
        'display_name' => 'OpenAI',
        'base_url' => 'https://api.openai.com/v1',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'sk-test'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
        'created_by' => $employee->id,
    ]);

    $summaries = aiProviderFamily('llm')->providers($company->id);

    expect($summaries)->toHaveCount(1)
        ->and($summaries[0]->familyKey)->toBe('llm')
        ->and($summaries[0]->displayName)->toBe('OpenAI')
        ->and($summaries[0]->connected)->toBeTrue();
});

it('returns no llm providers without a company scope', function (): void {
    expect(aiProviderFamily('llm')->providers(null))->toBe([]);
});

it('returns no image providers without a company scope', function (): void {
    expect(aiProviderFamily('image')->providers(null))->toBe([]);
});

it('organizes the hub into LLM and Image family tabs with two cards each', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    Livewire::test(Providers::class)
        ->assertSee('LLM')
        ->assertSee('Vision')
        ->assertSee('Add LLM provider')
        ->assertSee('Vision providers')
        ->assertSee(PhotoRoomConfiguration::PROVIDER_LABEL)
        ->assertSee('Alibaba Model Studio')
        ->assertSee('Claid AI')
        ->assertSee('Poof')
        ->assertSee('Stability AI')
        ->assertSee('AWS Bedrock')
        ->assertSee('Connect')
        ->assertSee('Status')
        ->assertSee('Not connected')
        ->assertSee('Fast, e-commerce-tuned background removal & product cutouts.')
        ->assertSee('Low-cost, high-resolution background removal.')
        ->assertSee('Stable Image edit API — background removal, search-and-recolor, erase & more.')
        ->assertDontSee('Set up')
        ->assertDontSee('Manage')
        ->assertDontSee('API key stored')
        ->assertDontSee('Coming soon')
        ->assertDontSee('Predictive');
});

it('shows honest readiness status per vision provider', function (): void {
    $user = createAdminUser();
    configurePhotoRoom(companyId: $user->company_id);
    app(ImageProviderCredentialStore::class)->upsert(
        $user->company_id,
        StabilityConfiguration::PROVIDER,
        [
            'display_name' => StabilityConfiguration::PROVIDER_LABEL,
            'base_url' => StabilityConfiguration::API_BASE_URL,
            'credentials' => ['api_key' => 'sk-stability'],
            'connection_config' => [],
        ],
    );

    $this->actingAs($user);

    Livewire::test(Providers::class)
        ->assertSee('Ready')
        ->assertSee('Key stored');
});
