<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Integration\Models\OAuthToken;
use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Marketplace\Ebay\DTO\EbayConnectionTestResult;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;
use Illuminate\Support\Carbon;
use Throwable;

class EbayConnectionTester
{
    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_ATTENTION = 'attention';

    public const STATUS_FAILED = 'failed';

    private const SELL_ACCOUNT_SCOPES = [
        'https://api.ebay.com/oauth/api_scope/sell.account',
        'https://api.ebay.com/oauth/api_scope/sell.account.readonly',
    ];

    private const SELL_INVENTORY_SCOPES = [
        'https://api.ebay.com/oauth/api_scope/sell.inventory',
        'https://api.ebay.com/oauth/api_scope/sell.inventory.readonly',
    ];

    public function __construct(
        private readonly EbayConfiguration $configuration,
        private readonly EbayOAuthService $oauth,
        private readonly IntegrationGateway $integration,
        private readonly SettingsService $settings,
    ) {}

    public function test(int $companyId): EbayConnectionTestResult
    {
        $result = $this->run($companyId);
        $this->persist($companyId, $result);

        return $result;
    }

    private function run(int $companyId): EbayConnectionTestResult
    {
        $testedAt = Carbon::now();

        try {
            $config = $this->configuration->requireConfigured($companyId);
        } catch (MarketplaceOperationException) {
            return new EbayConnectionTestResult(
                status: self::STATUS_FAILED,
                message: __('Add the eBay Client ID, Client secret, and Redirect URL name (RuName), save settings, then connect eBay.'),
                testedAt: $testedAt,
            );
        }

        if (! $this->hasRecommendedScopes($config['scopes'])) {
            return new EbayConnectionTestResult(
                status: self::STATUS_FAILED,
                message: __('Belimbing needs the standard eBay access set. Open Advanced OAuth settings, restore the recommended scopes, save, then reconnect eBay.'),
                testedAt: $testedAt,
            );
        }

        $token = $this->oauth->tokenForCompany($companyId);

        if (! $token instanceof OAuthToken || $token->refresh_token === null) {
            return new EbayConnectionTestResult(
                status: self::STATUS_FAILED,
                message: __('OAuth is not connected yet. Use Connect eBay on this page, approve the requested scopes, then test again.'),
                testedAt: $testedAt,
            );
        }

        if (! $this->hasRecommendedScopes($token->scopes ?? [])) {
            return new EbayConnectionTestResult(
                status: self::STATUS_FAILED,
                message: __('The saved OAuth grant is missing one or more recommended eBay scopes. Reconnect eBay after saving the updated scopes.'),
                testedAt: $testedAt,
            );
        }

        try {
            $accessToken = $this->oauth->accessToken($companyId);
        } catch (Throwable) {
            return new EbayConnectionTestResult(
                status: self::STATUS_FAILED,
                message: __('Belimbing could not refresh the eBay OAuth token. Check that sandbox/live mode matches the saved credentials, then reconnect eBay.'),
                testedAt: $testedAt,
            );
        }

        $response = $this->integration->send(new IntegrationRequest(
            system: EbayConfiguration::CHANNEL,
            operation: 'commerce.marketplace.ebay.connection.test',
            method: 'GET',
            endpoint: rtrim((string) $config['api_base_url'], '/').'/sell/inventory/v1/inventory_item',
            protocolOperation: 'GET /sell/inventory/v1/inventory_item',
            provider: EbayConfiguration::CHANNEL,
            headers: ['Authorization' => 'Bearer '.$accessToken],
            query: ['limit' => 1],
            ownerType: 'company',
            ownerId: $companyId,
            timeoutSeconds: 20,
            retryTimes: 0,
            metadata: [
                'environment' => $config['environment'],
                'marketplace_id' => $config['marketplace_id'],
            ],
        ));

        if ($response->failed()) {
            return new EbayConnectionTestResult(
                status: self::STATUS_FAILED,
                message: $this->failureMessage($response->status),
                testedAt: $testedAt,
                httpStatus: $response->status,
                exchangeId: $response->exchange?->id,
            );
        }

        return new EbayConnectionTestResult(
            status: self::STATUS_HEALTHY,
            message: __('Belimbing reached eBay successfully. OAuth, selected environment, recommended seller scopes, and the read-only Inventory API are working.'),
            testedAt: $testedAt,
            httpStatus: $response->status,
            exchangeId: $response->exchange?->id,
        );
    }

    /**
     * @param  list<string>  $scopes
     */
    private function hasSellAccountScope(array $scopes): bool
    {
        return array_intersect(self::SELL_ACCOUNT_SCOPES, $scopes) !== [];
    }

    /**
     * @param  list<string>  $scopes
     */
    private function hasSellInventoryScope(array $scopes): bool
    {
        return array_intersect(self::SELL_INVENTORY_SCOPES, $scopes) !== [];
    }

    /**
     * @param  list<string>  $scopes
     */
    private function hasRecommendedScopes(array $scopes): bool
    {
        return $this->hasSellAccountScope($scopes) && $this->hasSellInventoryScope($scopes);
    }

    private function failureMessage(?int $status): string
    {
        return match ($status) {
            401 => __('eBay rejected the OAuth token. Reconnect eBay and confirm sandbox/live mode matches the saved credentials.'),
            403 => __('eBay denied the Inventory API call. Open Advanced OAuth settings, restore the recommended scopes, reconnect eBay, then test again.'),
            null => __('Belimbing could not reach eBay. Check network access from the server, then test again.'),
            default => __('eBay returned HTTP :status during the connection test. Open the integration exchange for details, then test again.', ['status' => $status]),
        };
    }

    private function persist(int $companyId, EbayConnectionTestResult $result): void
    {
        $scope = Scope::company($companyId);

        $this->settings->set('marketplace.ebay.connection_test_status', $result->status, $scope);
        $this->settings->set('marketplace.ebay.connection_test_message', $result->message, $scope);
        $this->settings->set('marketplace.ebay.connection_tested_at', $result->testedAt->toIso8601String(), $scope);

        if ($result->httpStatus !== null) {
            $this->settings->set('marketplace.ebay.connection_test_http_status', (string) $result->httpStatus, $scope);
        }

        if ($result->exchangeId !== null) {
            $this->settings->set('marketplace.ebay.connection_test_exchange_id', $result->exchangeId, $scope);
        }
    }
}
