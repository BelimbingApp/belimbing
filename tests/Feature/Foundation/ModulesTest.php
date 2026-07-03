<?php

use App\Base\Foundation\Contracts\DomainRuntimeReloader;
use App\Base\Foundation\Livewire\Modules;
use App\Base\Foundation\Services\DomainState;
use App\Base\Foundation\Services\NestedCheckoutGitState;
use App\Base\Software\Inventory\InstalledBundle;
use App\Base\Software\Services\SoftwareInventoryService;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\FakeDomainRuntimeReloader;

const MODULES_DOMAIN = 'ZzManaged';
const MODULES_REPO = 'https://example.test/zz.git';
const MODULES_DESCRIPTION = 'Fixture description.';
const MODULES_TABLE = 'zz_managed_table';
const MODULES_SETTING = 'zz_managed.option';
const MODULES_PATH = 'Modules/'.MODULES_DOMAIN;
const MODULES_CATALOG_PAYROLL = 'people/payroll';
const MODULES_MANIFEST_ID = 'zz-managed/sample';
const MODULES_MANIFEST_DESCRIPTION = 'ZzManaged sample module.';
const MODULES_TEST_VERSION = '0.1.0';

beforeEach(function (): void {
    app()->instance(DomainRuntimeReloader::class, new FakeDomainRuntimeReloader);
    setupAuthzRoles();
});

afterEach(function (): void {
    File::deleteDirectory(app_path(MODULES_PATH));
});

function modulesCatalog(): void
{
    config(['domains.catalog' => [
        MODULES_DOMAIN => ['repo' => MODULES_REPO, 'description' => MODULES_DESCRIPTION],
    ]]);
}

function fakeBelimbingAppCatalogForModules(): void
{
    Http::fake([
        'https://api.github.com/orgs/BelimbingApp/repos*' => Http::response([
            ['name' => 'blb-payroll-my', 'html_url' => 'https://github.com/BelimbingApp/blb-payroll-my', 'default_branch' => 'main', 'topics' => ['blb-bundle']],
        ], 200),
        'https://raw.githubusercontent.com/BelimbingApp/blb-payroll-my/main/composer.json' => Http::response(json_encode([
            'name' => 'blb/payroll-my',
            'extra' => ['blb' => ['module' => MODULES_CATALOG_PAYROLL, 'version' => MODULES_TEST_VERSION, 'description' => 'Payroll — Malaysia.']],
        ]), 200),
        'https://api.github.com/repos/BelimbingApp/*/branches/main' => Http::response(['commit' => ['sha' => 'abc123']], 200),
    ]);
}

function writeModulesFakeManifest(): void
{
    file_put_contents(app_path(MODULES_PATH.'/Sample/composer.json'), json_encode([
        'name' => 'test/zz-managed-sample',
        'extra' => ['blb' => [
            'module' => MODULES_MANIFEST_ID,
            'version' => MODULES_TEST_VERSION,
            'description' => MODULES_MANIFEST_DESCRIPTION,
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function createModulesDomainWithClaimedTable(): void
{
    createFakeDomainCheckout(MODULES_DOMAIN, MODULES_TABLE, MODULES_SETTING);
    Schema::create(MODULES_TABLE, fn ($table) => $table->id());
}

function uninstallModulesDomainWithPhrase(string $phrase): void
{
    Livewire::test(Modules::class)
        ->call('openUninstall', MODULES_DOMAIN)
        ->set('uninstallPhrase', $phrase)
        ->call('uninstall')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.system.software.modules.index'));
}

it('renders the Modules page with the installed tab and residue pointer', function (): void {
    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.software.modules.index'))
        ->assertOk()
        ->assertSee('Modules')
        ->assertSee('Installed add-in business domains')
        ->assertSee('Built-in Platform')
        ->assertSee(route('admin.system.database-residue.index'));
});

it('denies the Modules page to users without the view capability', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.system.software.modules.index'))->assertForbidden();
});

it('reports satisfied module dependencies on the installed tab', function (): void {
    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.software.modules.index'))
        ->assertOk()
        ->assertSee('All required module dependencies are satisfied.')
        ->assertDontSee('Module dependency issues');
});

it('shows actionable local checkout drift for installed add-ins', function (): void {
    $this->actingAs(createAdminUser());
    app()->instance(NestedCheckoutGitState::class, new class extends NestedCheckoutGitState
    {
        public function inspect(string $path): array
        {
            return ['hasGit' => false, 'dirty' => false, 'unpushed' => 0];
        }
    });
    app()->instance(SoftwareInventoryService::class, new class extends SoftwareInventoryService
    {
        public function __construct()
        {
            // Parent dependencies are unused by this test double.
        }

        public function installedBundles(): array
        {
            return [
                new InstalledBundle(
                    key: 'app-Modules-'.MODULES_DOMAIN,
                    label: MODULES_DOMAIN,
                    kind: InstalledBundle::KIND_BUSINESS_DOMAIN,
                    path: 'app/Modules/'.MODULES_DOMAIN,
                    hasGit: true,
                    repo: 'BelimbingApp/zz-managed',
                    branch: 'main',
                    commit: null,
                    workingTree: ['dirty' => 1, 'ahead' => 0, 'behind' => 0],
                    disabled: false,
                    modules: [],
                    lifecycleName: MODULES_DOMAIN,
                ),
            ];
        }
    });

    Livewire::test(Modules::class)
        ->assertSee('id="add-in-bundle-drift"', false)
        ->assertSee('Add-in bundle has local checkout drift')
        ->assertSee('Commit, push, or remove the local changes in the nested Git checkout before updating or changing the add-in.')
        ->assertSee(MODULES_DOMAIN)
        ->assertSee('app/Modules/'.MODULES_DOMAIN)
        ->assertSee('uncommitted change')
        ->assertSee('git -C "app/Modules/'.MODULES_DOMAIN.'" status --short', false);
});

it('drills installed domains down to their module manifests', function (): void {
    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.software.modules.index'))
        ->assertOk()
        ->assertSee('people/payroll')
        ->assertSee('people/attendance');
})->skip(fn (): bool => ! is_dir(app_path('Modules/People')), 'People domain not installed');

it('keeps disabled domain manifests in the installed drill-down', function (): void {
    $this->actingAs(createAdminUser());
    createFakeDomainCheckout(MODULES_DOMAIN, MODULES_TABLE, MODULES_SETTING, ['withProvider' => true]);
    writeModulesFakeManifest();
    DomainState::disable(MODULES_DOMAIN);

    Livewire::test(Modules::class)
        ->assertSee('disabled')
        ->assertSee(MODULES_MANIFEST_ID)
        ->assertSee(MODULES_MANIFEST_DESCRIPTION)
        ->assertSee(MODULES_TEST_VERSION);
});

it('lists catalog domains on the Available tab', function (): void {
    $this->actingAs(createAdminUser());
    modulesCatalog();

    Livewire::test(Modules::class, ['tab' => 'available'])
        ->assertSee('Available add-in business domains')
        ->assertSee(MODULES_DOMAIN)
        ->assertSee(MODULES_DESCRIPTION);
});

it('refreshes and renders the BelimbingApp catalog', function (): void {
    fakeBelimbingAppCatalogForModules();
    $this->actingAs(createAdminUser());

    Livewire::test(Modules::class)
        ->call('refreshCatalog')
        ->assertSet('tab', 'available')
        ->assertSee('BelimbingApp catalog')
        ->assertSee('blb-payroll-my');
});

it('blocks catalog refresh without the manage capability', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test(Modules::class)->call('refreshCatalog')->assertForbidden();
});

it('installs an available domain and redirects back', function (): void {
    $this->actingAs(createAdminUser());
    modulesCatalog();
    Process::fake();

    Livewire::test(Modules::class)
        ->call('install', MODULES_DOMAIN)
        ->assertRedirect(route('admin.system.software.modules.index'));

    Process::assertRan(fn ($process): bool => gitCommandWithoutConfig($process->command) === ['git', 'clone', MODULES_REPO, app_path(MODULES_PATH)]);
});

it('disables and re-enables an installed domain', function (): void {
    $this->actingAs(createAdminUser());
    createFakeDomainCheckout(MODULES_DOMAIN, MODULES_TABLE, MODULES_SETTING);

    Livewire::test(Modules::class)
        ->call('disable', MODULES_DOMAIN)
        ->assertSessionHas('command-log')
        ->assertRedirect(route('admin.system.software.modules.index'));
    expect(DomainState::isDisabled(MODULES_DOMAIN))->toBeTrue();

    Livewire::test(Modules::class)
        ->call('enable', MODULES_DOMAIN)
        ->assertRedirect(route('admin.system.software.modules.index'));
    expect(DomainState::isDisabled(MODULES_DOMAIN))->toBeFalse();
});

it('refuses to uninstall without the exact typed phrase', function (): void {
    $this->actingAs(createAdminUser());
    createFakeDomainCheckout(MODULES_DOMAIN, MODULES_TABLE, MODULES_SETTING);

    Livewire::test(Modules::class)
        ->call('openUninstall', MODULES_DOMAIN)
        ->set('uninstallPhrase', 'uninstall zzmanaged please')
        ->call('uninstall')
        ->assertHasErrors('uninstallPhrase');

    expect(is_dir(app_path(MODULES_PATH)))->toBeTrue();
});

it('uninstalls keeping the database when the keep phrase is typed', function (): void {
    $this->actingAs(createAdminUser());
    createModulesDomainWithClaimedTable();

    uninstallModulesDomainWithPhrase('uninstall zzmanaged');

    expect(is_dir(app_path(MODULES_PATH)))->toBeFalse()
        ->and(Schema::hasTable(MODULES_TABLE))->toBeTrue();
});

it('uninstalls and drops tables when the drop phrase is typed', function (): void {
    $this->actingAs(createAdminUser());
    createModulesDomainWithClaimedTable();

    uninstallModulesDomainWithPhrase('uninstall zzmanaged and drop all tables');

    expect(is_dir(app_path(MODULES_PATH)))->toBeFalse()
        ->and(Schema::hasTable(MODULES_TABLE))->toBeFalse();
});

it('blocks lifecycle actions for users without the manage capability', function (): void {
    $this->actingAs(User::factory()->create());
    modulesCatalog();
    Process::fake();

    Livewire::test(Modules::class)->call('install', MODULES_DOMAIN)->assertForbidden();
    Livewire::test(Modules::class)->call('disable', MODULES_DOMAIN)->assertForbidden();
    Livewire::test(Modules::class)->call('openUninstall', MODULES_DOMAIN)->assertForbidden();

    Process::assertDidntRun(fn ($process): bool => gitCommandWithoutConfig($process->command) === ['git', 'clone', MODULES_REPO, app_path(MODULES_PATH)]);
});
