<?php

use App\Base\Media\PhotoCleanup\Contracts\ImageProviderCredentialStore;
use App\Base\Media\PhotoCleanup\PhotoCleanupSelection;
use App\Base\Media\PhotoCleanup\PhotoRoomConfiguration;
use App\Modules\Core\AI\Livewire\Providers\Providers;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

const PHOTOROOM_ACCOUNT_ENDPOINT_LW = 'https://image-api.photoroom.com/*';

it('shows a Test connection action for the connected photoroom provider', function (): void {
    $user = createAdminUser();
    configurePhotoRoom(companyId: $user->company_id);

    $this->actingAs($user);

    Livewire::test(Providers::class)
        ->assertSee('Test connection');
});

it('does not show Test connection for configured providers without a bound handshake client', function (): void {
    $user = createAdminUser();
    // Claid is configured (key stored) but not connected (no cleanup client) and
    // has no documented cheap handshake — no Test connection affordance.
    app(ImageProviderCredentialStore::class)->upsert(
        $user->company_id,
        'claid',
        [
            'display_name' => 'Claid AI',
            'base_url' => 'https://api.claid.ai/v1',
            'credentials' => ['api_key' => 'claid-key'],
            'connection_config' => [],
        ],
    );

    $this->actingAs($user);

    Livewire::test(Providers::class)
        ->assertDontSee('Test connection');
});

it('runs the photoroom handshake and dispatches a success notification with the plan', function (): void {
    $user = createAdminUser();
    configurePhotoRoom(companyId: $user->company_id);

    Http::fake([
        PHOTOROOM_ACCOUNT_ENDPOINT_LW => Http::response([
            'images' => ['available' => 83, 'subscription' => 100],
            'plan' => 'basic',
        ], 200),
    ]);

    $this->actingAs($user);

    $message = __('Connected').' · '.__('Plan: :plan; :available of :subscription credits available.', [
        'plan' => 'basic',
        'available' => 83,
        'subscription' => 100,
    ]);

    Livewire::test(Providers::class)
        ->call('testImageConnection', PhotoRoomConfiguration::PROVIDER)
        ->assertHasNoErrors()
        ->assertDispatched('notify', variant: 'success', message: $message);
});

it('dispatches an unauthorized error notification when the key is rejected', function (): void {
    $user = createAdminUser();
    configurePhotoRoom(companyId: $user->company_id);

    Http::fake([
        PHOTOROOM_ACCOUNT_ENDPOINT_LW => Http::response('', 401),
    ]);

    $this->actingAs($user);

    $message = __('Unauthorized').' · '.__('The stored API key was rejected.');

    Livewire::test(Providers::class)
        ->call('testImageConnection', PhotoRoomConfiguration::PROVIDER)
        ->assertDispatched('notify', variant: 'error', message: $message);
});

it('dispatches a request failed error notification on a non-401 error', function (): void {
    $user = createAdminUser();
    configurePhotoRoom(companyId: $user->company_id);

    Http::fake([
        PHOTOROOM_ACCOUNT_ENDPOINT_LW => Http::response('', 503),
    ]);

    $this->actingAs($user);

    $message = __('Request failed').' · '.__('The provider returned HTTP :status.', ['status' => 503]);

    Livewire::test(Providers::class)
        ->call('testImageConnection', PhotoRoomConfiguration::PROVIDER)
        ->assertDispatched('notify', variant: 'error', message: $message);
});

it('marks the default active provider and shows Set active for another ready provider', function (): void {
    $user = createAdminUser();
    configurePhotoRoom(companyId: $user->company_id);
    configureImageProviderKey('poof', $user->company_id);

    $this->actingAs($user);

    Livewire::test(Providers::class)
        // PhotoRoom is the default active choice → Active badge, no Set active for it.
        ->assertSee(__('Active'))
        ->assertSee(__('Ready'))
        // Poof is Ready but not active → its Set active affordance appears.
        ->assertSee(__('Set active'));
});

it('promotes a provider to Active when the operator chooses it', function (): void {
    $user = createAdminUser();
    configurePhotoRoom(companyId: $user->company_id);
    configureImageProviderKey('poof', $user->company_id);

    $this->actingAs($user);

    Livewire::test(Providers::class)
        ->call('setActiveImageProvider', 'poof')
        ->assertHasNoErrors()
        ->assertDispatched('notify', variant: 'success', message: __('Photo cleanup now uses this provider.'));

    // The persisted choice now resolves to poof.
    expect(app(PhotoCleanupSelection::class)->activeProviderKey($user->company_id))->toBe('poof');
});

it('refuses to activate a provider that is not ready (no key stored)', function (): void {
    $user = createAdminUser();
    configurePhotoRoom(companyId: $user->company_id);

    $this->actingAs($user);

    Livewire::test(Providers::class)
        ->call('setActiveImageProvider', 'poof')
        ->assertDispatched('notify', variant: 'error', message: __('That provider is not ready. Add a key first.'));

    // The default active provider is unchanged.
    expect(app(PhotoCleanupSelection::class)->activeProviderKey($user->company_id))->toBe('photoroom');
});
