<?php

namespace App\Modules\Commerce\Settings\Livewire;

use App\Base\Settings\Livewire\SettingsForm;
use App\Modules\Core\Geonames\Services\CurrencyOptions;

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
                $group['fields'][$index]['options'] = app(CurrencyOptions::class)->map();
            }
        }

        return $group;
    }
}
