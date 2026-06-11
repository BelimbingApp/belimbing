<?php

use App\Base\Foundation\Livewire\DomainManager;
use App\Base\Foundation\Services\DomainResidueScanner;
use App\Base\Foundation\Services\DomainState;
use App\Base\Settings\Models\Setting;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

afterEach(function (): void {
    File::deleteDirectory(app_path('Modules/ZzManaged'));
});

function createDomainResidueFixture(): void
{
    // An orphan table no discovered migration claims, with one row.
    Schema::create('zz_orphan_domain_table', function ($table): void {
        $table->id();
    });
    DB::table('zz_orphan_domain_table')->insert(['id' => 1]);

    // A ledger row whose migration file does not exist anywhere.
    DB::table('migrations')->insert([
        'migration' => '2099_01_01_000000_create_zz_orphan_domain_table',
        'batch' => 999,
    ]);

    // A setting key no discovered Config/settings.php declares.
    Setting::query()->create([
        'key' => 'zz_removed_domain.option',
        'value' => 'leftover',
        'scope_type' => null,
        'scope_id' => null,
    ]);
}

it('renders the domains page with installed domains and residue for admins', function (): void {
    $this->actingAs(createAdminUser());

    createDomainResidueFixture();

    $this->get(route('admin.system.domains.index'))
        ->assertOk()
        ->assertSee('Database residue')
        ->assertSee('zz_orphan_domain_table')
        ->assertSee('2099_01_01_000000_create_zz_orphan_domain_table')
        ->assertSee('zz_removed_domain.option');
});

it('denies the page to users without the view capability', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.system.domains.index'))->assertForbidden();
});

it('does not flag claimed tables, present migrations, or declared settings as residue', function (): void {
    $report = app(DomainResidueScanner::class)->scan();

    $orphanTables = array_column($report['orphanTables'], 'table');

    // base_settings is created by a discovered Base migration; users by Core.
    expect($orphanTables)->not->toContain('base_settings')
        ->and($orphanTables)->not->toContain('users');

    // Every ledger row in a freshly-migrated test DB has its file present.
    expect($report['orphanLedger'])->toBe([]);
});

it('drops selected orphan tables and prunes their ledger rows after typed confirmation', function (): void {
    $this->actingAs(createAdminUser());

    createDomainResidueFixture();

    Livewire::test(DomainManager::class)
        ->set('selectedTables', ['zz_orphan_domain_table'])
        ->set('confirmText', 'DELETE')
        ->call('dropSelectedTables')
        ->assertHasNoErrors();

    expect(Schema::hasTable('zz_orphan_domain_table'))->toBeFalse();

    Livewire::test(DomainManager::class)
        ->set('selectedLedger', ['2099_01_01_000000_create_zz_orphan_domain_table'])
        ->set('confirmText', 'DELETE')
        ->call('pruneSelectedLedger')
        ->assertHasNoErrors();

    expect(
        DB::table('migrations')->where('migration', '2099_01_01_000000_create_zz_orphan_domain_table')->exists()
    )->toBeFalse();
});

it('deletes selected orphan settings across scopes after typed confirmation', function (): void {
    $this->actingAs(createAdminUser());

    createDomainResidueFixture();

    Livewire::test(DomainManager::class)
        ->set('selectedSettings', ['zz_removed_domain.option'])
        ->set('confirmText', 'DELETE')
        ->call('deleteSelectedSettings')
        ->assertHasNoErrors();

    expect(Setting::query()->where('key', 'zz_removed_domain.option')->exists())->toBeFalse();
});

it('refuses cleanup without the typed confirmation', function (): void {
    $this->actingAs(createAdminUser());

    createDomainResidueFixture();

    Livewire::test(DomainManager::class)
        ->set('selectedTables', ['zz_orphan_domain_table'])
        ->set('confirmText', 'delete me')
        ->call('dropSelectedTables')
        ->assertHasErrors('confirmText');

    expect(Schema::hasTable('zz_orphan_domain_table'))->toBeTrue();
});

it('never drops a claimed table even when explicitly requested', function (): void {
    $this->actingAs(createAdminUser());

    $result = app(DomainResidueScanner::class)->dropTables(['base_settings']);

    expect($result['dropped'])->toBe([])
        ->and($result['skipped'])->toBe(['base_settings'])
        ->and(Schema::hasTable('base_settings'))->toBeTrue();
});

it('blocks cleanup actions for users without the manage capability', function (): void {
    $this->actingAs(User::factory()->create());

    createDomainResidueFixture();

    Livewire::test(DomainManager::class)
        ->set('selectedTables', ['zz_orphan_domain_table'])
        ->set('confirmText', 'DELETE')
        ->call('dropSelectedTables')
        ->assertForbidden();

    expect(Schema::hasTable('zz_orphan_domain_table'))->toBeTrue();
});

it('shows catalog domains without a checkout as available to install', function (): void {
    $this->actingAs(createAdminUser());

    config(['domains.catalog' => [
        'ZzManaged' => ['repo' => 'https://example.test/zz.git', 'description' => 'Fixture description.'],
    ]]);

    Livewire::test(DomainManager::class)
        ->assertSee('Available domains')
        ->assertSee('ZzManaged')
        ->assertSee('Fixture description.');
});

it('installs an available domain and redirects back', function (): void {
    $this->actingAs(createAdminUser());

    config(['domains.catalog' => [
        'ZzManaged' => ['repo' => 'https://example.test/zz.git', 'description' => 'Fixture description.'],
    ]]);

    Process::fake();

    Livewire::test(DomainManager::class)
        ->call('install', 'ZzManaged')
        ->assertRedirect(route('admin.system.domains.index'));

    Process::assertRan(fn ($process): bool => $process->command === ['git', 'clone', 'https://example.test/zz.git', app_path('Modules/ZzManaged')]);
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

    expect(is_dir(app_path('Modules/ZzManaged')))->toBeTrue();
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

    expect(is_dir(app_path('Modules/ZzManaged')))->toBeFalse()
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

    expect(is_dir(app_path('Modules/ZzManaged')))->toBeFalse()
        ->and(Schema::hasTable('zz_managed_table'))->toBeFalse();
});

it('blocks install, disable, and uninstall for users without the manage capability', function (): void {
    $this->actingAs(User::factory()->create());

    config(['domains.catalog' => [
        'ZzManaged' => ['repo' => 'https://example.test/zz.git', 'description' => 'Fixture description.'],
    ]]);

    Process::fake();

    Livewire::test(DomainManager::class)->call('install', 'ZzManaged')->assertForbidden();
    Livewire::test(DomainManager::class)->call('disable', 'ZzManaged')->assertForbidden();
    Livewire::test(DomainManager::class)->call('openUninstall', 'ZzManaged')->assertForbidden();

    // Rendering legitimately runs `git status` on installed checkouts; what
    // must never have run without the manage capability is the clone.
    Process::assertDidntRun(fn ($process): bool => ($process->command[0] ?? '') === 'git' && ($process->command[1] ?? '') === 'clone');
});
