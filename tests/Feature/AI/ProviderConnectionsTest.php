<?php

use function Pest\Laravel\get;

test('providers page empty state shows catalog and lara activation hint', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    get(route('admin.ai.providers'))
        ->assertOk()
        ->assertSee('Add a Provider')
        ->assertSee('activate Lara')
        ->assertSee(route('admin.setup.lara'), false);
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
