<?php

use App\Base\Database\Livewire\DatabaseTables\Index as DatabaseTablesIndex;
use App\Base\Database\Livewire\DatabaseTables\Show as DatabaseTablesShow;
use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\TableInspector;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

const TABLE_REGISTRY_RECONCILIATION_USER_MODULE_PATH = 'app/Modules/Core/User';
const TABLE_REGISTRY_RECONCILIATION_EXT_DIR = 'extensions/test-vendor/test-mod/Database/Migrations';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setupAuthzRoles();
});

test('registry reconciliation removes entries for missing undeclared relations', function (): void {
    TableRegistry::query()->create([
        'table_name' => 'orphaned_table',
        'module_name' => 'User',
        'module_path' => TABLE_REGISTRY_RECONCILIATION_USER_MODULE_PATH,
        'migration_file' => '0200_01_20_000000_create_orphaned_table.php',
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
    ]);

    app(TableInspector::class)->reconcileRegistry();

    $entry = TableRegistry::query()->where('table_name', 'users')->first();

    expect($entry)->not()->toBeNull()
        ->and($entry->module_name)->toBe('User')
        ->and($entry->module_path)->toBe(TABLE_REGISTRY_RECONCILIATION_USER_MODULE_PATH)
        ->and($entry->migration_file)->toBe('0200_01_20_000000_create_users_table.php');
});

test('database tables index shows reconciliation notice and omits orphaned rows', function (): void {
    $this->actingAs(createAdminUser());

    TableRegistry::query()->create([
        'table_name' => 'ghost_registry_entry',
        'module_name' => 'User',
        'module_path' => TABLE_REGISTRY_RECONCILIATION_USER_MODULE_PATH,
        'migration_file' => '0200_01_20_000001_create_ghost_registry_entry.php',
    ]);

    Livewire::test(DatabaseTablesIndex::class)
        ->assertSee('Removed orphaned registry entry for ghost_registry_entry because the relation no longer exists.');

    expect(TableRegistry::query()->where('table_name', 'ghost_registry_entry')->exists())->toBeFalse();
});

test('database tables index renders only one bounded page of registered tables', function (): void {
    $this->actingAs(createAdminUser());

    // Real relations so mount()'s reconciliation does not prune these registry
    // rows; a shared prefix lets search scope assertions to just these fixtures
    // regardless of how many baseline tables the seeder created.
    $created = [];

    try {
        foreach (range(0, 29) as $n) {
            $table = sprintf('pagebound_%02d', $n);
            $created[] = $table;

            Schema::create($table, function ($blueprint): void {
                $blueprint->id();
            });

            TableRegistry::query()->create([
                'table_name' => $table,
                'module_name' => 'Pagebound',
                'module_path' => 'app/Pagebound',
                'migration_file' => $table.'.php',
            ]);
        }

        $component = Livewire::test(DatabaseTablesIndex::class)
            ->set('search', 'pagebound_');

        /** @var LengthAwarePaginator $tables */
        $tables = $component->viewData('tables');

        // 30 matches, 25-per-page window: total is reported but only the first
        // page is materialised into the DOM.
        expect($tables->total())->toBe(30)
            ->and($tables->perPage())->toBe(25)
            ->and($tables->lastPage())->toBe(2)
            ->and($tables->count())->toBe(25);

        // Default sort is table_name asc, so page one is pagebound_00..24.
        $component->assertSee('pagebound_00')
            ->assertSee('pagebound_24')
            ->assertDontSee('pagebound_25')
            ->assertDontSee('pagebound_29');

        // Page two holds the remainder; page-one rows are no longer rendered.
        $component->call('gotoPage', 2)
            ->assertSee('pagebound_25')
            ->assertSee('pagebound_29')
            ->assertDontSee('pagebound_00')
            ->assertDontSee('pagebound_24');
    } finally {
        foreach ($created as $table) {
            Schema::dropIfExists($table);
        }
    }
});

test('database tables index search resets pagination to the first page', function (): void {
    $this->actingAs(createAdminUser());

    $component = Livewire::test(DatabaseTablesIndex::class)
        ->call('gotoPage', 2);

    expect($component->viewData('tables')->currentPage())->toBe(2);

    $component->set('search', 'users');

    expect($component->viewData('tables')->currentPage())->toBe(1);
});

test('database table show redirects to registry when an orphaned entry is requested', function (): void {
    $this->actingAs(createAdminUser());

    TableRegistry::query()->create([
        'table_name' => 'ghost_table_view',
        'module_name' => 'User',
        'module_path' => TABLE_REGISTRY_RECONCILIATION_USER_MODULE_PATH,
        'migration_file' => '0200_01_20_000002_create_ghost_table_view.php',
    ]);

    Livewire::test(DatabaseTablesShow::class, ['tableName' => 'ghost_table_view'])
        ->assertRedirect(route('admin.system.database-tables.index'));

    expect(session('warning'))->toContain('Removed orphaned registry entry for ghost_table_view because the relation no longer exists.')
        ->and(TableRegistry::query()->where('table_name', 'ghost_table_view')->exists())->toBeFalse();
});

test('reconciliation discovers tables from extension migration files', function (): void {
    $dir = base_path(TABLE_REGISTRY_RECONCILIATION_EXT_DIR);
    $file = $dir.'/2099_01_01_000000_create_test_vendor_ext_table.php';

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($file, <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('test_vendor_ext', function (Blueprint $table): void {
            $table->id();
        });
    }
};
PHP);

    try {
        TableRegistry::reconcile();

        $entry = TableRegistry::query()->where('table_name', 'test_vendor_ext')->first();

        expect($entry)->not()->toBeNull()
            ->and($entry->module_name)->toBe('test-mod')
            ->and($entry->module_path)->toBe('extensions/test-vendor/test-mod')
            ->and($entry->migration_file)->toBe('2099_01_01_000000_create_test_vendor_ext_table.php');
    } finally {
        @unlink($file);
        @rmdir($dir);
        @rmdir(dirname($dir));
        @rmdir(dirname($dir, 2));
        @rmdir(dirname($dir, 3));
    }
});
