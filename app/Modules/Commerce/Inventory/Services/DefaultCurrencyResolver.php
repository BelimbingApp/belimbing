<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Inventory\Services;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;

class DefaultCurrencyResolver
{
    public const string SETTINGS_KEY = 'commerce.default_currency_code';

    public const string FALLBACK = 'MYR';

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function forCompany(?int $companyId): string
    {
        $scope = $companyId === null ? null : Scope::company($companyId);
        $currencyCode = $this->settings->get(self::SETTINGS_KEY, self::FALLBACK, $scope);

        if (! is_string($currencyCode)) {
            return self::FALLBACK;
        }

        $currencyCode = strtoupper(trim($currencyCode));

        return preg_match('/^[A-Z]{3}$/', $currencyCode) ? $currencyCode : self::FALLBACK;
    }
}
