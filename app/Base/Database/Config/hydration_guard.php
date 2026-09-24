<?php

/*
|--------------------------------------------------------------------------
| Hydration guard
|--------------------------------------------------------------------------
|
| Counts the Eloquent models each HTTP request, Livewire update, queued job,
| and console command hydrates. Crossing the limit throws in local/testing
| (an unbounded load fails its test) and logs one structured warning per unit
| of work everywhere else, never failing a production request. The mode follows
| the environment and is not configurable.
| Owner: App\Base\Database\Services\HydrationGuard.
| Architecture: docs/architecture/query-bounds.md.
|
*/

return [
    // Models hydrated per unit of work before the guard reacts.
    'limit' => (int) env('HYDRATION_GUARD_LIMIT', 5000),
];
