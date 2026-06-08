<?php

namespace App\Modules\Core\Address\Http\Controllers;

use App\Modules\Core\Geonames\Concerns\HasGeonamesLookups;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CountrySearchController
{
    use HasGeonamesLookups;

    /**
     * Search countries for the combobox (JSON API, no Livewire — keeps the
     * ~250-country list out of the page's initial HTML).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $options = $this->searchCountriesForCombobox($request->query('q', ''));

        // Filter mode: callers pass `all=<label>` to prepend an empty
        // "All countries" option so the field can model a nullable filter.
        $allLabel = $request->query('all');
        if (is_string($allLabel) && $allLabel !== '' && trim((string) $request->query('q', '')) === '') {
            array_unshift($options, ['value' => '', 'label' => $allLabel]);
        }

        return response()->json($options);
    }
}
