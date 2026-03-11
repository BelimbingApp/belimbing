<?php

use function Pest\Laravel\get;

test('provider connections empty state shows browse CTA and lara activation hint', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    get(route('admin.ai.providers.connections'))
        ->assertOk()
        ->assertSee('Browse AI Providers')
        ->assertSee('activate Lara')
        ->assertSee(route('admin.setup.lara'), false);
});
