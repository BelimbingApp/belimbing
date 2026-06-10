<?php

namespace App\Modules\Core\Address\Http\Controllers;

use App\Modules\Core\Geonames\Concerns\HasGeonamesLookups;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CitySearchController
{
    use HasGeonamesLookups;

    /**
     * Search cities for combobox (JSON API, no Livewire — avoids DOM morph / focus loss).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $country = $request->query('country', '');
        $admin1 = $request->query('admin1', '');
        $query = $request->query('q', '');

        if ($country === '') {
            return response()->json([]);
        }

        // admin1 may arrive as prefixed code (e.g. "DE.01") — extract raw code for city lookup.
        $rawAdmin1 = null;
        if ($admin1 !== '') {
            $rawAdmin1 = str_contains($admin1, '.') ? substr($admin1, strpos($admin1, '.') + 1) : $admin1;
        }

        $results = $this->searchCitiesInCountry($country, $query, $rawAdmin1);

        return response()->json($results);
    }
}
