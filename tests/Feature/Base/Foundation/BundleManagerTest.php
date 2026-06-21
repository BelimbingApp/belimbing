<?php

use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

const BUNDLE_MANAGER_TEST_VERSION = '1.0.0';
const BUNDLE_MANAGER_EXTENSION_ROOT = 'extensions/';
const BUNDLE_MANAGER_COMPOSER_JSON = '/composer.json';
const BUNDLE_MANAGER_REQUIRED_SUFFIX = '/required';
const BUNDLE_MANAGER_DEPENDENT_SUFFIX = '/dependent';

beforeEach(function (): void {
    setupAuthzRoles();
})->skip(fn (): bool => ! is_dir(app_path('Modules/People')), 'People domain not installed');

test('admin sees the bundle manager page with installed module cards', function (): void {
    $this->actingAs(createAdminUser());

    $response = $this->get(route('admin.system.software.bundles.index'));

    $response->assertOk()
        ->assertSee('Bundles')
        ->assertSee('people/attendance')
        ->assertSee('people/payroll')
        ->assertSee('people/leave')
        ->assertSee('people/claim');
})->skip(fn (): bool => ! is_dir(app_path('Modules/People')), 'People domain not installed');

test('the dashboard treats conventional Core module paths as installed dependencies', function (): void {
    $this->actingAs(createAdminUser());

    $response = $this->get(route('admin.system.software.bundles.index'));

    $response->assertOk()
        ->assertSee('All required module dependencies are satisfied.')
        ->assertDontSee('Module dependency issues');
});

test('the dashboard reports incompatible required dependency versions', function (): void {
    $owner = 'zz-bundle-manager-'.bin2hex(random_bytes(4));
    $required = base_path(BUNDLE_MANAGER_EXTENSION_ROOT.$owner.BUNDLE_MANAGER_REQUIRED_SUFFIX);
    $dependent = base_path(BUNDLE_MANAGER_EXTENSION_ROOT.$owner.BUNDLE_MANAGER_DEPENDENT_SUFFIX);

    foreach ([$required, $dependent] as $module) {
        File::ensureDirectoryExists($module);
    }

    file_put_contents($required.BUNDLE_MANAGER_COMPOSER_JSON, json_encode([
        'name' => $owner.BUNDLE_MANAGER_REQUIRED_SUFFIX,
        'extra' => ['blb' => ['module' => $owner.BUNDLE_MANAGER_REQUIRED_SUFFIX, 'version' => BUNDLE_MANAGER_TEST_VERSION]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    file_put_contents($dependent.BUNDLE_MANAGER_COMPOSER_JSON, json_encode([
        'name' => $owner.BUNDLE_MANAGER_DEPENDENT_SUFFIX,
        'extra' => ['blb' => [
            'module' => $owner.BUNDLE_MANAGER_DEPENDENT_SUFFIX,
            'version' => BUNDLE_MANAGER_TEST_VERSION,
            'requires-modules' => [$owner.BUNDLE_MANAGER_REQUIRED_SUFFIX => '^2.0.0'],
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    try {
        $this->actingAs(createAdminUser());

        $response = $this->get(route('admin.system.software.bundles.index'));

        $response->assertOk()
            ->assertSee('Module dependency issues')
            ->assertSee($owner.BUNDLE_MANAGER_DEPENDENT_SUFFIX)
            ->assertSee($owner.BUNDLE_MANAGER_REQUIRED_SUFFIX)
            ->assertSee('^2.0.0')
            ->assertSee(BUNDLE_MANAGER_TEST_VERSION);
    } finally {
        File::deleteDirectory(base_path(BUNDLE_MANAGER_EXTENSION_ROOT.$owner));
    }
});

test('the bundle manager requires the system.bundles.view capability', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->get(route('admin.system.software.bundles.index'));

    $response->assertForbidden();
});
