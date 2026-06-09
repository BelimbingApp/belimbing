<?php

namespace App\Modules\Core\Geonames\Services;

use App\Modules\Core\Geonames\Models\Country;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for currency select/combobox options, derived from the
 * GeoNames country data (currency_code + currency_name), de-duplicated by code.
 * Used by every currency input so the list never drifts between screens.
 */
class CurrencyOptions
{
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
        return collect($this->map())
            ->map(fn (string $label, string $code): array => ['value' => $code, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function fallback(): array
    {
        return ['MYR' => 'Malaysian Ringgit (MYR)'];
    }
}
