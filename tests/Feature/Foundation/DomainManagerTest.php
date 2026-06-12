<?php

use App\Base\Foundation\Livewire\DomainManager;
use App\Base\Foundation\Services\DomainState;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

const DOMAIN_MANAGER_FIXTURE_PATH = 'Modules/ZzManaged';
const DOMAIN_MANAGER_FIXTURE_DESCRIPTION = 'Fixture description.';
const DOMAIN_MANAGER_FIXTURE_REPO = 'https://example.test/zz.git';

afterEach(function (): void {
    File::deleteDirectory(app_path(DOMAIN_MANAGER_FIXTURE_PATH));
});

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

    config(['domains.catalog' => [
        'ZzManaged' => ['repo' => DOMAIN_MANAGER_FIXTURE_REPO, 'description' => DOMAIN_MANAGER_FIXTURE_DESCRIPTION],
    ]]);

    Livewire::test(DomainManager::class)
        ->assertSee('Available domains')
        ->assertSee('ZzManaged')
        ->assertSee(DOMAIN_MANAGER_FIXTURE_DESCRIPTION);
});

it('installs an available domain and redirects back', function (): void {
    $this->actingAs(createAdminUser());

    config(['domains.catalog' => [
        'ZzManaged' => ['repo' => DOMAIN_MANAGER_FIXTURE_REPO, 'description' => DOMAIN_MANAGER_FIXTURE_DESCRIPTION],
    ]]);

    Process::fake();

    Livewire::test(DomainManager::class)
        ->call('install', 'ZzManaged')
        ->assertRedirect(route('admin.system.domains.index'));

    Process::assertRan(fn ($process): bool => $process->command === ['git', 'clone', DOMAIN_MANAGER_FIXTURE_REPO, app_path(DOMAIN_MANAGER_FIXTURE_PATH)]);
});

it('disables and re-enables an installed domain', function (): void {
    $this->actingAs(createAdminUser());

    createFakeDomainCheckout('ZzManaged', 'zz_managed_table', 'zz_managed.option');

    Livewire::test(DomainManager::class)
        ->call('disable', 'ZzManaged')
        ->assertRedirect(route('admin.system.domains.index'));

    expect(DomainState::isDisabled('ZzManaged'))->toBeTrue();

    Livewire::test(DomainManager::class)
        ->call('enable', 'ZzManaged')
        ->assertRedirect(route('admin.system.domains.index'));

    expect(DomainState::isDisabled('ZzManaged'))->toBeFalse();
});

it('refuses to uninstall without the exact typed phrase', function (): void {
    $this->actingAs(createAdminUser());

    createFakeDomainCheckout('ZzManaged', 'zz_managed_table', 'zz_managed.option');

    Livewire::test(DomainManager::class)
        ->call('openUninstall', 'ZzManaged')
        ->set('uninstallPhrase', 'uninstall zzmanaged please')
        ->call('uninstall')
        ->assertHasErrors('uninstallPhrase');

    expect(is_dir(app_path(DOMAIN_MANAGER_FIXTURE_PATH)))->toBeTrue();
});

it('uninstalls keeping the database when the keep phrase is typed', function (): void {
    $this->actingAs(createAdminUser());

    createFakeDomainCheckout('ZzManaged', 'zz_managed_table', 'zz_managed.option');
    Schema::create('zz_managed_table', fn ($table) => $table->id());

    Livewire::test(DomainManager::class)
        ->call('openUninstall', 'ZzManaged')
        ->set('uninstallPhrase', 'uninstall zzmanaged')
        ->call('uninstall')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.system.domains.index'));

    expect(is_dir(app_path(DOMAIN_MANAGER_FIXTURE_PATH)))->toBeFalse()
        ->and(Schema::hasTable('zz_managed_table'))->toBeTrue();
});

it('uninstalls and drops tables when the drop phrase is typed', function (): void {
    $this->actingAs(createAdminUser());

    createFakeDomainCheckout('ZzManaged', 'zz_managed_table', 'zz_managed.option');
    Schema::create('zz_managed_table', fn ($table) => $table->id());

    Livewire::test(DomainManager::class)
        ->call('openUninstall', 'ZzManaged')
        ->set('uninstallPhrase', 'uninstall zzmanaged and drop all tables')
        ->call('uninstall')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.system.domains.index'));

    expect(is_dir(app_path(DOMAIN_MANAGER_FIXTURE_PATH)))->toBeFalse()
        ->and(Schema::hasTable('zz_managed_table'))->toBeFalse();
});

it('blocks install, disable, and uninstall for users without the manage capability', function (): void {
    $this->actingAs(User::factory()->create());

    config(['domains.catalog' => [
        'ZzManaged' => ['repo' => DOMAIN_MANAGER_FIXTURE_REPO, 'description' => DOMAIN_MANAGER_FIXTURE_DESCRIPTION],
    ]]);

    Process::fake();

    Livewire::test(DomainManager::class)->call('install', 'ZzManaged')->assertForbidden();
    Livewire::test(DomainManager::class)->call('disable', 'ZzManaged')->assertForbidden();
    Livewire::test(DomainManager::class)->call('openUninstall', 'ZzManaged')->assertForbidden();

    // Rendering legitimately runs `git status` on installed checkouts; what
    // must never have run without the manage capability is the clone.
    Process::assertDidntRun(fn ($process): bool => ($process->command[0] ?? '') === 'git' && ($process->command[1] ?? '') === 'clone');
});
