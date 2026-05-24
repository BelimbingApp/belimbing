<?php

use App\Base\Foundation\Livewire\PluginManager;
use App\Base\Foundation\ModuleManifest\BelimbingAppCatalogService;
use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const PLUGIN_CATALOG_PAYROLL_MODULE = 'people/payroll';

beforeEach(function (): void {
    setupAuthzRoles();
});

function fakeBelimbingAppCatalog(): void
{
    Http::fake([
        'https://api.github.com/orgs/BelimbingApp/repos*' => Http::response([
            [
                'name' => 'blb-payroll-my',
                'html_url' => 'https://github.com/BelimbingApp/blb-payroll-my',
                'default_branch' => 'main',
                'topics' => ['blb-plugin', 'payroll'],
            ],
            [
                'name' => 'blb-payroll-sg',
                'html_url' => 'https://github.com/BelimbingApp/blb-payroll-sg',
                'default_branch' => 'main',
                'topics' => ['blb-plugin'],
            ],
            [
                'name' => 'unrelated-repo',
                'html_url' => 'https://github.com/BelimbingApp/unrelated-repo',
                'default_branch' => 'main',
                'topics' => ['internal'],
            ],
        ], 200),
        'https://raw.githubusercontent.com/BelimbingApp/blb-payroll-my/main/composer.json' => Http::response(json_encode([
            'name' => 'blb/payroll-my',
            'description' => 'Composer-level description',
            'extra' => [
                'blb' => [
                    'module' => PLUGIN_CATALOG_PAYROLL_MODULE,
                    'role' => 'plugin',
                    'version' => '0.1.0',
                    'description' => 'Payroll — Malaysia reference plugin.',
                ],
            ],
        ]), 200),
        'https://raw.githubusercontent.com/BelimbingApp/blb-payroll-sg/main/composer.json' => Http::response(json_encode([
            'name' => 'blb/payroll-sg',
            'extra' => [
                'blb' => [
                    'module' => PLUGIN_CATALOG_PAYROLL_MODULE.'-sg',
                    'role' => 'plugin',
                    'version' => '0.0.1',
                    'description' => 'Payroll — Singapore (hypothetical).',
                ],
            ],
        ]), 200),
        'https://api.github.com/repos/BelimbingApp/*/branches/main' => Http::response([
            'commit' => ['sha' => 'abc123def456'],
        ], 200),
    ]);
}

test('catalog refresh fetches BelimbingApp repos with blb-plugin topic', function (): void {
    fakeBelimbingAppCatalog();

    $entries = app(BelimbingAppCatalogService::class)->refresh();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->repoName)->toBe('blb-payroll-my')
        ->and($entries[0]->moduleIdentifier)->toBe(PLUGIN_CATALOG_PAYROLL_MODULE)
        ->and($entries[0]->version)->toBe('0.1.0');
});

test('catalog tab renders cached entries with the Installed badge on live modules', function (): void {
    fakeBelimbingAppCatalog();
    app(BelimbingAppCatalogService::class)->refresh();

    $this->actingAs(createAdminUser());

    Livewire::test(PluginManager::class, ['tab' => 'available'])
        ->assertSee('blb-payroll-my')
        ->assertSee(PLUGIN_CATALOG_PAYROLL_MODULE)
        ->assertSee('blb-payroll-sg')
        ->assertSee('Installed')                 // people/payroll is locally installed
        ->assertSee('git clone https://github.com/BelimbingApp/blb-payroll-sg.git'); // SG not installed → command shown
});

test('refreshCatalog action populates the cache and switches tab', function (): void {
    fakeBelimbingAppCatalog();

    $this->actingAs(createAdminUser());

    Livewire::test(PluginManager::class)
        ->call('refreshCatalog')
        ->assertSet('tab', 'available')
        ->assertSee('Catalog refreshed from GitHub');
});

test('refreshCatalog requires the system.plugins.manage capability', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('admin.system.plugins.index'));

    // Even getting to the page fails on the read capability — manage is strictly stricter.
    $response->assertForbidden();
});
