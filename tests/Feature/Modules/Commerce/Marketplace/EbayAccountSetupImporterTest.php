<?php

use App\Base\Integration\Services\OAuthTokenStore;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Marketplace\Ebay\EbayAccountSetupImporter;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Models\AccountResource;
use Illuminate\Support\Facades\Http;

function configureEbayAccountSetupImportEnvironment(int $companyId): void
{
    $scope = Scope::company($companyId);
    $settings = app(SettingsService::class);
    $settings->set('marketplace.ebay.environment', 'sandbox', $scope);
    $settings->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);
    $settings->set('marketplace.ebay.client_id', 'client-account-setup-test', $scope);
    $settings->set('marketplace.ebay.client_secret', 'secret-account-setup-test', $scope, encrypted: true);
    $settings->set('marketplace.ebay.redirect_uri', 'https://belimbing.test/commerce/marketplace/ebay/oauth/callback', $scope);

    app(OAuthTokenStore::class)->persist(
        EbayConfiguration::CHANNEL,
        $scope,
        [
            'access_token' => 'access-token-account-setup',
            'refresh_token' => 'refresh-token-account-setup',
            'expires_in' => 3600,
        ],
        [
            'https://api.ebay.com/oauth/api_scope/sell.account',
            'https://api.ebay.com/oauth/api_scope/sell.inventory',
        ],
    );
}

test('imports eBay account policies and inventory locations as selectable account resources', function (): void {
    $user = createAdminUser();
    configureEbayAccountSetupImportEnvironment($user->company_id);

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
                    'locationTypes' => ['WAREHOUSE'],
                    'location' => [
                        'address' => [
                            'country' => 'US',
                            'postalCode' => '90210',
                            'city' => 'Beverly Hills',
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $result = app(EbayAccountSetupImporter::class)->import($user->company_id);

    expect($result->total())->toBe(4)
        ->and($result->paymentPolicies)->toBe(1)
        ->and($result->inventoryLocations)->toBe(1);

    $resources = AccountResource::query()
        ->where('company_id', $user->company_id)
        ->orderBy('kind')
        ->get();

    expect($resources)->toHaveCount(4)
        ->and($resources->firstWhere('kind', AccountResource::KIND_PAYMENT_POLICY)?->external_id)->toBe('PAY-1')
        ->and($resources->firstWhere('kind', AccountResource::KIND_INVENTORY_LOCATION)?->external_id)->toBe('california_shop')
        ->and($resources->firstWhere('kind', AccountResource::KIND_INVENTORY_LOCATION)?->payload['city'])->toBe('Beverly Hills')
        ->and($resources->firstWhere('kind', AccountResource::KIND_INVENTORY_LOCATION)?->isEnabled())->toBeTrue();
});

test('refreshing eBay account setup updates existing resources instead of duplicating them', function (): void {
    $user = createAdminUser();
    configureEbayAccountSetupImportEnvironment($user->company_id);

    AccountResource::query()->create([
        'company_id' => $user->company_id,
        'channel' => EbayConfiguration::CHANNEL,
        'marketplace_id' => 'EBAY_US',
        'kind' => AccountResource::KIND_PAYMENT_POLICY,
        'external_id' => 'PAY-1',
        'name' => 'Old payment name',
        'imported_at' => now()->subDay(),
    ]);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/account/v1/payment_policy*' => Http::response([
            'paymentPolicies' => [
                ['paymentPolicyId' => 'PAY-1', 'name' => 'Updated payment name', 'marketplaceId' => 'EBAY_US'],
            ],
        ]),
        'https://api.sandbox.ebay.com/sell/account/v1/fulfillment_policy*' => Http::response([]),
        'https://api.sandbox.ebay.com/sell/account/v1/return_policy*' => Http::response([]),
        'https://api.sandbox.ebay.com/sell/inventory/v1/location*' => Http::response([]),
    ]);

    app(EbayAccountSetupImporter::class)->import($user->company_id);

    expect(AccountResource::query()->where('kind', AccountResource::KIND_PAYMENT_POLICY)->get())->toHaveCount(1)
        ->and(AccountResource::query()->firstWhere('external_id', 'PAY-1')?->name)->toBe('Updated payment name');
});
