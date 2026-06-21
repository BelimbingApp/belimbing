<?php

use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

const PLUGIN_MANAGER_TEST_VERSION = '1.0.0';
const PLUGIN_MANAGER_EXTENSION_ROOT = 'extensions/';
const PLUGIN_MANAGER_COMPOSER_JSON = '/composer.json';
const REQUIRED_PLUGIN_SUFFIX = '/required';
const DEPENDENT_PLUGIN_SUFFIX = '/dependent';

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
    $required = base_path(PLUGIN_MANAGER_EXTENSION_ROOT.$owner.REQUIRED_PLUGIN_SUFFIX);
    $dependent = base_path(PLUGIN_MANAGER_EXTENSION_ROOT.$owner.DEPENDENT_PLUGIN_SUFFIX);

    foreach ([$required, $dependent] as $module) {
        File::ensureDirectoryExists($module);
    }

    file_put_contents($required.PLUGIN_MANAGER_COMPOSER_JSON, json_encode([
        'name' => $owner.REQUIRED_PLUGIN_SUFFIX,
        'extra' => ['blb' => ['module' => $owner.REQUIRED_PLUGIN_SUFFIX, 'role' => 'source', 'version' => PLUGIN_MANAGER_TEST_VERSION]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    file_put_contents($dependent.PLUGIN_MANAGER_COMPOSER_JSON, json_encode([
        'name' => $owner.DEPENDENT_PLUGIN_SUFFIX,
        'extra' => ['blb' => [
            'module' => $owner.DEPENDENT_PLUGIN_SUFFIX,
            'role' => 'plugin',
            'version' => PLUGIN_MANAGER_TEST_VERSION,
            'requires-modules' => [$owner.REQUIRED_PLUGIN_SUFFIX => '^2.0.0'],
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    try {
        $this->actingAs(createAdminUser());

        $response = $this->get(route('admin.system.plugins.index'));

        $response->assertOk()
            ->assertSee('Module dependency issues')
            ->assertSee($owner.DEPENDENT_PLUGIN_SUFFIX)
            ->assertSee($owner.REQUIRED_PLUGIN_SUFFIX)
            ->assertSee('^2.0.0')
            ->assertSee(PLUGIN_MANAGER_TEST_VERSION);
    } finally {
        File::deleteDirectory(base_path(PLUGIN_MANAGER_EXTENSION_ROOT.$owner));
    }
});

test('the plugin manager requires the system.plugins.view capability', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->get(route('admin.system.plugins.index'));

    $response->assertForbidden();
});
