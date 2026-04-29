<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Settings\Livewire\Admin\Index;
use App\Modules\Commerce\Marketplace\Livewire\Ebay\Settings as EbaySettings;
use Livewire\Livewire;

test('admin settings page saves company scoped plain and encrypted values', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $this->get(route('admin.settings.index'))
        ->assertOk()
        ->assertSee('Settings')
        ->assertSee('eBay marketplace');

    Livewire::test(Index::class)
        ->set('values.commerce__default_currency_code', 'usd')
        ->set('values.marketplace__ebay__environment', 'sandbox')
        ->set('values.marketplace__ebay__client_id', 'client-123')
        ->set('values.marketplace__ebay__client_secret', 'secret-456')
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(SettingsService::class);
    $scope = Scope::company($user->company_id);

    expect($settings->get('commerce.default_currency_code', scope: $scope))->toBe('USD')
        ->and($settings->get('marketplace.ebay.client_id', scope: $scope))->toBe('client-123')
        ->and($settings->get('marketplace.ebay.client_secret', scope: $scope))->toBe('secret-456');
});

test('module settings pages render only their owning settings group', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $this->get(route('commerce.marketplace.ebay.settings'))
        ->assertOk()
        ->assertSee('eBay marketplace')
        ->assertSee('Client ID')
        ->assertDontSee('AI cost controls');

    Livewire::test(EbaySettings::class)
        ->assertSet('group', 'marketplace_ebay')
        ->set('values.marketplace__ebay__environment', 'live')
        ->set('values.marketplace__ebay__marketplace_id', 'ebay_us')
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(SettingsService::class);
    $scope = Scope::company($user->company_id);

    expect($settings->get('marketplace.ebay.environment', scope: $scope))->toBe('live')
        ->and($settings->get('marketplace.ebay.marketplace_id', scope: $scope))->toBe('EBAY_US');
});
