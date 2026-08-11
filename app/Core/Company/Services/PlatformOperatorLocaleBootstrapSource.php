<?php

namespace App\Core\Company\Services;

use App\Base\Locale\Contracts\PlatformOperatorLocaleBootstrapSource as PlatformOperatorLocaleBootstrapSourceContract;
use App\Base\Locale\DTO\PlatformOperatorLocaleBootstrap;
use App\Base\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Schema;

class PlatformOperatorLocaleBootstrapSource implements PlatformOperatorLocaleBootstrapSourceContract
{
    public function __construct(private readonly PrimaryCompanyManager $primaryCompanies) {}

    public function resolve(): ?PlatformOperatorLocaleBootstrap
    {
        if (! Schema::hasTable('tenants')
            || ! Schema::hasTable('companies')
            || ! Schema::hasTable('tenant_primary_companies')) {
            return null;
        }

        $operator = Tenant::platformOperator();
        $company = $operator === null ? null : $this->primaryCompanies->findForTenant($operator);
        $address = $company?->primaryAddress();
        $countryIso = strtoupper((string) ($address?->country_iso ?? ''));

        if ($countryIso === '') {
            return null;
        }

        return new PlatformOperatorLocaleBootstrap(
            countryIso: $countryIso,
            countryName: $address?->country?->country,
            languages: $address?->country?->languages,
            currencyCode: $address?->country?->currency_code,
        );
    }
}
