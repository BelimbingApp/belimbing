<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Address\Http\Controllers;

use App\Base\Htmx\HtmxResponse;
use App\Modules\Core\Address\Concerns\HasAddressGeoLookups;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class GeoLookupController
{
    use HasAddressGeoLookups;

    /**
     * Return admin1 (state/province) <option> fragment for a country.
     */
    public function admin1Options(Request $request): View
    {
        $countryIso = strtoupper($request->string('country_iso', '')->toString());
        $selected = $request->string('admin1_code', '')->toString();

        $options = $countryIso !== '' ? $this->loadAdmin1ForCountry($countryIso) : [];

        return view('partials.address.admin1-options', [
            'options' => $options,
            'selected' => $selected !== '' ? $selected : null,
        ]);
    }

    /**
     * Return locality <option> fragment for a postcode lookup.
     */
    public function localityOptions(Request $request): Response
    {
        $countryIso = strtoupper($request->string('country_iso', '')->toString());
        $postcode = trim($request->string('postcode', '')->toString());

        $localities = [];
        $admin1Code = null;

        if ($countryIso !== '' && $postcode !== '') {
            $this->ensurePostcodesImported($countryIso);
            $result = $this->lookupLocalitiesByPostcode($countryIso, $postcode);

            if ($result !== null) {
                $localities = $result['localities'];
                $admin1Code = $result['admin1_code'];
            }
        }

        $response = response(view('partials.address.locality-options', [
            'localities' => $localities,
        ])->render());

        if ($admin1Code !== null) {
            (new HtmxResponse)
                ->trigger(['geo:admin1-detected' => $admin1Code])
                ->applyTo($response);
        }

        return $response;
    }
}
