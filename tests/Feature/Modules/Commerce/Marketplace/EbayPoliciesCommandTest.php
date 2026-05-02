<?php

use App\Base\Integration\Services\OAuthTokenStore;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use Illuminate\Support\Facades\Http;

function configureEbayCommandTestEnvironment(int $companyId): void
{
    $scope = Scope::company($companyId);
    $settings = app(SettingsService::class);
    $settings->set('marketplace.ebay.environment', 'sandbox', $scope);
    $settings->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);
    $settings->set('marketplace.ebay.client_id', 'client-cmd-test', $scope);
    $settings->set('marketplace.ebay.client_secret', 'secret-cmd-test', $scope, encrypted: true);
    $settings->set('marketplace.ebay.redirect_uri', 'https://blb.test/commerce/marketplace/ebay/oauth/callback', $scope);

    app(OAuthTokenStore::class)->persist(
        EbayConfiguration::CHANNEL,
        $scope,
        [
            'access_token' => 'access-token-cmd',
            'refresh_token' => 'refresh-token-cmd',
            'expires_in' => 3600,
        ],
        ['https://api.ebay.com/oauth/api_scope/sell.account.readonly'],
    );
}

test('policies command lists payment, fulfillment, and return policies for the company', function (): void {
    $user = createAdminUser();
    configureEbayCommandTestEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/account/v1/payment_policy*' => Http::response([
            'paymentPolicies' => [
                ['paymentPolicyId' => 'PAY-1', 'name' => 'Standard payment', 'marketplaceId' => 'EBAY_US'],
            ],
        ]),
        'https://api.sandbox.ebay.com/sell/account/v1/fulfillment_policy*' => Http::response([
            'fulfillmentPolicies' => [
                ['fulfillmentPolicyId' => 'FUL-9', 'name' => 'Domestic ground', 'marketplaceId' => 'EBAY_US'],
            ],
        ]),
        'https://api.sandbox.ebay.com/sell/account/v1/return_policy*' => Http::response([
            'returnPolicies' => [
                ['returnPolicyId' => 'RET-7', 'name' => '30-day buyer pays', 'marketplaceId' => 'EBAY_US'],
            ],
        ]),
    ]);

    $this->artisan('commerce:marketplace:ebay:policies', ['--company-id' => $user->company_id])
        ->assertSuccessful()
        ->expectsOutputToContain('Payment policies')
        ->expectsOutputToContain('PAY-1')
        ->expectsOutputToContain('Fulfillment policies')
        ->expectsOutputToContain('FUL-9')
        ->expectsOutputToContain('Return policies')
        ->expectsOutputToContain('RET-7');
});

test('policies command honours --kind to limit the call surface', function (): void {
    $user = createAdminUser();
    configureEbayCommandTestEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/account/v1/payment_policy*' => Http::response([
            'paymentPolicies' => [
                ['paymentPolicyId' => 'PAY-2', 'name' => 'Wire transfer only', 'marketplaceId' => 'EBAY_US'],
            ],
        ]),
    ]);

    $this->artisan('commerce:marketplace:ebay:policies', [
        '--company-id' => $user->company_id,
        '--kind' => 'payment',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('PAY-2')
        ->doesntExpectOutputToContain('Fulfillment policies')
        ->doesntExpectOutputToContain('Return policies');

    Http::assertSentCount(1);
});

test('policies command warns when eBay reports no policies of a kind', function (): void {
    $user = createAdminUser();
    configureEbayCommandTestEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/account/v1/return_policy*' => Http::response([]),
    ]);

    $this->artisan('commerce:marketplace:ebay:policies', [
        '--company-id' => $user->company_id,
        '--kind' => 'return',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('None defined on this account');
});

test('policies command exits non-zero when the API call fails', function (): void {
    $user = createAdminUser();
    configureEbayCommandTestEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/account/v1/payment_policy*' => Http::response(['errors' => ['invalid_scope']], 403),
    ]);

    $this->artisan('commerce:marketplace:ebay:policies', [
        '--company-id' => $user->company_id,
        '--kind' => 'payment',
    ])->assertFailed();
});
