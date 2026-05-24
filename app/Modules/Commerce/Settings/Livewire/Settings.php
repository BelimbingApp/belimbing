<?php

namespace App\Modules\Commerce\Settings\Livewire;

use App\Base\Settings\Livewire\SettingsForm;
use App\Modules\Core\Geonames\Models\Country;
use Illuminate\Support\Facades\Schema;

class Settings extends SettingsForm
{
    protected function group(): string
    {
        return 'commerce';
    }

    /**
     * @return array<string, mixed>
     */
    protected function groupConfig(): array
    {
        $group = parent::groupConfig();

        foreach ($group['fields'] ?? [] as $index => $field) {
            if (($field['key'] ?? null) === 'commerce.default_currency_code') {
                $group['fields'][$index]['options'] = $this->currencyOptions();
            }
        }

        return $group;
    }

    /**
     * @return array<string, string>
     */
    private function currencyOptions(): array
    {
        if (! Schema::hasTable('geonames_countries')) {
            return ['MYR' => 'Malaysian Ringgit (MYR)'];
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

        return $options !== []
            ? $options
            : ['MYR' => 'Malaysian Ringgit (MYR)'];
    }
}
