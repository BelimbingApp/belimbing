<?php
namespace App\Modules\Core\Address\Http\Controllers;

use App\Modules\Core\Address\Concerns\HasAddressGeoLookups;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CountrySearchController
{
    use HasAddressGeoLookups;

    /**
     * Search countries for the combobox (JSON API, no Livewire — keeps the
     * ~250-country list out of the page's initial HTML).
     */
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(
            $this->searchCountriesForCombobox($request->query('q', '')),
        );
    }
}
