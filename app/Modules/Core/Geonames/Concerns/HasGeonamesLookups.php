<?php

namespace App\Modules\Core\Geonames\Concerns;

use App\Modules\Core\Geonames\Jobs\ImportPostcodes;
use App\Modules\Core\Geonames\Models\Admin1;
use App\Modules\Core\Geonames\Models\City;
use App\Modules\Core\Geonames\Models\Country;
use App\Modules\Core\Geonames\Models\Postcode;
use Illuminate\Support\Str;

/**
 * Shared GeoNames lookups for country / state / postcode / city pickers.
 *
 * This is generic geo-data access (it only touches the GeoNames models), so it
 * lives in the GeoNames module and is reused across domains — address forms,
 * company profiles, and marketplace seller locations all consume it. Kept as a
 * trait because the consumers are Livewire components and `__invoke`
 * controllers that cannot constructor-inject a service cleanly.
 */
trait HasGeonamesLookups
{
    private const POSTCODE_SEARCH_LIMIT = 10;

    private const CITY_SEARCH_LIMIT = 15;

    /**
     * Search countries by name for the combobox (server-side, JSON-API friendly).
     *
     * Mirrors {@see searchPostcodesInCountry()} so the country field can use the
     * combobox's `searchUrl` mode instead of embedding all ~250 options (and
     * their per-option Alpine markup) into the initial page HTML.
     *
     * @param  string  $query  Search query (empty returns the full list)
     * @return array<int, array{value: string, label: string}>
     */
    public function searchCountriesForCombobox(string $query): array
    {
        $q = trim($query);

        $builder = Country::query()->select(['iso', 'country']);

        if ($q !== '') {
            // Portable case-insensitive contains-match: works on both SQLite
            // (dev/test) and PostgreSQL (production) without relying on ilike.
            $pattern = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($q)).'%';
            $builder->whereRaw('LOWER(country) LIKE ?', [$pattern]);
        }

        return $builder
            ->orderBy('country')
            ->get()
            ->map(fn (Country $c) => ['value' => $c->iso, 'label' => $c->country])
            ->values()
            ->all();
    }

    /**
     * Load admin1 (state/province) options for a country.
     *
     * Returns an array suitable for the x-ui.combobox component. By default the
     * option value is the full GeoNames admin1 code (e.g. "US.CA"), which is
     * what address records store. Pass $subdivisionCode to get just the
     * subdivision part (e.g. "CA") for systems that want the bare state code,
     * such as eBay's `stateOrProvince` field.
     *
     * @param  string  $countryIso  Two-letter ISO country code
     * @return array<int, array{value: string, label: string}>
     */
    public function loadAdmin1ForCountry(string $countryIso, bool $subdivisionCode = false): array
    {
        $iso = strtoupper($countryIso);

        $options = Admin1::query()
            ->forCountry($iso)
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn (Admin1 $a) => [
                'value' => $subdivisionCode ? Str::afterLast((string) $a->code, '.') : $a->code,
                'label' => $a->name,
            ])
            ->values()
            ->all();

        if (! empty($options)) {
            return $options;
        }

        // Fallback when Admin1 seed data is missing: derive options from imported postcodes.
        // Only include codes that exist in geonames_admin1 to avoid FK violations.
        return Postcode::query()
            ->where('country_iso', $iso)
            ->whereNotNull('admin1Code')
            ->select('admin1Code')
            ->distinct()
            ->orderBy('admin1Code')
            ->get()
            ->map(function (Postcode $postcode) use ($iso, $subdivisionCode): ?array {
                $rawCode = (string) $postcode->admin1Code;
                $code = $iso.'.'.$rawCode;
                if (! Admin1::query()->where('code', $code)->exists()) {
                    return null;
                }

                return [
                    'value' => $subdivisionCode ? $rawCode : $code,
                    'label' => $rawCode,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Load postcode options for a country (for editable combobox).
     *
     * Returns an array suitable for the x-ui.combobox component.
     * Limited to 1000 postcodes per country for performance.
     *
     * @param  string  $countryIso  Two-letter ISO country code
     * @return array<int, array{value: string, label: string}>
     */
    public function loadPostcodesForCountry(string $countryIso): array
    {
        $iso = strtoupper($countryIso);

        return Postcode::query()
            ->where('country_iso', $iso)
            ->select('postcode')
            ->distinct()
            ->orderBy('postcode')
            ->limit(1000)
            ->get()
            ->map(fn (Postcode $p) => [
                'value' => (string) $p->postcode,
                'label' => (string) $p->postcode,
            ])
            ->values()
            ->all();
    }

    /**
     * Search postcodes by query (for editable combobox with server-side search).
     *
     * Returns matching postcodes. No limit on total postcodes per country.
     *
     * @param  string  $countryIso  Two-letter ISO country code
     * @param  string  $query  Search query (empty returns first postcodes up to limit)
     * @return array<int, array{value: string, label: string}>
     */
    public function searchPostcodesInCountry(string $countryIso, string $query): array
    {
        $iso = strtoupper($countryIso);
        $q = trim($query);

        $query = Postcode::query()
            ->where('country_iso', $iso)
            ->select('postcode')
            ->distinct();

        if ($q !== '') {
            $pattern = str_replace(['%', '_'], ['\\%', '\\_'], $q);
            $query->where('postcode', 'ilike', $pattern.'%');
        }

        return $query
            ->orderBy('postcode')
            ->limit(self::POSTCODE_SEARCH_LIMIT)
            ->get()
            ->map(fn (Postcode $p) => [
                'value' => (string) $p->postcode,
                'label' => (string) $p->postcode,
            ])
            ->values()
            ->all();
    }

    /**
     * Search cities by name for a country, optionally filtered by admin1 code.
     *
     * Searches name, ascii_name, and alternate_names. Results ordered by population
     * descending so the most relevant cities appear first.
     *
     * @param  string  $countryIso  Two-letter ISO country code
     * @param  string  $query  Search query (empty returns largest cities up to limit)
     * @param  string|null  $admin1Code  Optional admin1 code to narrow results (raw code, not prefixed)
     * @return array<int, array{value: string, label: string}>
     */
    public function searchCitiesInCountry(string $countryIso, string $query, ?string $admin1Code = null): array
    {
        $iso = strtoupper($countryIso);
        $q = trim($query);

        $builder = City::query()
            ->where('country_iso', $iso);

        if ($admin1Code !== null && $admin1Code !== '') {
            $builder->where('admin1_code', $admin1Code);
        }

        if ($q !== '') {
            $pattern = str_replace(['%', '_'], ['\\%', '\\_'], $q);
            $builder->where(function ($sub) use ($pattern) {
                $sub->where('name', 'ilike', $pattern.'%')
                    ->orWhere('ascii_name', 'ilike', $pattern.'%')
                    ->orWhere('alternate_names', 'ilike', '%'.$pattern.'%');
            });
        }

        return $builder
            ->orderByDesc('population')
            ->limit(self::CITY_SEARCH_LIMIT)
            ->get(['name', 'admin1_code', 'population'])
            ->map(fn (City $c) => [
                'value' => $c->name,
                'label' => $c->name,
            ])
            ->unique('value')
            ->values()
            ->all();
    }

    /**
     * Look up a postcode and return the matching locality and admin1 code.
     *
     * @param  string  $countryIso  Two-letter ISO country code
     * @param  string  $postcode  Postal code to look up
     * @return array{locality: string, admin1Code: string|null}|null
     */
    public function lookupPostcode(string $countryIso, string $postcode): ?array
    {
        $result = $this->lookupLocalitiesByPostcode($countryIso, $postcode);

        if (! $result || empty($result['localities'])) {
            return null;
        }

        return [
            'locality' => $result['localities'][0]['value'],
            'admin1Code' => $result['admin1Code'],
        ];
    }

    /**
     * Look up a postcode and return all matching localities (for editable combobox).
     *
     * @param  string  $countryIso  Two-letter ISO country code
     * @param  string  $postcode  Postal code to look up
     * @return array{localities: array<int, array{value: string, label: string}>, admin1Code: string|null}|null
     */
    public function lookupLocalitiesByPostcode(string $countryIso, string $postcode): ?array
    {
        $iso = strtoupper($countryIso);

        $results = Postcode::query()
            ->where('country_iso', $iso)
            ->where('postcode', $postcode)
            ->get(['place_name', 'admin1Code']);

        if ($results->isEmpty()) {
            return null;
        }

        $seen = [];
        $localities = [];

        foreach ($results as $row) {
            $name = $row->place_name;

            if ($name === null || $name === '' || isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;
            $localities[] = ['value' => $name, 'label' => $name];
        }

        if (empty($localities)) {
            return null;
        }

        $first = $results->first();
        $admin1Code = null;
        if ($first->admin1Code) {
            $candidate = $iso.'.'.$first->admin1Code;
            if (Admin1::query()->where('code', $candidate)->exists()) {
                $admin1Code = $candidate;
            }
        }

        return [
            'localities' => $localities,
            'admin1Code' => $admin1Code,
        ];
    }

    /**
     * Dispatch a postcode import job if data is missing for the country.
     *
     * @param  string  $countryIso  Two-letter ISO country code (uppercase)
     */
    protected function ensurePostcodesImported(string $countryIso): void
    {
        if (Postcode::query()->where('country_iso', $countryIso)->exists()) {
            return;
        }

        ImportPostcodes::dispatch([$countryIso])
            ->onQueue(ImportPostcodes::QUEUE);
        ImportPostcodes::runWorkerOnce();

        if (Postcode::query()->where('country_iso', $countryIso)->exists()) {
            return;
        }

        // Fallback path for environments where queue worker-once does not execute.
        ImportPostcodes::dispatchSync([$countryIso]);
    }
}
