<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Integration\Models\OAuthToken;
use App\Base\Integration\Services\OAuth2Client;
use App\Base\Integration\Services\OAuth2TokenRequestContext;
use App\Base\Integration\Services\OAuthTokenStore;
use App\Base\Settings\DTO\Scope;

class EbayApplicationTokenService
{
    public function __construct(
        private readonly EbayConfiguration $configuration,
        private readonly OAuth2Client $oauth,
        private readonly OAuthTokenStore $tokens,
    ) {}

    public function accessToken(int $companyId): string
    {
        $scope = Scope::company($companyId);
        $token = $this->tokens->find(
            EbayConfiguration::CHANNEL,
            $scope,
            EbayConfiguration::APPLICATION_TOKEN_ACCOUNT_KEY,
        );

        if ($token instanceof OAuthToken && ! $token->isExpired() && $token->access_token !== null) {
            return $token->access_token;
        }

        $config = $this->configuration->requireApplicationConfigured($companyId);
        $payload = $this->oauth->clientCredentialsToken(
            $config['token_url'],
            $config['client_id'],
            $config['client_secret'],
            EbayConfiguration::APPLICATION_SCOPES,
            new OAuth2TokenRequestContext(
                system: EbayConfiguration::CHANNEL,
                provider: EbayConfiguration::CHANNEL,
                ownerType: 'company',
                ownerId: $companyId,
                metadata: [
                    'environment' => $config['environment'],
                    'token_kind' => 'application',
                ],
            ),
        );

        return $this->tokens
            ->persist(
                EbayConfiguration::CHANNEL,
                $scope,
                $payload,
                EbayConfiguration::APPLICATION_SCOPES,
                EbayConfiguration::APPLICATION_TOKEN_ACCOUNT_KEY,
                metadata: [
                    'environment' => $config['environment'],
                    'token_kind' => 'application',
                ],
            )
            ->access_token;
    }
}
