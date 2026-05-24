<?php

use App\Base\Integration\Models\OutboundExchange;
use App\Base\Integration\Services\OAuthTokenStore;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Marketplace\Ebay\EbayApplicationTokenService;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function configureEbayApplicationTokenEnvironment(int $companyId): void
{
    $scope = Scope::company($companyId);
    $settings = app(SettingsService::class);
    $settings->set('marketplace.ebay.environment', 'sandbox', $scope);
    $settings->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);
    $settings->set('marketplace.ebay.client_id', 'client-application-test', $scope);
    $settings->set('marketplace.ebay.client_secret', 'secret-application-test', $scope, encrypted: true);
}

test('requests and stores an eBay application token for metadata APIs', function (): void {
    $user = createAdminUser();
    configureEbayApplicationTokenEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/identity/v1/oauth2/token' => Http::response([
            'access_token' => 'application-access-token',
            'expires_in' => 7200,
            'token_type' => 'Application Access Token',
        ]),
    ]);

    $token = app(EbayApplicationTokenService::class)->accessToken($user->company_id);

    expect($token)->toBe('application-access-token');

    $stored = app(OAuthTokenStore::class)->find(
        EbayConfiguration::CHANNEL,
        Scope::company($user->company_id),
        EbayConfiguration::APPLICATION_TOKEN_ACCOUNT_KEY,
    );

    expect($stored)->not->toBeNull()
        ->and($stored->refresh_token)->toBeNull()
        ->and($stored->scopes)->toBe(EbayConfiguration::APPLICATION_SCOPES)
        ->and($stored->metadata['token_kind'])->toBe('application')
        ->and($stored->metadata['environment'])->toBe('sandbox');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.sandbox.ebay.com/identity/v1/oauth2/token'
            && $request['grant_type'] === 'client_credentials'
            && $request['scope'] === 'https://api.ebay.com/oauth/api_scope';
    });

    $exchange = OutboundExchange::query()->firstOrFail();

    expect($exchange->system)->toBe(EbayConfiguration::CHANNEL)
        ->and($exchange->operation)->toBe('oauth2.client_credentials.exchange')
        ->and($exchange->metadata['token_kind'])->toBe('application');
});

test('reuses a valid stored eBay application token', function (): void {
    $user = createAdminUser();
    configureEbayApplicationTokenEnvironment($user->company_id);

    app(OAuthTokenStore::class)->persist(
        EbayConfiguration::CHANNEL,
        Scope::company($user->company_id),
        [
            'access_token' => 'stored-application-token',
            'expires_in' => 3600,
        ],
        EbayConfiguration::APPLICATION_SCOPES,
        EbayConfiguration::APPLICATION_TOKEN_ACCOUNT_KEY,
        metadata: ['token_kind' => 'application'],
    );

    Http::fake();

    $token = app(EbayApplicationTokenService::class)->accessToken($user->company_id);

    expect($token)->toBe('stored-application-token');

    Http::assertNothingSent();
});
