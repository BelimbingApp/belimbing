<?php

use App\Base\Integration\Services\OAuthTokenStore;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Inventory\Livewire\Settings as InventorySettings;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayConnectionTester;
use App\Modules\Commerce\Marketplace\Ebay\EbayOAuthService;
use App\Modules\Commerce\Marketplace\Livewire\Ebay\Settings as EbaySettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('eBay settings page renders its setup fields and persists values', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $this->get(route('commerce.marketplace.ebay.settings'))
        ->assertOk()
        ->assertSee('eBay marketplace')
        ->assertSee('Client ID')
        ->assertSee('Redirect URL name')
        ->assertSee('Callback URL')
        ->assertSee('How to configure eBay OAuth')
        ->assertSee('Auth Accepted URL')
        ->assertSee('Auth Declined URL')
        ->assertSee('Belimbing owns the callback')
        ->assertSee('eBay Developer Console')
        ->assertSee('https://developer.ebay.com/my/keys')
        ->assertSee('Display Title')
        ->assertSee('Privacy Policy URL')
        ->assertSee('https://github.com/belimbingapp/belimbing/blob/main/PRIVACY.md')
        ->assertSee('Select OAuth, not Auth’n’Auth')
        ->assertSee('Connect eBay')
        ->assertSee('Test connection')
        ->assertSee(route('commerce.marketplace.ebay.oauth.callback'))
        ->assertSee('Advanced OAuth settings')
        ->assertSee('Sell Inventory')
        ->assertSee('User Tokens')
        ->assertSee('Taxonomy/category metadata uses a separate application-token flow later')
        ->assertDontSee('Commerce defaults')
        ->assertDontSee('Ham auto parts');

    $scopes = [
        'https://api.ebay.com/oauth/api_scope/sell.inventory',
        'https://api.ebay.com/oauth/api_scope/sell.fulfillment',
    ];

    Livewire::test(EbaySettings::class)
        ->assertSet('values.marketplace__ebay__scopes', [
            'https://api.ebay.com/oauth/api_scope/sell.inventory',
            'https://api.ebay.com/oauth/api_scope/sell.account',
            'https://api.ebay.com/oauth/api_scope/sell.fulfillment',
        ]);

    Livewire::test(EbaySettings::class)
        ->set('values.marketplace__ebay__environment', 'live')
        ->set('values.marketplace__ebay__marketplace_id', 'ebay_us')
        ->set('values.marketplace__ebay__client_id', 'client-123')
        ->set('values.marketplace__ebay__client_secret', 'secret-456')
        ->set('values.marketplace__ebay__ru_name', 'KiatNg-Belimbin-SBX-runame')
        ->set('values.marketplace__ebay__scopes', $scopes)
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(SettingsService::class);
    $scope = Scope::company($user->company_id);

    expect($settings->get('marketplace.ebay.environment', scope: $scope))->toBe('live')
        ->and($settings->get('marketplace.ebay.marketplace_id', scope: $scope))->toBe('EBAY_US')
        ->and($settings->get('marketplace.ebay.client_id', scope: $scope))->toBe('client-123')
        ->and($settings->get('marketplace.ebay.client_secret', scope: $scope))->toBe('secret-456')
        ->and($settings->get('marketplace.ebay.ru_name', scope: $scope))->toBe('KiatNg-Belimbin-SBX-runame')
        ->and($settings->get('marketplace.ebay.scopes', scope: $scope))->toBe($scopes)
        ->and(app(EbayConfiguration::class)->forCompany($user->company_id)['redirect_uri'])->toBe('KiatNg-Belimbin-SBX-runame')
        ->and(app(EbayConfiguration::class)->forCompany($user->company_id)['callback_url'])->toBe(route('commerce.marketplace.ebay.oauth.callback'));
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

test('eBay OAuth authorize URL uses the eBay RuName instead of the callback URL', function (): void {
    $user = createAdminUser();
    $scope = Scope::company($user->company_id);
    $settings = app(SettingsService::class);

    $settings->set('marketplace.ebay.environment', 'sandbox', $scope);
    $settings->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);
    $settings->set('marketplace.ebay.client_id', 'client-oauth-url', $scope);
    $settings->set('marketplace.ebay.client_secret', 'secret-oauth-url', $scope, encrypted: true);
    $settings->set('marketplace.ebay.ru_name', 'KiatNg-Belimbin-SBX-runame', $scope);
    $settings->set('marketplace.ebay.scopes', ['https://api.ebay.com/oauth/api_scope/sell.account'], $scope);

    $this->actingAs($user);

    $url = app(EbayOAuthService::class)->authorizationUrl($user->company_id);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://auth.sandbox.ebay.com/oauth2/authorize?')
        ->and($query['redirect_uri'] ?? null)->toBe('KiatNg-Belimbin-SBX-runame')
        ->and($query['redirect_uri'] ?? null)->not()->toBe(route('commerce.marketplace.ebay.oauth.callback'));
});

test('eBay settings connection test verifies the saved OAuth grant against a safe Inventory API call', function (): void {
    $user = createAdminUser();
    $scope = Scope::company($user->company_id);
    $settings = app(SettingsService::class);

    $settings->set('marketplace.ebay.environment', 'sandbox', $scope);
    $settings->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);
    $settings->set('marketplace.ebay.client_id', 'client-connection-test', $scope);
    $settings->set('marketplace.ebay.client_secret', 'secret-connection-test', $scope, encrypted: true);
    $settings->set('marketplace.ebay.ru_name', 'KiatNg-Belimbin-SBX-runame', $scope);
    $settings->set('marketplace.ebay.scopes', [
        'https://api.ebay.com/oauth/api_scope/sell.account',
        'https://api.ebay.com/oauth/api_scope/sell.inventory',
    ], $scope);

    app(OAuthTokenStore::class)->persist(
        EbayConfiguration::CHANNEL,
        $scope,
        [
            'access_token' => 'access-token-connection-test',
            'refresh_token' => 'refresh-token-connection-test',
            'expires_in' => 3600,
        ],
        [
            'https://api.ebay.com/oauth/api_scope/sell.account',
            'https://api.ebay.com/oauth/api_scope/sell.inventory',
        ],
    );

    Http::fake([
        'https://api.sandbox.ebay.com/sell/inventory/v1/inventory_item*' => Http::response([
            'inventoryItems' => [
                ['sku' => 'test-sku'],
            ],
        ]),
    ]);

    $this->actingAs($user);

    Livewire::test(EbaySettings::class)
        ->call('testConnection')
        ->assertSet('connectionTest.status', EbayConnectionTester::STATUS_HEALTHY)
        ->assertSee('Belimbing reached eBay successfully');

    expect($settings->get('marketplace.ebay.connection_test_status', scope: $scope))->toBe(EbayConnectionTester::STATUS_HEALTHY)
        ->and($settings->get('marketplace.ebay.connection_test_message', scope: $scope))->toBe('Belimbing reached eBay successfully. OAuth, selected environment, recommended seller scopes, and the read-only Inventory API are working.');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.sandbox.ebay.com/sell/inventory/v1/inventory_item')
        && $request->hasHeader('Authorization', 'Bearer access-token-connection-test'));
});

test('eBay settings connection test explains when OAuth has not been connected yet', function (): void {
    $user = createAdminUser();
    $scope = Scope::company($user->company_id);
    $settings = app(SettingsService::class);

    $settings->set('marketplace.ebay.environment', 'sandbox', $scope);
    $settings->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);
    $settings->set('marketplace.ebay.client_id', 'client-without-oauth', $scope);
    $settings->set('marketplace.ebay.client_secret', 'secret-without-oauth', $scope, encrypted: true);
    $settings->set('marketplace.ebay.ru_name', 'KiatNg-Belimbin-SBX-runame', $scope);
    $settings->set('marketplace.ebay.scopes', [
        'https://api.ebay.com/oauth/api_scope/sell.account',
        'https://api.ebay.com/oauth/api_scope/sell.inventory',
    ], $scope);

    Http::fake();

    $this->actingAs($user);

    Livewire::test(EbaySettings::class)
        ->call('testConnection')
        ->assertSet('connectionTest.status', EbayConnectionTester::STATUS_FAILED)
        ->assertSee('OAuth is not connected yet');

    expect($settings->get('marketplace.ebay.connection_test_status', scope: $scope))->toBe(EbayConnectionTester::STATUS_FAILED)
        ->and($settings->get('marketplace.ebay.connection_test_message', scope: $scope))->toBe('OAuth is not connected yet. Use Connect eBay on this page, approve the requested scopes, then test again.');

    Http::assertNothingSent();
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
