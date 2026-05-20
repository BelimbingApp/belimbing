<?php

use App\Base\Integration\Services\OAuthTokenStore;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayConnectionTester;
use App\Modules\Commerce\Marketplace\Ebay\EbayOAuthService;
use App\Modules\Commerce\Marketplace\Livewire\Ebay\Settings as EbaySettings;
use App\Modules\Commerce\Marketplace\Models\AccountResource;
use App\Modules\Commerce\Settings\Livewire\Settings as CommerceSettings;
use App\Modules\Core\Geonames\Models\Country;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

const EBAY_SCOPE_INVENTORY = 'https://api.ebay.com/oauth/api_scope/sell.inventory';
const EBAY_SCOPE_FULFILLMENT = 'https://api.ebay.com/oauth/api_scope/sell.fulfillment';
const EBAY_SCOPE_ACCOUNT = 'https://api.ebay.com/oauth/api_scope/sell.account';

test('eBay settings page renders its setup fields and persists values', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $this->get(route('commerce.marketplace.ebay.settings'))
        ->assertOk()
        ->assertSee('eBay Settings')
        ->assertSee('Client ID')
        ->assertSee('Redirect URL name')
        ->assertSee('Callback URL')
        ->assertSee('United States (EBAY_US)')
        ->assertSee('Malaysia (EBAY_MY)')
        ->assertSee('The eBay site where this company sells')
        ->assertSee('How to configure eBay OAuth')
        ->assertSee('Auth Accepted URL')
        ->assertSee('Auth Declined URL')
        ->assertSee('Belimbing owns the callback')
        ->assertSee('eBay Developer Console')
        ->assertSee('https://developer.ebay.com/my/keys')
        ->assertSee('<code>App ID</code>', false)
        ->assertSee('<code>Cert ID</code>', false)
        ->assertSee('<code>Cert ID (Client secret)</code>', false)
        ->assertSee('Use the <code>Sandbox</code> keyset when Environment is <code>Sandbox</code>', false)
        ->assertSee('Display Title')
        ->assertSee('Privacy Policy URL')
        ->assertSee('https://github.com/belimbingapp/belimbing/blob/main/PRIVACY.md')
        ->assertSee('Select <code>OAuth</code>, not <code>Auth’n’Auth</code>', false)
        ->assertSee('Connect eBay')
        ->assertSee('Test connection')
        ->assertSee('Seller setup choices')
        ->assertSee('Refresh from eBay')
        ->assertSee('No eBay setup choices have been imported yet')
        ->assertSee(route('commerce.marketplace.ebay.oauth.callback'))
        ->assertSee('Copy')
        ->assertSee('navigator.clipboard.writeText', false)
        ->assertDontSee('<input id="setting-marketplace-ebay-callback-url"', false)
        ->assertSee('Advanced OAuth settings')
        ->assertSee('Sell Inventory')
        ->assertSee('User Tokens')
        ->assertSee('Seller permissions shown on eBay consent')
        ->assertDontSee('Commerce Settings')
        ->assertDontSee('Ham auto parts');

    $scopes = [
        EBAY_SCOPE_INVENTORY,
        EBAY_SCOPE_FULFILLMENT,
    ];

    Livewire::test(EbaySettings::class)
        ->assertSet('values.marketplace__ebay__scopes', [
            EBAY_SCOPE_INVENTORY,
            EBAY_SCOPE_ACCOUNT,
            EBAY_SCOPE_FULFILLMENT,
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

test('eBay settings imports seller setup choices and stores selected defaults', function (): void {
    $user = createAdminUser();
    $scope = Scope::company($user->company_id);
    $settings = app(SettingsService::class);

    $settings->set('marketplace.ebay.environment', 'sandbox', $scope);
    $settings->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);
    $settings->set('marketplace.ebay.client_id', 'client-setup-import', $scope);
    $settings->set('marketplace.ebay.client_secret', 'secret-setup-import', $scope, encrypted: true);
    $settings->set('marketplace.ebay.ru_name', 'KiatNg-Belimbin-SBX-runame', $scope);
    $settings->set('marketplace.ebay.scopes', [
        EBAY_SCOPE_ACCOUNT,
        EBAY_SCOPE_INVENTORY,
    ], $scope);

    app(OAuthTokenStore::class)->persist(
        EbayConfiguration::CHANNEL,
        $scope,
        [
            'access_token' => 'access-token-setup-import',
            'refresh_token' => 'refresh-token-setup-import',
            'expires_in' => 3600,
        ],
        [
            EBAY_SCOPE_ACCOUNT,
            EBAY_SCOPE_INVENTORY,
        ],
    );

    Http::fake([
        'https://api.sandbox.ebay.com/sell/account/v1/payment_policy*' => Http::response([
            'paymentPolicies' => [
                ['paymentPolicyId' => 'PAY-1', 'name' => 'Standard payment', 'marketplaceId' => 'EBAY_US'],
            ],
        ]),
        'https://api.sandbox.ebay.com/sell/account/v1/fulfillment_policy*' => Http::response([
            'fulfillmentPolicies' => [
                ['fulfillmentPolicyId' => 'FUL-1', 'name' => 'Ground shipping', 'marketplaceId' => 'EBAY_US'],
            ],
        ]),
        'https://api.sandbox.ebay.com/sell/account/v1/return_policy*' => Http::response([
            'returnPolicies' => [
                ['returnPolicyId' => 'RET-1', 'name' => '30-day returns', 'marketplaceId' => 'EBAY_US'],
            ],
        ]),
        'https://api.sandbox.ebay.com/sell/inventory/v1/location*' => Http::response([
            'locations' => [
                [
                    'merchantLocationKey' => 'california_shop',
                    'name' => 'California shop',
                    'merchantLocationStatus' => 'ENABLED',
                ],
            ],
        ]),
    ]);

    $this->actingAs($user);

    Livewire::test(EbaySettings::class)
        ->call('importAccountSetup')
        ->assertSee('Standard payment')
        ->assertSee('California shop')
        ->set('defaultPaymentPolicyId', 'PAY-1')
        ->set('defaultFulfillmentPolicyId', 'FUL-1')
        ->set('defaultReturnPolicyId', 'RET-1')
        ->set('defaultMerchantLocationKey', 'california_shop')
        ->call('saveAccountSetupDefaults')
        ->assertHasNoErrors();

    expect(AccountResource::query()->where('company_id', $user->company_id)->count())->toBe(4)
        ->and($settings->get('marketplace.ebay.default_payment_policy_id', scope: $scope))->toBe('PAY-1')
        ->and($settings->get('marketplace.ebay.default_fulfillment_policy_id', scope: $scope))->toBe('FUL-1')
        ->and($settings->get('marketplace.ebay.default_return_policy_id', scope: $scope))->toBe('RET-1')
        ->and($settings->get('marketplace.ebay.default_merchant_location_key', scope: $scope))->toBe('california_shop');
});

test('eBay settings normalizes legacy whitespace scopes into checkbox values', function (): void {
    $user = createAdminUser();
    $scope = Scope::company($user->company_id);

    app(SettingsService::class)->set(
        'marketplace.ebay.scopes',
        EBAY_SCOPE_INVENTORY."\n".EBAY_SCOPE_FULFILLMENT,
        $scope,
    );

    $this->actingAs($user);

    Livewire::test(EbaySettings::class)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(SettingsService::class)->get('marketplace.ebay.scopes', scope: $scope))->toBe([
        EBAY_SCOPE_INVENTORY,
        EBAY_SCOPE_FULFILLMENT,
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
    $settings->set('marketplace.ebay.scopes', [EBAY_SCOPE_ACCOUNT], $scope);

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
        EBAY_SCOPE_ACCOUNT,
        EBAY_SCOPE_INVENTORY,
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
            EBAY_SCOPE_ACCOUNT,
            EBAY_SCOPE_INVENTORY,
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
        EBAY_SCOPE_ACCOUNT,
        EBAY_SCOPE_INVENTORY,
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

test('commerce settings page renders only its own group and persists the default currency', function (): void {
    $user = createAdminUser();
    Country::query()->create([
        'iso' => 'US',
        'iso3' => 'USA',
        'iso_numeric' => '840',
        'country' => 'United States',
        'population' => 0,
        'continent' => 'NA',
        'currency_code' => 'USD',
        'currency_name' => 'US Dollar',
    ]);

    $this->actingAs($user);

    $this->get(route('commerce.settings'))
        ->assertOk()
        ->assertSee('Commerce Settings')
        ->assertSee('Default currency')
        ->assertSee('US Dollar (USD)')
        ->assertSee('Options come from Geonames country data')
        ->assertDontSee('OAuth app credentials')
        ->assertDontSee('Ham auto parts');

    Livewire::test(CommerceSettings::class)
        ->set('values.commerce__default_currency_code', 'usd')
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(SettingsService::class);
    $scope = Scope::company($user->company_id);

    expect($settings->get('commerce.default_currency_code', scope: $scope))->toBe('USD');
});
