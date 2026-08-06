<?php

use App\Base\Foundation\Contracts\DomainRuntimeReloader;
use App\Base\Foundation\Livewire\Domains;
use App\Base\Foundation\Services\DomainState;
use App\Base\Foundation\Services\NestedCheckoutGitState;
use App\Base\Software\Inventory\InstalledSource;
use App\Base\Software\Services\SoftwareInventoryService;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\FakeDomainRuntimeReloader;

const DOMAINS_DOMAIN = 'ZzManaged';
const DOMAINS_REPO = 'https://example.test/zz.git';
const DOMAINS_DESCRIPTION = 'Fixture description.';
const DOMAINS_TABLE = 'zz_managed_table';
const DOMAINS_SETTING = 'zz_managed.option';
const DOMAINS_PATH = 'Domains/'.DOMAINS_DOMAIN;
const DOMAINS_CATALOG_PAYROLL = 'people/payroll';
const DOMAINS_MANIFEST_ID = 'zz-managed/sample';
const DOMAINS_MANIFEST_DESCRIPTION = 'ZzManaged sample module.';
const DOMAINS_TEST_VERSION = '0.1.0';

beforeEach(function (): void {
    app()->instance(DomainRuntimeReloader::class, new FakeDomainRuntimeReloader);
    setupAuthzRoles();
});

afterEach(function (): void {
    File::deleteDirectory(app_path(DOMAINS_PATH));
});

function domainsCatalog(): void
{
    config(['domains.catalog' => [
        DOMAINS_DOMAIN => ['repo' => DOMAINS_REPO, 'description' => DOMAINS_DESCRIPTION],
    ]]);
}

function fakeBelimbingAppCatalogForDomains(): void
{
    Http::fake([
        'https://api.github.com/orgs/BelimbingApp/repos*' => Http::response([
            ['name' => 'blb-payroll-my', 'html_url' => 'https://github.com/BelimbingApp/blb-payroll-my', 'default_branch' => 'main', 'topics' => ['blb-source']],
        ], 200),
        'https://raw.githubusercontent.com/BelimbingApp/blb-payroll-my/main/composer.json' => Http::response(json_encode([
            'name' => 'blb/payroll-my',
            'extra' => ['blb' => ['module' => DOMAINS_CATALOG_PAYROLL, 'version' => DOMAINS_TEST_VERSION, 'description' => 'Payroll — Malaysia.']],
        ]), 200),
        'https://api.github.com/repos/BelimbingApp/*/branches/main' => Http::response(['commit' => ['sha' => 'abc123']], 200),
    ]);
}

function writeDomainsFakeManifest(): void
{
    file_put_contents(app_path(DOMAINS_PATH.'/Sample/composer.json'), json_encode([
        'name' => 'test/zz-managed-sample',
        'extra' => ['blb' => [
            'module' => DOMAINS_MANIFEST_ID,
            'version' => DOMAINS_TEST_VERSION,
            'description' => DOMAINS_MANIFEST_DESCRIPTION,
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function createDomainsDomainWithClaimedTable(): void
{
    createFakeDomainCheckout(DOMAINS_DOMAIN, DOMAINS_TABLE, DOMAINS_SETTING);
    Schema::create(DOMAINS_TABLE, fn ($table) => $table->id());
}

function uninstallDomainsDomainWithPhrase(string $phrase): void
{
    Livewire::test(Domains::class)
        ->call('openUninstall', DOMAINS_DOMAIN)
        ->set('uninstallPhrase', $phrase)
        ->call('uninstall')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.system.software.domains.index'));
}

it('renders the Domains page with the installed tab and residue pointer', function (): void {
    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.software.domains.index'))
        ->assertOk()
        ->assertSee('Domains')
        ->assertSee('Installed Domains')
        ->assertSee('Built-in Platform')
        ->assertSee(route('admin.system.database-residue.index'));
});

it('denies the Domains page to users without the view capability', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.system.software.domains.index'))->assertForbidden();
});

it('redirects the legacy Modules URL and preserves its tab query', function (): void {
    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.software.modules.index', ['tab' => 'available']))
        ->assertRedirect(route('admin.system.software.domains.index', ['tab' => 'available']));
});

it('reports satisfied module dependencies on the installed tab', function (): void {
    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.software.domains.index'))
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

        public function installedSources(): array
        {
            return [
                new InstalledSource(
                    key: 'domain-zz-managed',
                    label: DOMAINS_DOMAIN,
                    kind: InstalledSource::KIND_DOMAIN,
                    path: 'app/Domains/'.DOMAINS_DOMAIN,
                    hasGit: true,
                    repo: 'BelimbingApp/zz-managed',
                    branch: 'main',
                    commit: null,
                    workingTree: ['dirty' => 1, 'ahead' => 0, 'behind' => 0],
                    disabled: false,
                    modules: [],
                    lifecycleName: DOMAINS_DOMAIN,
                ),
            ];
        }
    });

    Livewire::test(Domains::class)
        ->assertSee('id="add-in-source-drift"', false)
        ->assertSee('Add-in source has local checkout drift')
        ->assertSee('Commit, push, or remove the local changes in the nested Git checkout before updating or changing the add-in.')
        ->assertSee(DOMAINS_DOMAIN)
        ->assertSee('app/Domains/'.DOMAINS_DOMAIN)
        ->assertSee('uncommitted change')
        ->assertSee('git -C "app/Domains/'.DOMAINS_DOMAIN.'" status --short', false);
});

it('drills installed domains down to their module manifests', function (): void {
    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.software.domains.index'))
        ->assertOk()
        ->assertSee('people/payroll')
        ->assertSee('people/attendance');
})->skip(fn (): bool => ! is_dir(app_path('Domains/People')), 'People domain not installed');

it('keeps disabled domain manifests in the installed drill-down', function (): void {
    $this->actingAs(createAdminUser());
    createFakeDomainCheckout(DOMAINS_DOMAIN, DOMAINS_TABLE, DOMAINS_SETTING, ['withProvider' => true]);
    writeDomainsFakeManifest();
    DomainState::disable(DOMAINS_DOMAIN);

    Livewire::test(Domains::class)
        ->assertSee('disabled')
        ->assertSee(DOMAINS_MANIFEST_ID)
        ->assertSee(DOMAINS_MANIFEST_DESCRIPTION)
        ->assertSee(DOMAINS_TEST_VERSION);
});

it('lists catalog domains on the Available tab', function (): void {
    $this->actingAs(createAdminUser());
    domainsCatalog();

    Livewire::test(Domains::class, ['tab' => 'available'])
        ->assertSee('Available Domains')
        ->assertSee(DOMAINS_DOMAIN)
        ->assertSee(DOMAINS_DESCRIPTION);
});

it('refreshes and renders the BelimbingApp catalog', function (): void {
    fakeBelimbingAppCatalogForDomains();
    $this->actingAs(createAdminUser());

    Livewire::test(Domains::class)
        ->call('refreshCatalog')
        ->assertSet('tab', 'available')
        ->assertSee('BelimbingApp catalog')
        ->assertSee('blb-payroll-my');
});

it('blocks catalog refresh without the manage capability', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test(Domains::class)->call('refreshCatalog')->assertForbidden();
});

it('installs an available domain and redirects back', function (): void {
    $this->actingAs(createAdminUser());
    domainsCatalog();
    Process::fake();

    Livewire::test(Domains::class)
        ->call('install', DOMAINS_DOMAIN)
        ->assertRedirect(route('admin.system.software.domains.index'));

    Process::assertRan(fn ($process): bool => gitCommandWithoutConfig($process->command) === ['git', 'clone', DOMAINS_REPO, app_path(DOMAINS_PATH)]);
});

it('disables and re-enables an installed domain', function (): void {
    $this->actingAs(createAdminUser());
    createFakeDomainCheckout(DOMAINS_DOMAIN, DOMAINS_TABLE, DOMAINS_SETTING);

    Livewire::test(Domains::class)
        ->call('disable', DOMAINS_DOMAIN)
        ->assertSessionHas('command-log')
        ->assertRedirect(route('admin.system.software.domains.index'));
    expect(DomainState::isDisabled(DOMAINS_DOMAIN))->toBeTrue();

    Livewire::test(Domains::class)
        ->call('enable', DOMAINS_DOMAIN)
        ->assertRedirect(route('admin.system.software.domains.index'));
    expect(DomainState::isDisabled(DOMAINS_DOMAIN))->toBeFalse();
});

it('refuses to uninstall without the exact typed phrase', function (): void {
    $this->actingAs(createAdminUser());
    createFakeDomainCheckout(DOMAINS_DOMAIN, DOMAINS_TABLE, DOMAINS_SETTING);

    Livewire::test(Domains::class)
        ->call('openUninstall', DOMAINS_DOMAIN)
        ->set('uninstallPhrase', 'uninstall zzmanaged please')
        ->call('uninstall')
        ->assertHasErrors('uninstallPhrase');

    expect(is_dir(app_path(DOMAINS_PATH)))->toBeTrue();
});

it('uninstalls keeping the database when the keep phrase is typed', function (): void {
    $this->actingAs(createAdminUser());
    createDomainsDomainWithClaimedTable();

    uninstallDomainsDomainWithPhrase('uninstall zzmanaged');

    expect(is_dir(app_path(DOMAINS_PATH)))->toBeFalse()
        ->and(Schema::hasTable(DOMAINS_TABLE))->toBeTrue();
});

it('uninstalls and drops tables when the drop phrase is typed', function (): void {
    $this->actingAs(createAdminUser());
    createDomainsDomainWithClaimedTable();

    uninstallDomainsDomainWithPhrase('uninstall zzmanaged and drop all tables');

    expect(is_dir(app_path(DOMAINS_PATH)))->toBeFalse()
        ->and(Schema::hasTable(DOMAINS_TABLE))->toBeFalse();
});

it('blocks lifecycle actions for users without the manage capability', function (): void {
    $this->actingAs(User::factory()->create());
    domainsCatalog();
    Process::fake();

    Livewire::test(Domains::class)->call('install', DOMAINS_DOMAIN)->assertForbidden();
    Livewire::test(Domains::class)->call('disable', DOMAINS_DOMAIN)->assertForbidden();
    Livewire::test(Domains::class)->call('openUninstall', DOMAINS_DOMAIN)->assertForbidden();

    Process::assertDidntRun(fn ($process): bool => gitCommandWithoutConfig($process->command) === ['git', 'clone', DOMAINS_REPO, app_path(DOMAINS_PATH)]);
});
