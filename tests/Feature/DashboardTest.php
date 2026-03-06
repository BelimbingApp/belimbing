<?php

use App\Modules\Core\User\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertStatus(200);
});

test('authenticated users can access Lara chat entry points from dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertStatus(200)
        ->assertSee('open-lara-chat', false)
        ->assertSee('Ctrl+K')
        ->assertSee('close-lara-chat', false);
});
