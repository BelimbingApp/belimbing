<?php

use App\Base\Database\Livewire\DatabaseTables\Index as DatabaseTablesIndex;
use App\Base\Database\Livewire\DatabaseTables\Show as DatabaseTablesShow;
use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\TableInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setupAuthzRoles();
});

test('registry reconciliation removes entries for missing undeclared relations', function (): void {
    TableRegistry::query()->create([
        'table_name' => 'orphaned_table',
        'module_name' => 'User',
        'module_path' => 'app/Modules/Core/User',
        'migration_file' => '0200_01_20_000000_create_orphaned_table.php',
        'is_stable' => true,
        'stabilized_at' => now(),
    ]);

    $notices = app(TableInspector::class)->reconcileRegistry();

    expect(TableRegistry::query()->where('table_name', 'orphaned_table')->exists())->toBeFalse()
        ->and($notices)->toContain(__('Removed orphaned registry entry for :table because the relation no longer exists.', [
            'table' => 'orphaned_table',
        ]));
});

test('registry reconciliation preserves declared tables even before they exist live', function (): void {
    TableRegistry::query()->where('table_name', 'users')->update([
        'module_name' => 'Wrong',
        'module_path' => 'app/Wrong',
        'migration_file' => 'wrong.php',
        'is_stable' => false,
        'stabilized_at' => null,
    ]);

    app(TableInspector::class)->reconcileRegistry();

    $entry = TableRegistry::query()->where('table_name', 'users')->first();

    expect($entry)->not()->toBeNull()
        ->and($entry->module_name)->toBe('User')
        ->and($entry->module_path)->toBe('app/Modules/Core/User')
        ->and($entry->migration_file)->toBe('0200_01_20_000000_create_users_table.php')
        ->and($entry->is_stable)->toBeFalse();
});

test('database tables index shows reconciliation notice and omits orphaned rows', function (): void {
    $this->actingAs(createAdminUser());

    TableRegistry::query()->create([
        'table_name' => 'ghost_registry_entry',
        'module_name' => 'User',
        'module_path' => 'app/Modules/Core/User',
        'migration_file' => '0200_01_20_000001_create_ghost_registry_entry.php',
        'is_stable' => true,
        'stabilized_at' => now(),
    ]);

    Livewire::test(DatabaseTablesIndex::class)
        ->assertSee('Removed orphaned registry entry for ghost_registry_entry because the relation no longer exists.');

    expect(TableRegistry::query()->where('table_name', 'ghost_registry_entry')->exists())->toBeFalse();
});

test('database table show redirects to registry when an orphaned entry is requested', function (): void {
    $this->actingAs(createAdminUser());

    TableRegistry::query()->create([
        'table_name' => 'ghost_table_view',
        'module_name' => 'User',
        'module_path' => 'app/Modules/Core/User',
        'migration_file' => '0200_01_20_000002_create_ghost_table_view.php',
        'is_stable' => true,
        'stabilized_at' => now(),
    ]);

    Livewire::test(DatabaseTablesShow::class, ['tableName' => 'ghost_table_view'])
        ->assertRedirect(route('admin.system.database-tables.index'));

    expect(session('warning'))->toContain('Removed orphaned registry entry for ghost_table_view because the relation no longer exists.')
        ->and(TableRegistry::query()->where('table_name', 'ghost_table_view')->exists())->toBeFalse();
});
