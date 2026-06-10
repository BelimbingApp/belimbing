<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;

/**
 * Manages opt-in to eBay seller programs via the Sell Account API.
 *
 * Business policies (payment/fulfillment/return) are gated behind the
 * `SELLING_POLICY_MANAGEMENT` program: until the seller is opted in, every
 * policy read or write fails the precondition with eBay error `20403`
 * ("not eligible for Business Policy"). This service is the opt-in side of
 * account setup; the read/write of the policies themselves lives in
 * {@see EbayPoliciesService}.
 */
class EbayProgramService
{
    public const PROGRAM_SELLING_POLICY_MANAGEMENT = 'SELLING_POLICY_MANAGEMENT';

    public function __construct(
        private readonly EbayConfiguration $configuration,
        private readonly EbayOAuthService $oauth,
        private readonly IntegrationGateway $integration,
    ) {}

    /**
     * @return list<string> the program types the seller is currently opted in to
     */
    public function optedInProgramTypes(int $companyId): array
    {
        $config = $this->configuration->forCompany($companyId);
        $response = $this->integration->send(new IntegrationRequest(
            system: EbayConfiguration::CHANNEL,
            operation: 'commerce.marketplace.ebay.program.opted_in',
            method: 'GET',
            endpoint: rtrim((string) $config['api_base_url'], '/').'/sell/account/v1/program/get_opted_in_programs',
            protocolOperation: 'GET /sell/account/v1/program/get_opted_in_programs',
            provider: EbayConfiguration::CHANNEL,
            headers: ['Authorization' => 'Bearer '.$this->oauth->accessToken($companyId)],
            ownerType: 'company',
            ownerId: $companyId,
            timeoutSeconds: 30,
            retryTimes: 1,
        ));

        if ($response->failed()) {
            throw MarketplaceOperationException::requestFailed(
                EbayConfiguration::CHANNEL,
                'program.opted_in',
                $response->status,
                $response->exchange?->id,
            );
        }

        $body = $response->json();
        $programs = is_array($body) && is_array($body['programs'] ?? null) ? $body['programs'] : [];

        return collect($programs)
            ->map(fn (mixed $program): ?string => is_array($program) && is_string($program['programType'] ?? null) ? $program['programType'] : null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Opt the seller in to a program if not already opted in.
     *
     * @return bool true when this call performed the opt-in, false when the
     *              seller was already opted in (idempotent no-op)
     */
    public function ensureOptedIn(int $companyId, string $programType): bool
    {
        if (in_array($programType, $this->optedInProgramTypes($companyId), true)) {
            return false;
        }

        $config = $this->configuration->forCompany($companyId);
        $response = $this->integration->send(new IntegrationRequest(
            system: EbayConfiguration::CHANNEL,
            operation: 'commerce.marketplace.ebay.program.opt_in',
            method: 'POST',
            endpoint: rtrim((string) $config['api_base_url'], '/').'/sell/account/v1/program/opt_in',
            protocolOperation: 'POST /sell/account/v1/program/opt_in',
            provider: EbayConfiguration::CHANNEL,
            headers: [
                'Authorization' => 'Bearer '.$this->oauth->accessToken($companyId),
                'Content-Type' => 'application/json',
            ],
            body: ['programType' => $programType],
            ownerType: 'company',
            ownerId: $companyId,
            timeoutSeconds: 30,
            retryTimes: 0,
        ));

        if ($response->failed()) {
            throw MarketplaceOperationException::requestFailed(
                EbayConfiguration::CHANNEL,
                'program.opt_in',
                $response->status,
                $response->exchange?->id,
            );
        }

        return true;
    }
}
