<?php

namespace App\Modules\Core\Address\Http\Controllers;

use App\Modules\Core\Geonames\Concerns\HasGeonamesLookups;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostcodeSearchController
{
    use HasGeonamesLookups;

    /**
     * Search postcodes for combobox (JSON API, no Livewire - avoids DOM morph / focus loss).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $country = $request->query('country', '');
        $query = $request->query('q', '');

        if ($country === '') {
            return response()->json([]);
        }

        $results = $this->searchPostcodesInCountry($country, $query);

        return response()->json($results);
    }
}
