<?php

use App\Base\Integration\Services\OAuthTokenStore;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function configureEbayAccountSetupEnvironment(int $companyId): void
{
    $scope = Scope::company($companyId);
    $settings = app(SettingsService::class);
    $settings->set('marketplace.ebay.environment', 'sandbox', $scope);
    $settings->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);
    $settings->set('marketplace.ebay.client_id', 'client-setup-test', $scope);
    $settings->set('marketplace.ebay.client_secret', 'secret-setup-test', $scope, encrypted: true);
    $settings->set('marketplace.ebay.redirect_uri', 'https://blb.test/commerce/marketplace/ebay/oauth/callback', $scope);

    app(OAuthTokenStore::class)->persist(
        EbayConfiguration::CHANNEL,
        $scope,
        ['access_token' => 'access-setup', 'refresh_token' => 'refresh-setup', 'expires_in' => 3600],
        ['https://api.ebay.com/oauth/api_scope/sell.account'],
    );
}

test('account setup is idempotent when already opted in with existing policies and location', function (): void {
    $user = createAdminUser();
    configureEbayAccountSetupEnvironment($user->company_id);

    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/program/get_opted_in_programs') => Http::response(['programs' => [['programType' => 'SELLING_POLICY_MANAGEMENT']]]),
            str_contains($url, '/payment_policy') => Http::response(['paymentPolicies' => [['paymentPolicyId' => 'PAY-1', 'name' => 'Existing', 'marketplaceId' => 'EBAY_US']]]),
            str_contains($url, '/return_policy') => Http::response(['returnPolicies' => [['returnPolicyId' => 'RET-1', 'name' => 'Existing', 'marketplaceId' => 'EBAY_US']]]),
            str_contains($url, '/fulfillment_policy') => Http::response(['fulfillmentPolicies' => [['fulfillmentPolicyId' => 'FUL-1', 'name' => 'Existing', 'marketplaceId' => 'EBAY_US']]]),
            str_contains($url, '/sell/inventory/v1/location') => Http::response(['locations' => [['merchantLocationKey' => 'california_shop', 'name' => 'CA', 'merchantLocationStatus' => 'ENABLED', 'location' => ['address' => ['country' => 'US']], 'locationTypes' => ['WAREHOUSE']]]]),
            default => Http::response([], 200),
        };
    });

    $this->artisan('commerce:marketplace:ebay:account:setup', [
        '--company-id' => $user->company_id,
        '--location-key' => 'california_shop',
    ])->assertSuccessful();

    $scope = Scope::company($user->company_id);
    $settings = app(SettingsService::class);
    expect($settings->get('marketplace.ebay.default_payment_policy_id', null, $scope))->toBe('PAY-1')
        ->and($settings->get('marketplace.ebay.default_return_policy_id', null, $scope))->toBe('RET-1')
        ->and($settings->get('marketplace.ebay.default_fulfillment_policy_id', null, $scope))->toBe('FUL-1')
        ->and($settings->get('marketplace.ebay.default_merchant_location_key', null, $scope))->toBe('california_shop');

    // Idempotent: no opt-in, policy-create, or location-create writes happen.
    Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/program/opt_in'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && Str::endsWith($request->url(), '/payment_policy'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && Str::contains($request->url(), '/sell/inventory/v1/location/'));
});

test('account setup opts in and creates default policies and location when missing', function (): void {
    $user = createAdminUser();
    configureEbayAccountSetupEnvironment($user->company_id);

    Http::fake(function (Request $request) {
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

    $this->artisan('commerce:marketplace:ebay:account:setup', [
        '--company-id' => $user->company_id,
        '--create-policies' => true,
        '--location-key' => 'warehouse',
    ])->assertSuccessful();

    $scope = Scope::company($user->company_id);
    $settings = app(SettingsService::class);
    expect($settings->get('marketplace.ebay.default_payment_policy_id', null, $scope))->toBe('NEW-PAY')
        ->and($settings->get('marketplace.ebay.default_return_policy_id', null, $scope))->toBe('NEW-RET')
        ->and($settings->get('marketplace.ebay.default_fulfillment_policy_id', null, $scope))->toBe('NEW-FUL')
        ->and($settings->get('marketplace.ebay.default_merchant_location_key', null, $scope))->toBe('warehouse');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/program/opt_in')
        && ($request->data()['programType'] ?? null) === 'SELLING_POLICY_MANAGEMENT');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && Str::endsWith($request->url(), '/sell/inventory/v1/location/warehouse'));
});
