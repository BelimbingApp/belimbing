<?php

use App\Base\Integration\Services\OAuthTokenStore;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Marketplace\Ebay\DTO\EbayInventoryLocation;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayLocationsService;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function configureEbayLocationsEnvironment(int $companyId): void
{
    $scope = Scope::company($companyId);
    $settings = app(SettingsService::class);
    $settings->set('marketplace.ebay.environment', 'sandbox', $scope);
    $settings->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);
    $settings->set('marketplace.ebay.client_id', 'client-locations-test', $scope);
    $settings->set('marketplace.ebay.client_secret', 'secret-locations-test', $scope, encrypted: true);
    $settings->set('marketplace.ebay.redirect_uri', 'https://blb.test/commerce/marketplace/ebay/oauth/callback', $scope);

    app(OAuthTokenStore::class)->persist(
        EbayConfiguration::CHANNEL,
        $scope,
        [
            'access_token' => 'access-token-locations',
            'refresh_token' => 'refresh-token-locations',
            'expires_in' => 3600,
        ],
        ['https://api.ebay.com/oauth/api_scope/sell.inventory'],
    );
}

test('pulls inventory locations from the Inventory API and surfaces address fields', function (): void {
    $user = createAdminUser();
    configureEbayLocationsEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/inventory/v1/location*' => Http::response([
            'locations' => [
                [
                    'merchantLocationKey' => 'home_warehouse',
                    'name' => "Ham's home warehouse",
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
                [
                    'merchantLocationKey' => 'overflow_unit',
                    'name' => 'Overflow storage unit',
                    'merchantLocationStatus' => 'DISABLED',
                    'locationTypes' => ['STORE'],
                    'location' => [
                        'address' => [
                            'country' => 'US',
                            'postalCode' => '90211',
                            'city' => 'Los Angeles',
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $locations = app(EbayLocationsService::class)->pullInventoryLocations($user->company_id);

    expect($locations)->toHaveCount(2)
        ->and($locations->first())->toBeInstanceOf(EbayInventoryLocation::class)
        ->and($locations->first()->merchantLocationKey)->toBe('home_warehouse')
        ->and($locations->first()->name)->toBe("Ham's home warehouse")
        ->and($locations->first()->status)->toBe('ENABLED')
        ->and($locations->first()->isEnabled())->toBeTrue()
        ->and($locations->first()->country)->toBe('US')
        ->and($locations->first()->postalCode)->toBe('90210')
        ->and($locations->first()->city)->toBe('Beverly Hills')
        ->and($locations->first()->locationTypes)->toBe(['WAREHOUSE'])
        ->and($locations->last()->isEnabled())->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        return str_starts_with($request->url(), 'https://api.sandbox.ebay.com/sell/inventory/v1/location');
    });
});

test('returns an empty collection when the seller has no locations defined yet', function (): void {
    $user = createAdminUser();
    configureEbayLocationsEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/inventory/v1/location*' => Http::response([]),
    ]);

    $locations = app(EbayLocationsService::class)->pullInventoryLocations($user->company_id);

    expect($locations)->toHaveCount(0);
});

test('handles locations with missing address blocks gracefully', function (): void {
    $user = createAdminUser();
    configureEbayLocationsEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/inventory/v1/location*' => Http::response([
            'locations' => [
                [
                    'merchantLocationKey' => 'minimal',
                    'name' => 'Minimal location',
                    'merchantLocationStatus' => 'ENABLED',
                    // no `location.address`, no `locationTypes`
                ],
            ],
        ]),
    ]);

    $location = app(EbayLocationsService::class)->pullInventoryLocations($user->company_id)->first();

    expect($location->merchantLocationKey)->toBe('minimal')
        ->and($location->country)->toBeNull()
        ->and($location->postalCode)->toBeNull()
        ->and($location->city)->toBeNull()
        ->and($location->locationTypes)->toBe([]);
});

test('exits non-zero by surfacing HTTP failures from eBay', function (): void {
    $user = createAdminUser();
    configureEbayLocationsEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/inventory/v1/location*' => Http::response(['errors' => ['unauthorized']], 401),
    ]);

    expect(fn () => app(EbayLocationsService::class)->pullInventoryLocations($user->company_id))
        ->toThrow(function (MarketplaceOperationException $exception): void {
            expect($exception->context['status'])->toBe(401)
                ->and($exception->context['exchange_id'] ?? null)->toStartWith('ix_')
                ->and($exception->getMessage())->toContain('Integration exchange [ix_');
        });
});
