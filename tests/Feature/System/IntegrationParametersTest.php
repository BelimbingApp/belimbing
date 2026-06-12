<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Models\Setting;
use App\Base\System\Livewire\IntegrationParameters\Index as IntegrationParametersIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['settings.cache_ttl' => 0]);
    setupAuthzRoles();
    $this->actingAs(createAdminUser());
});

test('integration parameters page renders under system integrations', function (): void {
    $this->get(route('admin.system.integration-parameters.index'))
        ->assertOk()
        ->assertSee('Integration Parameters')
        ->assertSee('No integration parameters stored yet.');
});

test('a secret parameter is stored encrypted and displayed write-only', function (): void {
    Livewire::test(IntegrationParametersIndex::class)
        ->call('openAddModal')
        ->set('newSystem', 'cloudflare')
        ->set('newName', 'api_token')
        ->set('newType', 'secret')
        ->set('newDescription', 'Tunnel-config token, expires 2026-06-13')
        ->set('newValue', 'super-secret-value-a4f9')
        ->call('addParameter')
        ->assertHasNoErrors()
        ->assertSet('addModalOpen', false)
        ->assertSee('••••a4f9')
        ->assertDontSee('super-secret-value-a4f9');

    $row = Setting::query()
        ->whereNull('scope_type')
        ->where('key', 'integrations.cloudflare.api_token')
        ->firstOrFail();

    expect($row->is_encrypted)->toBeTrue()
        ->and(json_encode($row->value))->not->toContain('super-secret-value-a4f9')
        ->and(app(SettingsService::class)->get('integrations.cloudflare.api_token'))->toBe('super-secret-value-a4f9')
        ->and(app(SettingsService::class)->get('integrations.cloudflare.api_token.description'))->toBe('Tunnel-config token, expires 2026-06-13');
});

test('a text parameter stays readable and the entry modal prefills it', function (): void {
    Livewire::test(IntegrationParametersIndex::class)
        ->call('openAddModal')
        ->set('newSystem', 'cloudflare')
        ->set('newName', 'account_id')
        ->set('newType', 'text')
        ->set('newValue', '2af6a339c8a67f16e5af307db0b3b281')
        ->call('addParameter')
        ->assertHasNoErrors()
        // text parameters display their value — that is the point of the type
        ->assertSee('2af6a339c8a67f16e5af307db0b3b281');

    expect(Setting::query()->where('key', 'integrations.cloudflare.account_id')->firstOrFail()->is_encrypted)->toBeFalse();

    Livewire::test(IntegrationParametersIndex::class)
        ->call('openEntry', 'integrations.cloudflare.account_id')
        ->assertSet('entryModalOpen', true)
        ->assertSet('entryValue', '2af6a339c8a67f16e5af307db0b3b281')
        ->set('entryValue', 'new-account-id')
        ->call('saveEntry')
        ->assertHasNoErrors()
        ->assertSet('entryModalOpen', false);

    expect(app(SettingsService::class)->get('integrations.cloudflare.account_id'))->toBe('new-account-id');
});

test('parameter keys must be unique, well-formed, and not reserved', function (): void {
    app(SettingsService::class)->set('integrations.cloudflare.api_token', 'existing', encrypted: true);

    Livewire::test(IntegrationParametersIndex::class)
        ->set('newSystem', 'cloudflare')
        ->set('newName', 'api_token')
        ->set('newValue', 'another')
        ->call('addParameter')
        ->assertHasErrors(['newName']);

    Livewire::test(IntegrationParametersIndex::class)
        ->set('newSystem', 'Cloud Flare!')
        ->set('newName', 'api_token')
        ->set('newValue', 'x')
        ->call('addParameter')
        ->assertHasErrors(['newSystem']);

    // 'description' is reserved for the sibling description setting
    Livewire::test(IntegrationParametersIndex::class)
        ->set('newSystem', 'cloudflare')
        ->set('newName', 'description')
        ->set('newValue', 'x')
        ->call('addParameter')
        ->assertHasErrors(['newName']);
});

test('a secret edit starts blank, blank keeps the stored value, and a new value stays encrypted', function (): void {
    app(SettingsService::class)->set('integrations.cloudflare.api_token', 'old-secret', encrypted: true);

    // blank value = keep current secret (only the description changed)
    Livewire::test(IntegrationParametersIndex::class)
        ->call('openEntry', 'integrations.cloudflare.api_token')
        ->assertSet('entryValue', '')
        ->set('entryDescription', 'rotated quarterly')
        ->call('saveEntry')
        ->assertHasNoErrors();

    expect(app(SettingsService::class)->get('integrations.cloudflare.api_token'))->toBe('old-secret')
        ->and(app(SettingsService::class)->get('integrations.cloudflare.api_token.description'))->toBe('rotated quarterly');

    // the secret-input saved-value sentinel also means "keep"
    Livewire::test(IntegrationParametersIndex::class)
        ->call('openEntry', 'integrations.cloudflare.api_token')
        ->set('entryValue', '******')
        ->call('saveEntry')
        ->assertHasNoErrors();

    expect(app(SettingsService::class)->get('integrations.cloudflare.api_token'))->toBe('old-secret');

    // a new value replaces it and stays encrypted
    Livewire::test(IntegrationParametersIndex::class)
        ->call('openEntry', 'integrations.cloudflare.api_token')
        ->set('entryValue', 'new-secret-77zz')
        ->call('saveEntry')
        ->assertHasNoErrors();

    expect(app(SettingsService::class)->get('integrations.cloudflare.api_token'))->toBe('new-secret-77zz')
        ->and(Setting::query()->where('key', 'integrations.cloudflare.api_token')->firstOrFail()->is_encrypted)->toBeTrue();
});

test('deleting a parameter from the entry modal removes the value and its description', function (): void {
    $settings = app(SettingsService::class);
    $settings->set('integrations.cloudflare.api_token', 'doomed', encrypted: true);
    $settings->set('integrations.cloudflare.api_token.description', 'temp');

    Livewire::test(IntegrationParametersIndex::class)
        ->call('openEntry', 'integrations.cloudflare.api_token')
        ->call('deleteParameter')
        ->assertHasNoErrors()
        ->assertSet('entryModalOpen', false);

    expect(Setting::query()->where('key', 'like', 'integrations.cloudflare.api_token%')->count())->toBe(0);
});

test('the list searches by key and sorts by key or recency', function (): void {
    $settings = app(SettingsService::class);
    $settings->set('integrations.cloudflare.api_token', 'a', encrypted: true);
    $settings->set('integrations.wechat.ingest_app_secret', 'b', encrypted: true);

    Livewire::test(IntegrationParametersIndex::class)
        ->set('search', 'wechat')
        ->assertSee('integrations.wechat.ingest_app_secret')
        ->assertDontSee('integrations.cloudflare.api_token');

    Livewire::test(IntegrationParametersIndex::class)
        ->assertSet('sortBy', 'key')
        ->assertSet('sortDir', 'asc')
        ->call('sort', 'updated_at')
        ->assertSet('sortBy', 'updated_at')
        ->assertSet('sortDir', 'desc');
});
