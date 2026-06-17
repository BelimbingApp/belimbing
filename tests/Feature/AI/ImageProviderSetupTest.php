<?php

use App\Base\Media\PhotoCleanup\PhotoRoomConfiguration;
use App\Modules\Core\AI\Livewire\Providers\ImageProviderSetup;
use App\Modules\Core\AI\Models\AiProvider;
use Livewire\Livewire;

function savedImageProvider(
    string $providerKey,
    string $apiKey,
    ?string $region = null,
    ?string $providerName = null,
): AiProvider {
    $user = createAdminUser();

    $component = Livewire::actingAs($user)
        ->test(ImageProviderSetup::class)
        ->call('open', $providerKey)
        ->assertSet('show', true)
        ->set('values.apiKey', $apiKey);

    if ($region !== null) {
        $component->set('values.region', $region);
    }

    $component
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('configured.apiKey', true)
        ->assertSet('show', false);

    $provider = AiProvider::query()
        ->forCompany($user->company_id)
        ->image()
        ->where('name', $providerName ?? $providerKey)
        ->first();

    expect($provider)->toBeInstanceOf(AiProvider::class);

    return $provider;
}

it('opens for photoroom and saves the api key', function (): void {
    $provider = savedImageProvider('photoroom', 'live-key-xyz', providerName: PhotoRoomConfiguration::PROVIDER);

    expect($provider->credentials['api_key'])->toBe('live-key-xyz');
});

it('opens for alibaba and saves the DashScope key and region', function (): void {
    $provider = savedImageProvider('alibaba', 'sk-dashscope-test', 'international');

    expect($provider->credentials['api_key'])->toBe('sk-dashscope-test')
        ->and($provider->connection_config['region'])->toBe('international');
});

it('opens for poof and saves the api key', function (): void {
    expect(savedImageProvider('poof', 'poof-key-123')->credentials['api_key'])->toBe('poof-key-123');
});

it('opens for stability and saves the api key', function (): void {
    expect(savedImageProvider('stability', 'sk-stability-test')->credentials['api_key'])->toBe('sk-stability-test');
});

it('opens for bedrock and saves the api key and region', function (): void {
    $provider = savedImageProvider('bedrock', 'bedrock-token-xyz', 'us-west-2');

    expect($provider->credentials['api_key'])->toBe('bedrock-token-xyz')
        ->and($provider->connection_config['region'])->toBe('us-west-2');
});

it('leaves a stored secret untouched when the masked field is not edited', function (): void {
    $user = createAdminUser();
    configurePhotoRoom('original-sandbox-key', $user->company_id);

    Livewire::actingAs($user)
        ->test(ImageProviderSetup::class)
        ->call('open', 'photoroom')
        ->assertSet('configured.apiKey', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(
        AiProvider::query()->forCompany($user->company_id)->image()->where('name', 'photoroom')->first()?->credentials['api_key']
    )->toBe('original-sandbox-key');
});

it('forgets stored credentials when a provider is removed', function (): void {
    $user = createAdminUser();
    configurePhotoRoom('to-be-removed', $user->company_id);

    Livewire::actingAs($user)
        ->test(ImageProviderSetup::class)
        ->call('confirmRemove', 'photoroom')
        ->assertSet('showRemoveConfirm', true)
        ->assertSet('displayName', PhotoRoomConfiguration::PROVIDER_LABEL)
        ->call('remove')
        ->assertSet('showRemoveConfirm', false)
        ->assertDispatched('image-providers-updated');

    expect(
        AiProvider::query()->forCompany($user->company_id)->image()->where('name', 'photoroom')->exists()
    )->toBeFalse();
});

it('ignores an unknown provider key', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(ImageProviderSetup::class)
        ->call('open', 'not-a-provider')
        ->assertSet('show', false);
});

it('shows the api endpoint and a get-key link in the setup modal', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(ImageProviderSetup::class)
        ->call('open', 'poof')
        ->assertSee('api.poof.bg')
        ->assertSee('Poof API key')
        ->assertSee('https://poof.bg', false);
});

it('shows a region-aware endpoint for alibaba', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(ImageProviderSetup::class)
        ->call('open', 'alibaba')
        ->set('values.region', 'china')
        ->assertSee('dashscope.aliyuncs.com')
        ->set('values.region', 'international')
        ->assertSee('dashscope-intl.aliyuncs.com');
});

it('renders endpoint controls in the endpoint panel before credential fields', function (string $providerKey, array $orderedLabels): void {
    $this->actingAs(createAdminUser());

    Livewire::test(ImageProviderSetup::class)
        ->call('open', $providerKey)
        ->assertSeeInOrder($orderedLabels);
})->with([
    'alibaba' => ['alibaba', ['Endpoint region', 'API endpoint', 'DashScope API key']],
    'bedrock' => ['bedrock', ['AWS region', 'API endpoint', 'Bedrock API key']],
]);

it('shows a region-aware bedrock runtime endpoint', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(ImageProviderSetup::class)
        ->call('open', 'bedrock')
        ->assertSee('bedrock-runtime.us-east-1.amazonaws.com')
        ->set('values.region', 'us-west-2')
        ->assertSee('bedrock-runtime.us-west-2.amazonaws.com');
});

it('shows the stability endpoint and a get-key link in the setup modal', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(ImageProviderSetup::class)
        ->call('open', 'stability')
        ->assertSee('api.stability.ai')
        ->assertSee('Stability AI API key')
        ->assertSee('https://platform.stability.ai/account/keys', false);
});

it('does not expose a test connection action in the setup modal', function (): void {
    $user = createAdminUser();
    configurePhotoRoom(companyId: $user->company_id);

    Livewire::actingAs($user)
        ->test(ImageProviderSetup::class)
        ->call('open', 'photoroom')
        ->assertDontSee('Test connection');
});
