<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Inventory\Livewire\Settings as InventorySettings;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Livewire\Ebay\Settings as EbaySettings;
use Livewire\Livewire;

test('eBay settings page renders its setup fields and persists values', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $this->get(route('commerce.marketplace.ebay.settings'))
        ->assertOk()
        ->assertSee('eBay marketplace')
        ->assertSee('Client ID')
        ->assertSee(route('commerce.marketplace.ebay.oauth.callback'))
        ->assertSee('Sell Inventory')
        ->assertSee('Commerce Taxonomy')
        ->assertDontSee('Commerce defaults')
        ->assertDontSee('Ham auto parts');

    $scopes = [
        'https://api.ebay.com/oauth/api_scope/sell.inventory',
        'https://api.ebay.com/oauth/api_scope/sell.fulfillment',
    ];

    Livewire::test(EbaySettings::class)
        ->set('values.marketplace__ebay__environment', 'live')
        ->set('values.marketplace__ebay__marketplace_id', 'ebay_us')
        ->set('values.marketplace__ebay__client_id', 'client-123')
        ->set('values.marketplace__ebay__client_secret', 'secret-456')
        ->set('values.marketplace__ebay__scopes', $scopes)
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(SettingsService::class);
    $scope = Scope::company($user->company_id);

    expect($settings->get('marketplace.ebay.environment', scope: $scope))->toBe('live')
        ->and($settings->get('marketplace.ebay.marketplace_id', scope: $scope))->toBe('EBAY_US')
        ->and($settings->get('marketplace.ebay.client_id', scope: $scope))->toBe('client-123')
        ->and($settings->get('marketplace.ebay.client_secret', scope: $scope))->toBe('secret-456')
        ->and($settings->get('marketplace.ebay.scopes', scope: $scope))->toBe($scopes)
        ->and(app(EbayConfiguration::class)->forCompany($user->company_id)['redirect_uri'])->toBe(route('commerce.marketplace.ebay.oauth.callback'));
});

test('eBay settings normalizes legacy whitespace scopes into checkbox values', function (): void {
    $user = createAdminUser();
    $scope = Scope::company($user->company_id);

    app(SettingsService::class)->set(
        'marketplace.ebay.scopes',
        "https://api.ebay.com/oauth/api_scope/sell.inventory\nhttps://api.ebay.com/oauth/api_scope/sell.fulfillment",
        $scope,
    );

    $this->actingAs($user);

    Livewire::test(EbaySettings::class)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(SettingsService::class)->get('marketplace.ebay.scopes', scope: $scope))->toBe([
        'https://api.ebay.com/oauth/api_scope/sell.inventory',
        'https://api.ebay.com/oauth/api_scope/sell.fulfillment',
    ]);
});

test('eBay client secret field shows a masked current value preview', function (): void {
    $user = createAdminUser();
    $scope = Scope::company($user->company_id);

    app(SettingsService::class)->set(
        'marketplace.ebay.client_secret',
        'client-secret-1234567890',
        $scope,
        encrypted: true,
    );

    $this->actingAs($user);

    Livewire::test(EbaySettings::class)
        ->assertSee('Current value:')
        ->assertSee('client-*************7890');
});

test('inventory settings page renders only its own group and persists the default currency', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $this->get(route('commerce.inventory.settings'))
        ->assertOk()
        ->assertSee('Commerce defaults')
        ->assertSee('Default currency')
        ->assertDontSee('eBay marketplace')
        ->assertDontSee('Ham auto parts');

    Livewire::test(InventorySettings::class)
        ->set('values.commerce__default_currency_code', 'usd')
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(SettingsService::class);
    $scope = Scope::company($user->company_id);

    expect($settings->get('commerce.default_currency_code', scope: $scope))->toBe('USD');
});
