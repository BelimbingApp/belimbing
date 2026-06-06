<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Integration\Models\OAuthToken;
use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use App\Base\Integration\Services\IntegrationResponse;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Marketplace\Ebay\Diagnostics\EbayDiagnosticProbe;
use App\Modules\Commerce\Marketplace\Ebay\Diagnostics\EbayDiagnosticProbes;
use App\Modules\Commerce\Marketplace\Ebay\DTO\EbayDiagnosticsResult;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Runs read-only eBay endpoint diagnostics.
 *
 * Each run executes one curated probe (see {@see EbayDiagnosticProbes}) through
 * the IntegrationGateway, pre-flighting credentials, OAuth grant, and the
 * probe's required scope before calling eBay. Results are classified as:
 *
 * - healthy:   the endpoint responded 2xx.
 * - attention: a known account-precondition response (e.g. business policies
 *              not opted in) — the connection works but the account is not ready.
 * - failed:    transport, auth, scope, or otherwise unexpected responses.
 *
 * The last result is persisted as a single structured settings value so the
 * settings page can render it without re-calling eBay.
 */
class EbayDiagnosticsService
{
    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_ATTENTION = 'attention';

    public const STATUS_FAILED = 'failed';

    public const SETTINGS_KEY = 'marketplace.ebay.diagnostics';

    /**
     * Scopes that satisfy each probe scope group. A probe is runnable if the
     * granted token holds any scope in its group (read-only counts).
     *
     * @var array<string, list<string>>
     */
    private const SCOPE_GROUPS = [
        EbayDiagnosticProbe::SCOPE_GROUP_ACCOUNT => [
            'https://api.ebay.com/oauth/api_scope/sell.account',
            'https://api.ebay.com/oauth/api_scope/sell.account.readonly',
        ],
        EbayDiagnosticProbe::SCOPE_GROUP_INVENTORY => [
            'https://api.ebay.com/oauth/api_scope/sell.inventory',
            'https://api.ebay.com/oauth/api_scope/sell.inventory.readonly',
        ],
    ];

    /**
     * Known eBay error identifiers that mean "connection works, account is not
     * ready yet". These map to attention rather than a hard failure.
     *
     * @var list<int>
     */
    private const ACCOUNT_PRECONDITION_ERROR_IDS = [
        20403, // Seller not eligible / business policies not opted in (Account API).
    ];

    public function __construct(
        private readonly EbayConfiguration $configuration,
        private readonly EbayOAuthService $oauth,
        private readonly IntegrationGateway $integration,
        private readonly SettingsService $settings,
        private readonly EbayDiagnosticProbes $probes,
    ) {}

    public function run(int $companyId, ?string $probeKey = null): EbayDiagnosticsResult
    {
        $probe = $this->probes->find($probeKey);
        $result = $this->execute($companyId, $probe);
        $this->persist($companyId, $result);

        return $result;
    }

    private function execute(int $companyId, EbayDiagnosticProbe $probe): EbayDiagnosticsResult
    {
        $testedAt = Carbon::now();

        try {
            $config = $this->configuration->requireConfigured($companyId);
        } catch (MarketplaceOperationException) {
            return $this->preflightFailure($probe, $testedAt, __('Add the eBay Client ID, Client secret, and Redirect URL name (RuName), save settings, then connect eBay.'));
        }

        $scopeGroup = self::SCOPE_GROUPS[$probe->scopeGroup];

        if (! $this->hasAnyScope($config['scopes'], $scopeGroup)) {
            return $this->preflightFailure($probe, $testedAt, __('This probe needs the :scope scope. Open Advanced OAuth settings, enable it, save, then reconnect eBay.', ['scope' => $probe->scopeGroup]));
        }

        $token = $this->oauth->tokenForCompany($companyId);

        if (! $token instanceof OAuthToken || $token->refresh_token === null) {
            return $this->preflightFailure($probe, $testedAt, __('OAuth is not connected yet. Use Connect eBay on this page, approve the requested scopes, then run diagnostics again.'));
        }

        if (! $this->hasAnyScope($token->scopes ?? [], $scopeGroup)) {
            return $this->preflightFailure($probe, $testedAt, __('The saved eBay grant is missing the :scope scope this probe needs. Reconnect eBay after enabling it in Advanced OAuth settings.', ['scope' => $probe->scopeGroup]));
        }

        try {
            $accessToken = $this->oauth->accessToken($companyId);
        } catch (Throwable) {
            return $this->preflightFailure($probe, $testedAt, __('Belimbing could not refresh the eBay OAuth token. Check that sandbox/live mode matches the saved credentials, then reconnect eBay.'));
        }

        $endpoint = rtrim((string) $config['api_base_url'], '/').$probe->path;
        $query = $probe->query;

        if ($probe->marketplaceScoped) {
            $query = ['marketplace_id' => (string) $config['marketplace_id']] + $query;
        }

        $response = $this->integration->send(new IntegrationRequest(
            system: EbayConfiguration::CHANNEL,
            operation: $probe->operation(),
            method: $probe->method(),
            endpoint: $endpoint,
            protocolOperation: $probe->method().' '.$probe->path,
            provider: EbayConfiguration::CHANNEL,
            headers: ['Authorization' => 'Bearer '.$accessToken],
            query: $query,
            ownerType: 'company',
            ownerId: $companyId,
            timeoutSeconds: 20,
            retryTimes: 0,
            metadata: [
                'environment' => $config['environment'],
                'marketplace_id' => $config['marketplace_id'],
                'diagnostic_probe' => $probe->key,
            ],
        ));

        return $this->classify($probe, $testedAt, $endpoint, $query, $response);
    }

    private function classify(EbayDiagnosticProbe $probe, Carbon $testedAt, string $endpoint, array $query, IntegrationResponse $response): EbayDiagnosticsResult
    {
        $excerpt = $this->responseExcerpt($response);

        if ($response->successful()) {
            return new EbayDiagnosticsResult(
                probeKey: $probe->key,
                status: self::STATUS_HEALTHY,
                message: __(':probe responded successfully. Credentials, OAuth, environment, scope, and the endpoint are all working.', ['probe' => $probe->label]),
                testedAt: $testedAt,
                endpoint: $endpoint,
                query: $query,
                httpStatus: $response->status,
                exchangeId: $response->exchange?->id,
                responseExcerpt: $excerpt,
            );
        }

        $errorId = $this->firstErrorId($response);

        if ($errorId !== null && in_array($errorId, self::ACCOUNT_PRECONDITION_ERROR_IDS, true)) {
            return new EbayDiagnosticsResult(
                probeKey: $probe->key,
                status: self::STATUS_ATTENTION,
                message: __('Belimbing reached eBay, but the account is not ready for this endpoint yet. Opt in to eBay business policies for this marketplace, then run diagnostics again.'),
                testedAt: $testedAt,
                endpoint: $endpoint,
                query: $query,
                httpStatus: $response->status,
                exchangeId: $response->exchange?->id,
                responseExcerpt: $excerpt,
            );
        }

        return new EbayDiagnosticsResult(
            probeKey: $probe->key,
            status: self::STATUS_FAILED,
            message: $this->failureMessage($response->status),
            testedAt: $testedAt,
            endpoint: $endpoint,
            query: $query,
            httpStatus: $response->status,
            exchangeId: $response->exchange?->id,
            responseExcerpt: $excerpt,
        );
    }

    private function preflightFailure(EbayDiagnosticProbe $probe, Carbon $testedAt, string $message): EbayDiagnosticsResult
    {
        return new EbayDiagnosticsResult(
            probeKey: $probe->key,
            status: self::STATUS_FAILED,
            message: $message,
            testedAt: $testedAt,
            endpoint: $probe->path,
            query: $probe->query,
        );
    }

    /**
     * @param  list<string>  $granted
     * @param  list<string>  $group
     */
    private function hasAnyScope(array $granted, array $group): bool
    {
        return array_intersect($group, $granted) !== [];
    }

    private function firstErrorId(IntegrationResponse $response): ?int
    {
        $errorId = $response->json('errors.0.errorId');

        return is_numeric($errorId) ? (int) $errorId : null;
    }

    private function responseExcerpt(IntegrationResponse $response): ?string
    {
        $error = $response->json('errors.0');

        if (! is_array($error)) {
            return null;
        }

        $parts = array_filter([
            isset($error['errorId']) ? 'errorId '.$error['errorId'] : null,
            $error['domain'] ?? null,
            $error['message'] ?? null,
        ], static fn (mixed $part): bool => is_string($part) ? $part !== '' : $part !== null);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function failureMessage(?int $status): string
    {
        return match ($status) {
            401 => __('eBay rejected the OAuth token. Reconnect eBay and confirm sandbox/live mode matches the saved credentials.'),
            403 => __('eBay denied this call. Open Advanced OAuth settings, restore the recommended scopes, reconnect eBay, then run diagnostics again.'),
            null => __('Belimbing could not reach eBay. Check network access from the server, then run diagnostics again.'),
            default => __('eBay returned HTTP :status. Open the integration exchange for the full payload, then run diagnostics again.', ['status' => $status]),
        };
    }

    private function persist(int $companyId, EbayDiagnosticsResult $result): void
    {
        $this->settings->set(self::SETTINGS_KEY, $result->toArray(), Scope::company($companyId));
    }
}
