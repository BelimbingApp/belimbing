<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Livewire\Concerns;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Core\Geonames\Models\City;
use DateTimeZone;

trait ManagesCompanyTimezone
{
    public string $companyTimezone = '';

    /**
     * Persist the selected IANA timezone as the company default.
     *
     * Validates against PHP's known timezone identifiers.
     * Clearing the value removes the setting, falling back to UTC.
     *
     * @param  string  $value  The IANA timezone identifier
     */
    public function updatedCompanyTimezone(string $value): void
    {
        $tz = trim($value);

        if ($tz !== '' && ! in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            return;
        }

        $settings = app(SettingsService::class);
        $scope = Scope::company($this->company->id);

        if ($tz === '') {
            $settings->forget('ui.timezone.default', $scope);
            $this->dispatch('timezone-saved', timezone: '');
        } else {
            $settings->set('ui.timezone.default', $tz, $scope);
            $this->dispatch('timezone-saved', timezone: $tz);
        }
    }

    /**
     * Resolve timezone from Geonames city data for the primary address.
     *
     * Returns the IANA timezone only when an exact city name match exists.
     */
    protected function resolveTimezoneFromAddress(): ?string
    {
        $address = $this->company->primaryAddress();

        if (! $address || ! $address->country_iso || ! $address->locality) {
            return null;
        }

        $city = City::query()
            ->where('country_iso', $address->country_iso)
            ->where(function ($q) use ($address): void {
                $q->where('name', $address->locality)
                    ->orWhere('ascii_name', $address->locality);
            })
            ->orderByDesc('population')
            ->first();

        return $city?->timezone;
    }

    /**
     * Auto-save timezone when it can be resolved from the primary address.
     *
     * Called after address create/update to keep the company timezone in sync.
     */
    protected function autoSaveTimezoneFromAddress(): void
    {
        $tz = $this->resolveTimezoneFromAddress();

        if (! $tz) {
            return;
        }

        $settings = app(SettingsService::class);
        $scope = Scope::company($this->company->id);
        $settings->set('ui.timezone.default', $tz, $scope);
        $this->companyTimezone = $tz;
    }
}
