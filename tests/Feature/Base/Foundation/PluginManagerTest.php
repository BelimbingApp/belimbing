<?php

use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setupAuthzRoles();
})->skip(fn (): bool => ! is_dir(app_path('Modules/People')), 'People domain not installed');

test('admin sees the plugin manager page with installed module cards', function (): void {
    $this->actingAs(createAdminUser());

    $response = $this->get(route('admin.system.plugins.index'));

    $response->assertOk()
        ->assertSee('Plugin manager')
        ->assertSee('people/attendance')
        ->assertSee('people/payroll')
        ->assertSee('people/leave')
        ->assertSee('people/claim');
})->skip(fn (): bool => ! is_dir(app_path('Modules/People')), 'People domain not installed');

test('the dashboard reports unmet required dependencies when Core has no manifest', function (): void {
    // Today Core/Employee and Core/Company do not ship composer.json
    // with extra.blb. People sub-modules declare them as required,
    // so the dependency-health banner correctly flags them. When Core
    // manifests are added, this test should flip to assert the
    // satisfied banner instead.
    $this->actingAs(createAdminUser());

    $response = $this->get(route('admin.system.plugins.index'));

    $response->assertOk()
        ->assertSee('Unmet required dependencies')
        ->assertSee('core/employee')
        ->assertSee('core/company');
});

test('the plugin manager requires the system.plugins.view capability', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->get(route('admin.system.plugins.index'));

    $response->assertForbidden();
});
