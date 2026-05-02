<?php

use App\Base\Integration\Services\OAuthTokenStore;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Marketplace\Ebay\DTO\EbayBusinessPolicy;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayPoliciesService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function configureEbayPoliciesEnvironment(int $companyId): void
{
    $scope = Scope::company($companyId);
    $settings = app(SettingsService::class);
    $settings->set('marketplace.ebay.environment', 'sandbox', $scope);
    $settings->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);
    $settings->set('marketplace.ebay.client_id', 'client-policy-test', $scope);
    $settings->set('marketplace.ebay.client_secret', 'secret-policy-test', $scope, encrypted: true);
    $settings->set('marketplace.ebay.redirect_uri', 'https://blb.test/commerce/marketplace/ebay/oauth/callback', $scope);

    app(OAuthTokenStore::class)->persist(
        EbayConfiguration::CHANNEL,
        $scope,
        [
            'access_token' => 'access-token-policy',
            'refresh_token' => 'refresh-token-policy',
            'expires_in' => 3600,
        ],
        ['https://api.ebay.com/oauth/api_scope/sell.account.readonly'],
    );
}

test('pulls payment policies as DTOs scoped to the configured marketplace', function (): void {
    $user = createAdminUser();
    configureEbayPoliciesEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/account/v1/payment_policy*' => Http::response([
            'paymentPolicies' => [
                [
                    'paymentPolicyId' => 'PAY-1',
                    'name' => 'Standard payment',
                    'marketplaceId' => 'EBAY_US',
                    'description' => 'Default payment policy',
                ],
                [
                    'paymentPolicyId' => 'PAY-2',
                    'name' => 'Wire transfer only',
                    'marketplaceId' => 'EBAY_US',
                ],
            ],
        ]),
    ]);

    $policies = app(EbayPoliciesService::class)->pullPaymentPolicies($user->company_id);

    expect($policies)->toHaveCount(2)
        ->and($policies->first())->toBeInstanceOf(EbayBusinessPolicy::class)
        ->and($policies->first()->kind)->toBe(EbayBusinessPolicy::KIND_PAYMENT)
        ->and($policies->first()->id)->toBe('PAY-1')
        ->and($policies->first()->name)->toBe('Standard payment')
        ->and($policies->first()->marketplaceId)->toBe('EBAY_US')
        ->and($policies->first()->description)->toBe('Default payment policy')
        ->and($policies->last()->description)->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return str_starts_with($request->url(), 'https://api.sandbox.ebay.com/sell/account/v1/payment_policy')
            && $request['marketplace_id'] === 'EBAY_US';
    });
});

test('pulls fulfillment policies via the fulfillment_policy endpoint', function (): void {
    $user = createAdminUser();
    configureEbayPoliciesEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/account/v1/fulfillment_policy*' => Http::response([
            'fulfillmentPolicies' => [
                [
                    'fulfillmentPolicyId' => 'FUL-9',
                    'name' => 'Domestic ground',
                    'marketplaceId' => 'EBAY_US',
                ],
            ],
        ]),
    ]);

    $policies = app(EbayPoliciesService::class)->pullFulfillmentPolicies($user->company_id);

    expect($policies)->toHaveCount(1)
        ->and($policies->first()->kind)->toBe(EbayBusinessPolicy::KIND_FULFILLMENT)
        ->and($policies->first()->id)->toBe('FUL-9')
        ->and($policies->first()->name)->toBe('Domestic ground');
});

test('pulls return policies via the return_policy endpoint', function (): void {
    $user = createAdminUser();
    configureEbayPoliciesEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/account/v1/return_policy*' => Http::response([
            'returnPolicies' => [
                [
                    'returnPolicyId' => 'RET-7',
                    'name' => '30-day buyer pays return',
                    'marketplaceId' => 'EBAY_US',
                    'description' => null,
                ],
            ],
        ]),
    ]);

    $policies = app(EbayPoliciesService::class)->pullReturnPolicies($user->company_id);

    expect($policies)->toHaveCount(1)
        ->and($policies->first()->kind)->toBe(EbayBusinessPolicy::KIND_RETURN)
        ->and($policies->first()->id)->toBe('RET-7')
        ->and($policies->first()->description)->toBeNull();
});

test('returns an empty collection when eBay reports no policies of the requested kind', function (): void {
    $user = createAdminUser();
    configureEbayPoliciesEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/account/v1/payment_policy*' => Http::response([]),
    ]);

    $policies = app(EbayPoliciesService::class)->pullPaymentPolicies($user->company_id);

    expect($policies)->toHaveCount(0);
});

test('falls back to the configured marketplace when an item omits marketplaceId', function (): void {
    $user = createAdminUser();
    configureEbayPoliciesEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/account/v1/return_policy*' => Http::response([
            'returnPolicies' => [
                [
                    'returnPolicyId' => 'RET-77',
                    'name' => 'Marketplace-omitted policy',
                    // marketplaceId intentionally omitted
                ],
            ],
        ]),
    ]);

    $policies = app(EbayPoliciesService::class)->pullReturnPolicies($user->company_id);

    expect($policies->first()->marketplaceId)->toBe('EBAY_US');
});
