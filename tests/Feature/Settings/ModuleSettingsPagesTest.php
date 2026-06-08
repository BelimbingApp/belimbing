<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Integration\Services\OAuthTokenStore;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayDiagnosticsService;
use App\Modules\Commerce\Marketplace\Ebay\EbayOAuthService;
use App\Modules\Commerce\Marketplace\Livewire\Ebay\Settings as EbaySettings;
use App\Modules\Commerce\Marketplace\Models\AccountResource;
use App\Modules\Commerce\Settings\Livewire\Settings as CommerceSettings;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Geonames\Models\Admin1;
use App\Modules\Core\Geonames\Models\Country;
use App\Modules\Core\User\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
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
        ->assertSee('Run diagnostics')
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

test('eBay settings opts in, creates a merchant location, and creates starter policies', function (): void {
    $user = createAdminUser();
    $scope = Scope::company($user->company_id);
    $settings = app(SettingsService::class);

    $settings->set('marketplace.ebay.environment', 'sandbox', $scope);
    $settings->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);
    $settings->set('marketplace.ebay.client_id', 'client-acct-actions', $scope);
    $settings->set('marketplace.ebay.client_secret', 'secret-acct-actions', $scope, encrypted: true);

    app(OAuthTokenStore::class)->persist(
        EbayConfiguration::CHANNEL,
        $scope,
        ['access_token' => 'access-acct', 'refresh_token' => 'refresh-acct', 'expires_in' => 3600],
        [EBAY_SCOPE_ACCOUNT, EBAY_SCOPE_INVENTORY],
    );

    Http::fake(function ($request) {
        $url = $request->url();
        $method = $request->method();

        return match (true) {
            str_contains($url, '/program/get_opted_in_programs') => Http::response(['programs' => []]),
            str_contains($url, '/program/opt_in') => Http::response([], 200),
            $method === 'POST' && str_contains($url, '/payment_policy') => Http::response(['paymentPolicyId' => 'NEW-PAY']),
            $method === 'POST' && str_contains($url, '/return_policy') => Http::response(['returnPolicyId' => 'NEW-RET']),
            $method === 'POST' && str_contains($url, '/fulfillment_policy') => Http::response(['fulfillmentPolicyId' => 'NEW-FUL']),
            str_contains($url, '/payment_policy') => Http::response(['paymentPolicies' => []]),
            str_contains($url, '/return_policy') => Http::response(['returnPolicies' => []]),
            str_contains($url, '/fulfillment_policy') => Http::response(['fulfillmentPolicies' => []]),
            $method === 'POST' && str_contains($url, '/sell/inventory/v1/location/') => Http::response([], 204),
            str_contains($url, '/sell/inventory/v1/location') => Http::response(['locations' => []]),
            default => Http::response([], 200),
        };
    });

    $this->actingAs($user);

    Livewire::test(EbaySettings::class)
        ->call('optInToBusinessPolicies')
        ->assertHasNoErrors()
        ->set('newLocationKey', 'california_shop')
        ->set('newLocationCountry', 'US')
        ->set('newLocationState', 'CA')
        ->set('newLocationCity', 'Los Angeles')
        ->set('newLocationPostal', '90001')
        ->call('createMerchantLocation')
        ->assertHasNoErrors()
        ->assertSee('set as your default location')
        ->call('createStarterPolicies')
        ->assertHasNoErrors()
        ->assertSee('switched on for your eBay account')
        ->assertSee('Three policies are now on your eBay account');

    expect($settings->get('marketplace.ebay.default_merchant_location_key', scope: $scope))->toBe('california_shop')
        ->and($settings->get('marketplace.ebay.default_payment_policy_id', scope: $scope))->toBe('NEW-PAY')
        ->and($settings->get('marketplace.ebay.default_fulfillment_policy_id', scope: $scope))->toBe('NEW-FUL')
        ->and($settings->get('marketplace.ebay.default_return_policy_id', scope: $scope))->toBe('NEW-RET');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/program/opt_in')
        && ($request->data()['programType'] ?? null) === 'SELLING_POLICY_MANAGEMENT');
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/sell/inventory/v1/location/california_shop'));
});

test('eBay settings updates an existing location address instead of failing or duplicating', function (): void {
    $user = createAdminUser();
    $scope = Scope::company($user->company_id);
    $settings = app(SettingsService::class);
    $settings->set('marketplace.ebay.environment', 'sandbox', $scope);
    $settings->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);
    $settings->set('marketplace.ebay.client_id', 'client-loc-update', $scope);
    $settings->set('marketplace.ebay.client_secret', 'secret-loc-update', $scope, encrypted: true);

    app(OAuthTokenStore::class)->persist(
        EbayConfiguration::CHANNEL,
        $scope,
        ['access_token' => 'access-loc', 'refresh_token' => 'refresh-loc', 'expires_in' => 3600],
        [EBAY_SCOPE_ACCOUNT, EBAY_SCOPE_INVENTORY],
    );

    Http::fake(function ($request) {
        $url = $request->url();
        $method = $request->method();

        return match (true) {
            str_contains($url, '/update_location_details') => Http::response([], 204),
            str_contains($url, '/payment_policy') => Http::response(['paymentPolicies' => []]),
            str_contains($url, '/return_policy') => Http::response(['returnPolicies' => []]),
            str_contains($url, '/fulfillment_policy') => Http::response(['fulfillmentPolicies' => []]),
            $method === 'GET' && str_contains($url, '/sell/inventory/v1/location') => Http::response(['locations' => [[
                'merchantLocationKey' => 'warehouse',
                'name' => 'Warehouse',
                'merchantLocationStatus' => 'ENABLED',
                'location' => ['address' => ['country' => 'US', 'city' => 'Los Angeles', 'postalCode' => '90001']],
                'locationTypes' => ['WAREHOUSE'],
            ]]]),
            default => Http::response([], 200),
        };
    });

    $this->actingAs($user);

    Livewire::test(EbaySettings::class)
        ->set('newLocationKey', 'warehouse')
        ->set('newLocationCountry', 'US')
        ->set('newLocationState', 'CA')
        ->set('newLocationCity', 'San Diego')
        ->set('newLocationPostal', '92101')
        ->call('createMerchantLocation')
        ->assertHasNoErrors()
        ->assertSee('Updated the address');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/sell/inventory/v1/location/warehouse/update_location_details')
        && data_get($request->data(), 'location.address.city') === 'San Diego');
    Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/sell/inventory/v1/location/warehouse'));
});

test('eBay settings validates the merchant location form before calling eBay', function (): void {
    $user = createAdminUser();
    $scope = Scope::company($user->company_id);
    app(SettingsService::class)->set('marketplace.ebay.environment', 'sandbox', $scope);
    app(SettingsService::class)->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);

    Http::fake();
    $this->actingAs($user);

    Livewire::test(EbaySettings::class)
        ->set('newLocationKey', 'bad key!')
        ->set('newLocationCity', '')
        ->call('createMerchantLocation')
        ->assertHasErrors(['newLocationKey', 'newLocationCity']);

    Http::assertNothingSent();
});

test('eBay settings populates location state suggestions from the chosen country and clears them on change', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    app(SettingsService::class)->set('marketplace.ebay.marketplace_id', 'EBAY_US', Scope::company($user->company_id));

    Admin1::create(['code' => 'US.CA', 'name' => 'California']);
    Admin1::create(['code' => 'US.NY', 'name' => 'New York']);
    Admin1::create(['code' => 'CA.BC', 'name' => 'British Columbia']);

    Livewire::test(EbaySettings::class)
        ->set('newLocationCountry', 'US')
        ->assertSet('newLocationStateOptions', [
            ['value' => 'CA', 'label' => 'California'],
            ['value' => 'NY', 'label' => 'New York'],
        ])
        ->set('newLocationState', 'CA')
        ->set('newLocationCountry', 'CA')
        ->assertSet('newLocationState', '')
        ->assertSet('newLocationStateOptions', [
            ['value' => 'BC', 'label' => 'British Columbia'],
        ]);
});

test('eBay settings hides starter policies on the live environment', function (): void {
    $user = createAdminUser();
    $scope = Scope::company($user->company_id);
    app(SettingsService::class)->set('marketplace.ebay.environment', 'live', $scope);
    app(SettingsService::class)->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);

    $this->actingAs($user);

    Livewire::test(EbaySettings::class)
        ->assertSee('Account setup')
        ->assertSee('Turn on Business Policies')
        ->assertSee('Add a shipping location')
        ->assertSee('Seller Hub')
        ->assertDontSee('Create starter policies');
});

test('eBay settings saves template category mappings for readiness', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $template = ProductTemplate::factory()->create([
        'company_id' => $user->company_id,
        'name' => 'Brake Caliper',
    ]);

    Livewire::test(EbaySettings::class)
        ->assertSee('eBay category mappings')
        ->assertSee('Brake Caliper')
        ->set("templateCategoryMappings.{$template->id}.marketplace_id", 'EBAY_MOTORS_US')
        ->set("templateCategoryMappings.{$template->id}.category_tree_id", '100')
        ->set("templateCategoryMappings.{$template->id}.category_id", '33563')
        ->call('saveTemplateCategoryMappings')
        ->assertHasNoErrors();

    expect(data_get($template->fresh()->metadata, 'marketplace.ebay'))->toBe([
        'marketplace_id' => 'EBAY_MOTORS_US',
        'category_tree_id' => '100',
        'category_id' => '33563',
    ]);
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

test('eBay client secret field can reveal the saved Cert ID when configured', function (): void {
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
        ->assertSet('values.marketplace__ebay__client_secret', 'client-secret-1234567890')
        ->assertSee('Show secret');
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

test('eBay settings diagnostics verifies the saved OAuth grant against a safe Account API call', function (): void {
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
        'https://api.sandbox.ebay.com/sell/account/v1/payment_policy*' => Http::response([
            'paymentPolicies' => [
                ['paymentPolicyId' => 'policy-1', 'name' => 'Default Payments'],
            ],
        ]),
    ]);

    $this->actingAs($user);

    Livewire::test(EbaySettings::class)
        ->call('runDiagnostics')
        ->assertSet('diagnostics.status', EbayDiagnosticsService::STATUS_HEALTHY)
        ->assertSee('responded successfully');

    $stored = $settings->get(EbayDiagnosticsService::SETTINGS_KEY, scope: $scope);

    expect($stored['status'])->toBe(EbayDiagnosticsService::STATUS_HEALTHY)
        ->and($stored['probe_key'])->toBe('account_payment_policies')
        ->and($stored['http_status'])->toBe(200)
        ->and($stored['message'])->toContain('responded successfully');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.sandbox.ebay.com/sell/account/v1/payment_policy')
        && str_contains($request->url(), 'marketplace_id=EBAY_US')
        && $request->hasHeader('Authorization', 'Bearer access-token-connection-test'));
});

test('eBay settings diagnostics explains when OAuth has not been connected yet', function (): void {
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
        ->call('runDiagnostics')
        ->assertSet('diagnostics.status', EbayDiagnosticsService::STATUS_FAILED)
        ->assertSee('OAuth is not connected yet');

    $stored = $settings->get(EbayDiagnosticsService::SETTINGS_KEY, scope: $scope);

    expect($stored['status'])->toBe(EbayDiagnosticsService::STATUS_FAILED)
        ->and($stored['message'])->toBe('OAuth is not connected yet. Use Connect eBay on this page, approve the requested scopes, then run diagnostics again.');

    Http::assertNothingSent();
});

/**
 * Seed a company with saved eBay credentials and a connected OAuth grant.
 *
 * @param  list<string>  $scopes
 */
function seedConnectedEbay(User $user, array $scopes): void
{
    $scope = Scope::company($user->company_id);
    $settings = app(SettingsService::class);

    $settings->set('marketplace.ebay.environment', 'sandbox', $scope);
    $settings->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);
    $settings->set('marketplace.ebay.client_id', 'client-diagnostics', $scope);
    $settings->set('marketplace.ebay.client_secret', 'secret-diagnostics', $scope, encrypted: true);
    $settings->set('marketplace.ebay.ru_name', 'KiatNg-Belimbin-SBX-runame', $scope);
    $settings->set('marketplace.ebay.scopes', $scopes, $scope);

    app(OAuthTokenStore::class)->persist(
        EbayConfiguration::CHANNEL,
        $scope,
        ['access_token' => 'access-diagnostics', 'refresh_token' => 'refresh-diagnostics', 'expires_in' => 3600],
        $scopes,
    );
}

/**
 * Create a company user who may manage the marketplace but cannot view exchanges.
 */
function createMarketplaceManager(): User
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $role = Role::query()->create([
        'company_id' => $company->id,
        'code' => 'mkt_manager_'.$company->id,
        'name' => 'Marketplace Manager',
        'is_system' => false,
        'grant_all' => false,
    ]);

    DB::table('base_authz_role_capabilities')->insert([
        'role_id' => $role->id,
        'capability_key' => 'commerce.marketplace.manage',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);

    return $user;
}

test('eBay settings diagnostics classifies a business-policy precondition as attention, not failure', function (): void {
    $user = createAdminUser();
    seedConnectedEbay($user, [EBAY_SCOPE_ACCOUNT, EBAY_SCOPE_INVENTORY]);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/account/v1/payment_policy*' => Http::response([
            'errors' => [[
                'errorId' => 20403,
                'domain' => 'API_ACCOUNT',
                'message' => 'Seller is not opted in to business policies.',
            ]],
        ], 400),
    ]);

    $this->actingAs($user);

    Livewire::test(EbaySettings::class)
        ->call('runDiagnostics')
        ->assertSet('diagnostics.status', EbayDiagnosticsService::STATUS_ATTENTION)
        ->assertSee('account is not ready');

    $stored = app(SettingsService::class)->get(EbayDiagnosticsService::SETTINGS_KEY, scope: Scope::company($user->company_id));

    expect($stored['status'])->toBe(EbayDiagnosticsService::STATUS_ATTENTION)
        ->and($stored['http_status'])->toBe(400)
        ->and($stored['response_excerpt'])->toContain('20403')
        ->and($stored['response_excerpt'])->toContain('API_ACCOUNT');
});

test('eBay settings diagnostics runs the selected inventory probe against the Inventory API', function (): void {
    $user = createAdminUser();
    seedConnectedEbay($user, [EBAY_SCOPE_ACCOUNT, EBAY_SCOPE_INVENTORY]);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/inventory/v1/location*' => Http::response(['locations' => []]),
    ]);

    $this->actingAs($user);

    Livewire::test(EbaySettings::class)
        ->set('diagnosticProbeKey', 'inventory_locations')
        ->call('runDiagnostics')
        ->assertSet('diagnostics.status', EbayDiagnosticsService::STATUS_HEALTHY)
        ->assertSet('diagnostics.probe_key', 'inventory_locations');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.sandbox.ebay.com/sell/inventory/v1/location')
        && ! str_contains($request->url(), 'marketplace_id')
        && str_contains($request->url(), 'limit=1'));
});

test('eBay settings diagnostics links to the integration exchange for authorized viewers', function (): void {
    $user = createAdminUser();
    seedConnectedEbay($user, [EBAY_SCOPE_ACCOUNT, EBAY_SCOPE_INVENTORY]);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/account/v1/payment_policy*' => Http::response(['paymentPolicies' => []]),
    ]);

    $this->actingAs($user);

    Livewire::test(EbaySettings::class)
        ->call('runDiagnostics')
        ->assertSet('diagnostics.status', EbayDiagnosticsService::STATUS_HEALTHY)
        ->assertSee('Open exchange');
});

test('eBay settings diagnostics hides the exchange link from operators without exchange access', function (): void {
    $user = createMarketplaceManager();
    seedConnectedEbay($user, [EBAY_SCOPE_ACCOUNT, EBAY_SCOPE_INVENTORY]);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/account/v1/payment_policy*' => Http::response(['paymentPolicies' => []]),
    ]);

    $this->actingAs($user);

    Livewire::test(EbaySettings::class)
        ->call('runDiagnostics')
        ->assertSet('diagnostics.status', EbayDiagnosticsService::STATUS_HEALTHY)
        ->assertDontSee('Open exchange');
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
