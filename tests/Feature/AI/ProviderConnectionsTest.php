<?php

use function Pest\Laravel\get;

test('legacy provider routes redirect to the unified providers page', function (string $legacyRoute): void {
    $user = createAdminUser();

    $this->actingAs($user);

    get(route($legacyRoute))
        ->assertRedirect(route('admin.ai.providers'));
})->with([
    'browse route' => ['admin.ai.providers.browse'],
    'connections route' => ['admin.ai.providers.connections'],
]);
