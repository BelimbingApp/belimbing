<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Integration\Models\OAuthToken;
use App\Base\Integration\Services\OAuth2Client;
use App\Base\Integration\Services\OAuthTokenStore;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;
use Illuminate\Support\Str;

class EbayOAuthService
{
    public function __construct(
        private readonly EbayConfiguration $configuration,
        private readonly OAuth2Client $oauth,
        private readonly OAuthTokenStore $tokens,
    ) {}

    public function authorizationUrl(int $companyId): string
    {
        $config = $this->configuration->requireConfigured($companyId);
        $state = Str::random(40);
        session(['marketplace.ebay.oauth_state' => $state]);

        return $this->oauth->authorizationUrl(
            $config['auth_url'],
            $config['client_id'],
            $config['redirect_uri'],
            $config['scopes'],
            $state,
            ['prompt' => 'login'],
        );
    }

    public function exchangeCode(int $companyId, string $code): OAuthToken
    {
        $config = $this->configuration->requireConfigured($companyId);
        $payload = $this->oauth->exchangeAuthorizationCode(
            $config['token_url'],
            $config['client_id'],
            $config['client_secret'],
            $code,
            $config['redirect_uri'],
        );

        return $this->tokens->persist(
            EbayConfiguration::CHANNEL,
            Scope::company($companyId),
            $payload,
            $config['scopes'],
            metadata: ['environment' => $config['environment']],
        );
    }

    public function accessToken(int $companyId): string
    {
        $scope = Scope::company($companyId);
        $token = $this->tokens->find(EbayConfiguration::CHANNEL, $scope);

        if (! $token instanceof OAuthToken || $token->refresh_token === null) {
            throw MarketplaceOperationException::missingConfiguration(EbayConfiguration::CHANNEL, 'marketplace.ebay.oauth_token');
        }

        if (! $token->isExpired() && $token->access_token !== null) {
            return $token->access_token;
        }

        $config = $this->configuration->requireConfigured($companyId);
        $payload = $this->oauth->refreshAccessToken(
            $config['token_url'],
            $config['client_id'],
            $config['client_secret'],
            $token->refresh_token,
            $config['scopes'],
        );

        return $this->tokens
            ->persist(EbayConfiguration::CHANNEL, $scope, $payload, $config['scopes'], metadata: ['environment' => $config['environment']])
            ->access_token;
    }

    public function tokenForCompany(int $companyId): ?OAuthToken
    {
        return $this->tokens->find(EbayConfiguration::CHANNEL, Scope::company($companyId));
    }
}
