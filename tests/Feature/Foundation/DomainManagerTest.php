<?php

use App\Base\Foundation\Livewire\DomainManager;
use App\Base\Foundation\Services\DomainState;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

const DOMAIN_MANAGER_FIXTURE_DOMAIN = 'ZzManaged';
const DOMAIN_MANAGER_FIXTURE_REPO = 'https://example.test/zz.git';
const DOMAIN_MANAGER_FIXTURE_DESCRIPTION = 'Fixture description.';
const DOMAIN_MANAGER_FIXTURE_TABLE = 'zz_managed_table';
const DOMAIN_MANAGER_FIXTURE_SETTING = 'zz_managed.option';

afterEach(function (): void {
    File::deleteDirectory(app_path('Modules/'.DOMAIN_MANAGER_FIXTURE_DOMAIN));
});

function configureManagedDomainCatalog(): void
{
    config(['domains.catalog' => [
        DOMAIN_MANAGER_FIXTURE_DOMAIN => [
            'repo' => DOMAIN_MANAGER_FIXTURE_REPO,
            'description' => DOMAIN_MANAGER_FIXTURE_DESCRIPTION,
        ],
    ]]);
}

function createManagedDomainCheckout(): void
{
    createFakeDomainCheckout(
        DOMAIN_MANAGER_FIXTURE_DOMAIN,
        DOMAIN_MANAGER_FIXTURE_TABLE,
        DOMAIN_MANAGER_FIXTURE_SETTING,
    );
}

it('renders the domains page with installed domains and a residue pointer', function (): void {
    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.domains.index'))
        ->assertOk()
        ->assertSee('Installed domains')
        ->assertSee(route('admin.system.database-residue.index'));
});

it('denies the page to users without the view capability', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.system.domains.index'))->assertForbidden();
});

it('shows catalog domains without a checkout as available to install', function (): void {
    $this->actingAs(createAdminUser());

    configureManagedDomainCatalog();

    Livewire::test(DomainManager::class)
        ->assertSee('Available domains')
        ->assertSee(DOMAIN_MANAGER_FIXTURE_DOMAIN)
        ->assertSee(DOMAIN_MANAGER_FIXTURE_DESCRIPTION);
});

it('installs an available domain and redirects back', function (): void {
    $this->actingAs(createAdminUser());

    configureManagedDomainCatalog();

    Process::fake();

    Livewire::test(DomainManager::class)
        ->call('install', DOMAIN_MANAGER_FIXTURE_DOMAIN)
        ->assertRedirect(route('admin.system.domains.index'));

    Process::assertRan(fn ($process): bool => $process->command === ['git', 'clone', DOMAIN_MANAGER_FIXTURE_REPO, app_path('Modules/'.DOMAIN_MANAGER_FIXTURE_DOMAIN)]);
});

it('disables and re-enables an installed domain', function (): void {
    $this->actingAs(createAdminUser());

    createManagedDomainCheckout();

    Livewire::test(DomainManager::class)
        ->call('disable', DOMAIN_MANAGER_FIXTURE_DOMAIN)
        ->assertRedirect(route('admin.system.domains.index'));

    expect(DomainState::isDisabled(DOMAIN_MANAGER_FIXTURE_DOMAIN))->toBeTrue();

    Livewire::test(DomainManager::class)
        ->call('enable', DOMAIN_MANAGER_FIXTURE_DOMAIN)
        ->assertRedirect(route('admin.system.domains.index'));

    expect(DomainState::isDisabled(DOMAIN_MANAGER_FIXTURE_DOMAIN))->toBeFalse();
});

it('refuses to uninstall without the exact typed phrase', function (): void {
    $this->actingAs(createAdminUser());

    createManagedDomainCheckout();

    Livewire::test(DomainManager::class)
        ->call('openUninstall', DOMAIN_MANAGER_FIXTURE_DOMAIN)
        ->set('uninstallPhrase', 'uninstall zzmanaged please')
        ->call('uninstall')
        ->assertHasErrors('uninstallPhrase');

    expect(is_dir(app_path('Modules/'.DOMAIN_MANAGER_FIXTURE_DOMAIN)))->toBeTrue();
});

it('uninstalls keeping the database when the keep phrase is typed', function (): void {
    $this->actingAs(createAdminUser());

    createManagedDomainCheckout();
    Schema::create(DOMAIN_MANAGER_FIXTURE_TABLE, fn ($table) => $table->id());

    Livewire::test(DomainManager::class)
        ->call('openUninstall', DOMAIN_MANAGER_FIXTURE_DOMAIN)
        ->set('uninstallPhrase', 'uninstall zzmanaged')
        ->call('uninstall')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.system.domains.index'));

    expect(is_dir(app_path('Modules/'.DOMAIN_MANAGER_FIXTURE_DOMAIN)))->toBeFalse()
        ->and(Schema::hasTable(DOMAIN_MANAGER_FIXTURE_TABLE))->toBeTrue();
});

it('uninstalls and drops tables when the drop phrase is typed', function (): void {
    $this->actingAs(createAdminUser());

    createManagedDomainCheckout();
    Schema::create(DOMAIN_MANAGER_FIXTURE_TABLE, fn ($table) => $table->id());

    Livewire::test(DomainManager::class)
        ->call('openUninstall', DOMAIN_MANAGER_FIXTURE_DOMAIN)
        ->set('uninstallPhrase', 'uninstall zzmanaged and drop all tables')
        ->call('uninstall')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.system.domains.index'));

    expect(is_dir(app_path('Modules/'.DOMAIN_MANAGER_FIXTURE_DOMAIN)))->toBeFalse()
        ->and(Schema::hasTable(DOMAIN_MANAGER_FIXTURE_TABLE))->toBeFalse();
});

it('blocks install, disable, and uninstall for users without the manage capability', function (): void {
    $this->actingAs(User::factory()->create());

    configureManagedDomainCatalog();

    Process::fake();

    Livewire::test(DomainManager::class)->call('install', DOMAIN_MANAGER_FIXTURE_DOMAIN)->assertForbidden();
    Livewire::test(DomainManager::class)->call('disable', DOMAIN_MANAGER_FIXTURE_DOMAIN)->assertForbidden();
    Livewire::test(DomainManager::class)->call('openUninstall', DOMAIN_MANAGER_FIXTURE_DOMAIN)->assertForbidden();

    // Rendering legitimately runs `git status` on installed checkouts; what
    // must never have run without the manage capability is the clone.
    Process::assertDidntRun(fn ($process): bool => ($process->command[0] ?? '') === 'git' && ($process->command[1] ?? '') === 'clone');
});
