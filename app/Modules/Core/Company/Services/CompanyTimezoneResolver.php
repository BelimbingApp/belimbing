<?php

namespace App\Modules\Core\Company\Services;

use App\Base\DateTime\Services\TimezoneSettings;
use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Geonames\Models\City;

/**
 * Resolve and decide timezone updates for a company based on address locality.
 *
 * Returns a decision array describing what action to take:
 *   - ['action' => 'auto-save', 'timezone' => '...']  when company has no timezone set
 *   - ['action' => 'prompt', 'timezone' => '...', 'current' => '...']  when timezone differs
 *   - null  when no action is needed
 */
final class CompanyTimezoneResolver
{
    public function __construct(
        private readonly TimezoneSettings $timezoneSettings,
    ) {}

    /**
     * Resolve the IANA timezone for an address from Geonames city data.
     *
     * Matches the address locality against the city name or ASCII name,
     * preferring the most populated city when multiple matches exist.
     */
    public function resolve(Address $address): ?string
    {
        if (! $address->country_iso || ! $address->locality) {
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
     * Decide what timezone action to take for a company after an address geo change.
     *
     * Only acts when the changed address is the company's primary address.
     *
     * @return array{action: string, timezone: string, current?: string}|null
     */
    public function decide(Company $company, Address $address): ?array
    {
        $primary = $company->primaryAddress();

        if (! $primary || ! $primary->is($address)) {
            return null;
        }

        $suggested = $this->resolve($address);

        if (! $suggested) {
            return null;
        }

        $current = $this->timezoneSettings->explicitCompanyTimezone((int) $company->id);

        if ($current === null) {
            return ['action' => 'auto-save', 'timezone' => $suggested];
        }

        if ($current !== $suggested) {
            return ['action' => 'prompt', 'timezone' => $suggested, 'current' => $current];
        }

        return null;
    }

    /**
     * Persist a timezone as the company default.
     */
    public function apply(Company $company, string $timezone): void
    {
        $this->timezoneSettings->setCompanyTimezone((int) $company->id, $timezone);
    }
}
