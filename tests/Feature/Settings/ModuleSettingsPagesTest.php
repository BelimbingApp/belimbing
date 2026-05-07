<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Inventory\Livewire\Settings as InventorySettings;
use App\Modules\Commerce\Marketplace\Livewire\Ebay\Settings as EbaySettings;
use Livewire\Livewire;

test('eBay settings page renders only its own group and persists plain and encrypted values', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $this->get(route('commerce.marketplace.ebay.settings'))
        ->assertOk()
        ->assertSee('eBay marketplace')
        ->assertSee('Client ID')
        ->assertDontSee('Commerce defaults')
        ->assertDontSee('Ham auto parts');

    Livewire::test(EbaySettings::class)
        ->set('values.marketplace__ebay__environment', 'live')
        ->set('values.marketplace__ebay__marketplace_id', 'ebay_us')
        ->set('values.marketplace__ebay__client_id', 'client-123')
        ->set('values.marketplace__ebay__client_secret', 'secret-456')
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(SettingsService::class);
    $scope = Scope::company($user->company_id);

    expect($settings->get('marketplace.ebay.environment', scope: $scope))->toBe('live')
        ->and($settings->get('marketplace.ebay.marketplace_id', scope: $scope))->toBe('EBAY_US')
        ->and($settings->get('marketplace.ebay.client_id', scope: $scope))->toBe('client-123')
        ->and($settings->get('marketplace.ebay.client_secret', scope: $scope))->toBe('secret-456');
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
