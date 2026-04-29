<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;

class EbayConfiguration
{
    public const CHANNEL = 'ebay';

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array{
     *     environment: string,
     *     marketplace_id: string,
     *     client_id: string|null,
     *     client_secret: string|null,
     *     redirect_uri: string|null,
     *     scopes: list<string>,
     *     api_base_url: string,
     *     auth_url: string,
     *     token_url: string
     * }
     */
    public function forCompany(int $companyId): array
    {
        $scope = Scope::company($companyId);
        $environment = (string) $this->settings->get('marketplace.ebay.environment', 'sandbox', $scope);

        return [
            'environment' => $environment,
            'marketplace_id' => strtoupper((string) $this->settings->get('marketplace.ebay.marketplace_id', 'EBAY_US', $scope)),
            'client_id' => $this->nullableSetting('marketplace.ebay.client_id', $scope),
            'client_secret' => $this->nullableSetting('marketplace.ebay.client_secret', $scope),
            'redirect_uri' => $this->nullableSetting('marketplace.ebay.redirect_uri', $scope),
            'scopes' => $this->scopes($scope),
            'api_base_url' => $environment === 'live' ? 'https://api.ebay.com' : 'https://api.sandbox.ebay.com',
            'auth_url' => $environment === 'live' ? 'https://auth.ebay.com/oauth2/authorize' : 'https://auth.sandbox.ebay.com/oauth2/authorize',
            'token_url' => $environment === 'live' ? 'https://api.ebay.com/identity/v1/oauth2/token' : 'https://api.sandbox.ebay.com/identity/v1/oauth2/token',
        ];
    }

    public function requireConfigured(int $companyId): array
    {
        $config = $this->forCompany($companyId);

        foreach (['client_id', 'client_secret', 'redirect_uri'] as $key) {
            if ($config[$key] === null || $config[$key] === '') {
                throw MarketplaceOperationException::missingConfiguration(self::CHANNEL, 'marketplace.ebay.'.$key);
            }
        }

        return $config;
    }

    private function nullableSetting(string $key, Scope $scope): ?string
    {
        $value = $this->settings->get($key, null, $scope);

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    /**
     * @return list<string>
     */
    private function scopes(Scope $scope): array
    {
        $raw = $this->settings->get('marketplace.ebay.scopes', '', $scope);
        $parts = is_array($raw)
            ? $raw
            : preg_split('/\s+/', trim((string) $raw));

        return collect($parts ?: [])
            ->map(fn (mixed $scope): string => trim((string) $scope))
            ->filter()
            ->values()
            ->all();
    }
}
