<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Services;

use App\Base\Locale\Contracts\LicenseeLocaleBootstrapSource as LicenseeLocaleBootstrapSourceContract;
use App\Base\Locale\DTO\LicenseeLocaleBootstrap;
use App\Modules\Core\Company\Models\Company;

class LicenseeLocaleBootstrapSource implements LicenseeLocaleBootstrapSourceContract
{
    public function resolve(): ?LicenseeLocaleBootstrap
    {
        try {
            $licensee = Company::query()
                ->with(['addresses.country'])
                ->find(Company::LICENSEE_ID);

            $address = $licensee?->primaryAddress();
            $countryIso = strtoupper((string) ($address?->country_iso ?? ''));

            if ($countryIso === '') {
                return null;
            }

            return new LicenseeLocaleBootstrap(
                countryIso: $countryIso,
                countryName: $address?->country?->country,
                languages: $address?->country?->languages,
                currencyCode: $address?->country?->currency_code,
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
