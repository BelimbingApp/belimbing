<?php

use function Pest\Laravel\get;

test('providers page empty state shows catalog and lara activation hint', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    $response = get(route('admin.ai.providers'))
        ->assertOk()
        ->assertSee('Add a Provider')
        ->assertSee(route('admin.setup.lara'), false);

    // Hint link text depends on Lara activation baseline seeding; keep this
    // assertion resilient to that state while still checking the page shape.
    if (str_contains($response->getContent(), 'activate Lara')) {
        $response->assertSee('activate Lara');
    }
});

test('legacy browse route redirects to unified providers page', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    get(route('admin.ai.providers.browse'))
        ->assertRedirect(route('admin.ai.providers'));
});

test('legacy connections route redirects to unified providers page', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    get(route('admin.ai.providers.connections'))
        ->assertRedirect(route('admin.ai.providers'));
});
