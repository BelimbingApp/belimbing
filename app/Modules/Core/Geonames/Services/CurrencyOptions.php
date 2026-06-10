<?php

namespace App\Modules\Core\Geonames\Services;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Geonames\Models\Country;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for currency select/combobox options, derived from the
 * GeoNames country data (currency_code + currency_name), de-duplicated by code.
 * Used by every currency input so the list never drifts between screens.
 */
class CurrencyOptions
{
    private const array FALLBACK_PRIORITY_CODES = ['USD', 'EUR'];

    /**
     * Currency code => human label, e.g. ['USD' => 'United States Dollar (USD)'].
     *
     * @return array<string, string>
     */
    public function map(): array
    {
        if (! Schema::hasTable('geonames_countries')) {
            return $this->fallback();
        }

        $options = Country::query()
            ->whereNotNull('currency_code')
            ->where('currency_code', '!=', '')
            ->selectRaw('upper(currency_code) as currency_code, min(currency_name) as currency_name')
            ->groupByRaw('upper(currency_code)')
            ->orderBy('currency_code')
            ->pluck('currency_name', 'currency_code')
            ->mapWithKeys(fn (?string $name, string $code): array => [
                $code => $name !== null && $name !== '' ? $name.' ('.$code.')' : $code,
            ])
            ->all();

        return $options !== [] ? $options : $this->fallback();
    }

    /**
     * Combobox-ready option list: [['value' => 'USD', 'label' => '...'], ...].
     *
     * @return list<array{value: string, label: string}>
     */
    public function options(): array
    {
        return collect($this->orderedMap($this->map()))
            ->map(fn (string $label, string $code): array => ['value' => $code, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $map
     * @return array<string, string>
     */
    private function orderedMap(array $map): array
    {
        $ordered = [];
        $remaining = $map;

        foreach ($this->preferredCodes() as $code) {
            if (! array_key_exists($code, $remaining)) {
                continue;
            }

            $ordered[$code] = $remaining[$code];
            unset($remaining[$code]);
        }

        return [...$ordered, ...$remaining];
    }

    /**
     * @return list<string>
     */
    private function preferredCodes(): array
    {
        $companyCurrencyCode = $this->currentCompanyCurrencyCode();

        return array_values(array_unique(array_filter([
            $companyCurrencyCode,
            ...self::FALLBACK_PRIORITY_CODES,
        ], fn (mixed $code): bool => is_string($code) && $code !== '')));
    }

    private function currentCompanyCurrencyCode(): ?string
    {
        $companyId = Auth::user()?->company_id;

        if (! is_int($companyId) && ! ctype_digit((string) $companyId)) {
            return null;
        }

        try {
            $company = Company::query()->find((int) $companyId);
            $currencyCode = strtoupper((string) ($company?->primaryAddress()?->country?->currency_code ?? ''));

            return $currencyCode !== '' ? $currencyCode : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function fallback(): array
    {
        return ['MYR' => 'Malaysian Ringgit (MYR)'];
    }
}
