<?php

use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

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

test('the dashboard treats conventional Core module paths as installed dependencies', function (): void {
    $this->actingAs(createAdminUser());

    $response = $this->get(route('admin.system.plugins.index'));

    $response->assertOk()
        ->assertSee('All required module dependencies are satisfied.')
        ->assertDontSee('Module dependency issues');
});

test('the dashboard reports incompatible required dependency versions', function (): void {
    $owner = 'zz-plugin-manager-'.bin2hex(random_bytes(4));
    $required = base_path('extensions/'.$owner.'/required');
    $dependent = base_path('extensions/'.$owner.'/dependent');

    foreach ([$required, $dependent] as $module) {
        File::ensureDirectoryExists($module);
    }

    file_put_contents($required.'/composer.json', json_encode([
        'name' => $owner.'/required',
        'extra' => ['blb' => ['module' => $owner.'/required', 'role' => 'source', 'version' => '1.0.0']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    file_put_contents($dependent.'/composer.json', json_encode([
        'name' => $owner.'/dependent',
        'extra' => ['blb' => [
            'module' => $owner.'/dependent',
            'role' => 'plugin',
            'version' => '1.0.0',
            'requires-modules' => [$owner.'/required' => '^2.0.0'],
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    try {
        $this->actingAs(createAdminUser());

        $response = $this->get(route('admin.system.plugins.index'));

        $response->assertOk()
            ->assertSee('Module dependency issues')
            ->assertSee($owner.'/dependent')
            ->assertSee($owner.'/required')
            ->assertSee('^2.0.0')
            ->assertSee('1.0.0');
    } finally {
        File::deleteDirectory(base_path('extensions/'.$owner));
    }
});

test('the plugin manager requires the system.plugins.view capability', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->get(route('admin.system.plugins.index'));

    $response->assertForbidden();
});
