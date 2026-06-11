<?php

use App\Base\Database\Livewire\Residue\Index as ResidueIndex;
use App\Base\Foundation\Services\DomainResidueScanner;
use App\Base\Settings\Models\Setting;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

function createDatabaseResidueFixture(): void
{
    // An orphan table no discovered migration claims, with one row.
    Schema::create('zz_orphan_residue_table', function ($table): void {
        $table->id();
    });
    DB::table('zz_orphan_residue_table')->insert(['id' => 1]);

    // A ledger row whose migration file does not exist anywhere.
    DB::table('migrations')->insert([
        'migration' => '2099_01_01_000000_create_zz_orphan_residue_table',
        'batch' => 999,
    ]);

    // A setting key no discovered Config/settings.php declares.
    Setting::query()->create([
        'key' => 'zz_removed_module.option',
        'value' => 'leftover',
        'scope_type' => null,
        'scope_id' => null,
    ]);
}

it('renders the residue page with orphaned tables, ledger rows, and settings', function (): void {
    $this->actingAs(createAdminUser());

    createDatabaseResidueFixture();

    $this->get(route('admin.system.database-residue.index'))
        ->assertOk()
        ->assertSee('Database Residue')
        ->assertSee('zz_orphan_residue_table')
        ->assertSee('2099_01_01_000000_create_zz_orphan_residue_table')
        ->assertSee('zz_removed_module.option');
});

it('denies the page to users without the view capability', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.system.database-residue.index'))->assertForbidden();
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

it('does not flag tables created through create*Table migration helpers', function (): void {
    // The Payroll mapping tables are built by a shared trait helper
    // (createPayrollPayItemMappingTable) rather than a literal
    // Schema::create — the claim parser must still see them.
    $orphanTables = array_column(app(DomainResidueScanner::class)->scan()['orphanTables'], 'table');

    expect($orphanTables)->not->toContain('people_payroll_attendance_rule_pay_items')
        ->and($orphanTables)->not->toContain('people_payroll_leave_type_pay_items')
        ->and($orphanTables)->not->toContain('people_payroll_claim_type_pay_items');
})->skip(fn (): bool => ! is_dir(app_path('Modules/People')), 'People domain not installed');

it('does not flag runtime-claimed settings as residue', function (): void {
    // commerce.marketplace.ebay.* is claimed via the runtime wildcard in
    // Marketplace Config/settings.php even though no editable field
    // declares these keys.
    Setting::query()->create([
        'key' => 'commerce.marketplace.ebay.orders_synced_through',
        'value' => '2026-06-01',
        'scope_type' => null,
        'scope_id' => null,
    ]);

    $orphanKeys = array_column(app(DomainResidueScanner::class)->scan()['orphanSettings'], 'key');

    expect($orphanKeys)->not->toContain('commerce.marketplace.ebay.orders_synced_through');
})->skip(fn (): bool => ! is_dir(app_path('Modules/Commerce')), 'Commerce domain not installed');

it('drops selected orphan tables and prunes their ledger rows after typed confirmation', function (): void {
    $this->actingAs(createAdminUser());

    createDatabaseResidueFixture();

    Livewire::test(ResidueIndex::class)
        ->set('selectedTables', ['zz_orphan_residue_table'])
        ->set('confirmText', 'THIS CANNOT BE UNDONE')
        ->call('dropSelectedTables')
        ->assertHasNoErrors();

    expect(Schema::hasTable('zz_orphan_residue_table'))->toBeFalse();

    Livewire::test(ResidueIndex::class)
        ->set('selectedLedger', ['2099_01_01_000000_create_zz_orphan_residue_table'])
        ->set('confirmText', 'THIS CANNOT BE UNDONE')
        ->call('pruneSelectedLedger')
        ->assertHasNoErrors();

    expect(
        DB::table('migrations')->where('migration', '2099_01_01_000000_create_zz_orphan_residue_table')->exists()
    )->toBeFalse();
});

it('deletes selected orphan settings across scopes after typed confirmation', function (): void {
    $this->actingAs(createAdminUser());

    createDatabaseResidueFixture();

    Livewire::test(ResidueIndex::class)
        ->set('selectedSettings', ['zz_removed_module.option'])
        ->set('confirmText', 'THIS CANNOT BE UNDONE')
        ->call('deleteSelectedSettings')
        ->assertHasNoErrors();

    expect(Setting::query()->where('key', 'zz_removed_module.option')->exists())->toBeFalse();
});

it('reveals action buttons only when items are selected and the acknowledgment is typed', function (): void {
    $this->actingAs(createAdminUser());

    createDatabaseResidueFixture();

    $component = Livewire::test(ResidueIndex::class)
        ->assertDontSee('Permanently drop');

    // Selection alone is not enough.
    $component->set('selectedTables', ['zz_orphan_residue_table'])
        ->assertDontSee('Permanently drop');

    // The typed acknowledgment arms only cards with selections.
    $component->set('confirmText', 'THIS CANNOT BE UNDONE')
        ->assertSee('Permanently drop')
        ->assertDontSee('Permanently delete');
});

it('refuses cleanup without the typed confirmation', function (): void {
    $this->actingAs(createAdminUser());

    createDatabaseResidueFixture();

    Livewire::test(ResidueIndex::class)
        ->set('selectedTables', ['zz_orphan_residue_table'])
        ->set('confirmText', 'delete me')
        ->call('dropSelectedTables')
        ->assertHasErrors('confirmText');

    expect(Schema::hasTable('zz_orphan_residue_table'))->toBeTrue();
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

    createDatabaseResidueFixture();

    Livewire::test(ResidueIndex::class)
        ->set('selectedTables', ['zz_orphan_residue_table'])
        ->set('confirmText', 'THIS CANNOT BE UNDONE')
        ->call('dropSelectedTables')
        ->assertForbidden();

    expect(Schema::hasTable('zz_orphan_residue_table'))->toBeTrue();
});
