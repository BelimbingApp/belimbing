<?php

use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\IncubatingSchemaPreflight;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const INCUBATING_SCHEMA_TEST_MODULE_PATH = 'extensions/test-vendor/test-mod';
const INCUBATING_SCHEMA_TEST_DIR = INCUBATING_SCHEMA_TEST_MODULE_PATH.'/Database/Migrations';
const INCUBATING_SCHEMA_TEST_FILE = '2099_01_01_000000_create_test_incubating_widgets_table.php';
const INCUBATING_SCHEMA_TEST_FILE_NAME = '2099_01_01_000000_create_test_incubating_widgets_table';
const INCUBATING_SCHEMA_TEST_TABLE = 'test_incubating_widgets';
const INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE = 'test_stable_widget_parts';
const INCUBATING_SCHEMA_TEST_SIBLING_TABLE = 'test_stable_widget_part_notes';
const INCUBATING_SCHEMA_TEST_SINGLE_TABLE_DEPENDENT_NAME = '2099_01_01_000001_create_test_stable_widget_parts_table';
const INCUBATING_SCHEMA_TEST_MULTI_TABLE_DEPENDENT_NAME = '2099_01_01_000001_create_test_stable_widget_part_tables';

afterEach(function (): void {
    // Incubating migration: drop table, clear ledger rows, remove the file.
    cleanupIncubatingTestMigration(INCUBATING_SCHEMA_TEST_DIR, INCUBATING_SCHEMA_TEST_FILE, INCUBATING_SCHEMA_TEST_TABLE);
    TableRegistry::query()->where('table_name', INCUBATING_SCHEMA_TEST_TABLE)->delete();

    // Dependent/sibling tables from the cascade tests (no on-disk migration file).
    foreach ([INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE, INCUBATING_SCHEMA_TEST_SIBLING_TABLE] as $table) {
        Schema::dropIfExists($table);
        TableRegistry::query()->where('table_name', $table)->delete();
    }

    DB::table('migrations')->whereIn('migration', [
        INCUBATING_SCHEMA_TEST_SINGLE_TABLE_DEPENDENT_NAME,
        INCUBATING_SCHEMA_TEST_MULTI_TABLE_DEPENDENT_NAME,
    ])->delete();
});

test('preflight discovers incubating migrations declared with trait metadata', function (): void {
    writeIncubatingTestMigration(INCUBATING_SCHEMA_TEST_DIR, INCUBATING_SCHEMA_TEST_FILE, INCUBATING_SCHEMA_TEST_TABLE);

    $migrations = app(IncubatingSchemaPreflight::class)->incubatingMigrations([base_path(INCUBATING_SCHEMA_TEST_DIR)]);

    expect($migrations)->toHaveCount(1)
        ->and($migrations[0]['file'])->toBe(INCUBATING_SCHEMA_TEST_FILE)
        ->and($migrations[0]['tables'])->toBe([INCUBATING_SCHEMA_TEST_TABLE]);
});

test('preflight can resolve incubating state for a registered table from trait metadata', function (): void {
    writeIncubatingTestMigration(INCUBATING_SCHEMA_TEST_DIR, INCUBATING_SCHEMA_TEST_FILE, INCUBATING_SCHEMA_TEST_TABLE);

    TableRegistry::query()->create([
        'table_name' => INCUBATING_SCHEMA_TEST_TABLE,
        'module_name' => 'test-mod',
        'module_path' => INCUBATING_SCHEMA_TEST_MODULE_PATH,
        'migration_file' => INCUBATING_SCHEMA_TEST_FILE,
    ]);

    expect(app(IncubatingSchemaPreflight::class)->tableIsIncubating(INCUBATING_SCHEMA_TEST_TABLE))->toBeTrue();
});

test('preflight cascades the rebuild into stable tables that depend on an incubating table', function (): void {
    writeIncubatingTestMigration(INCUBATING_SCHEMA_TEST_DIR, INCUBATING_SCHEMA_TEST_FILE, INCUBATING_SCHEMA_TEST_TABLE);

    Schema::create(INCUBATING_SCHEMA_TEST_TABLE, function (Blueprint $table): void {
        $table->id();
    });

    Schema::create(INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE, function (Blueprint $table): void {
        $table->id();
        $table->foreignId('widget_id')->constrained(INCUBATING_SCHEMA_TEST_TABLE);
    });

    TableRegistry::query()->create([
        'table_name' => INCUBATING_SCHEMA_TEST_TABLE,
        'module_name' => 'test-mod',
        'module_path' => INCUBATING_SCHEMA_TEST_MODULE_PATH,
        'migration_file' => INCUBATING_SCHEMA_TEST_FILE,
    ]);

    TableRegistry::query()->create([
        'table_name' => INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE,
        'module_name' => 'test-mod',
        'module_path' => INCUBATING_SCHEMA_TEST_MODULE_PATH,
        'migration_file' => INCUBATING_SCHEMA_TEST_SINGLE_TABLE_DEPENDENT_NAME.'.php',
    ]);

    DB::table('migrations')->insert([
        ['migration' => INCUBATING_SCHEMA_TEST_FILE_NAME, 'batch' => 1],
        ['migration' => INCUBATING_SCHEMA_TEST_SINGLE_TABLE_DEPENDENT_NAME, 'batch' => 1],
    ]);

    $result = app(IncubatingSchemaPreflight::class)->run([base_path(INCUBATING_SCHEMA_TEST_DIR)]);

    expect($result['cascaded'])->toBe([INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE])
        ->and($result['tables'])->toContain(INCUBATING_SCHEMA_TEST_TABLE, INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE)
        ->and($result['migrations'])->toContain(INCUBATING_SCHEMA_TEST_FILE_NAME, INCUBATING_SCHEMA_TEST_SINGLE_TABLE_DEPENDENT_NAME)
        ->and(Schema::hasTable(INCUBATING_SCHEMA_TEST_TABLE))->toBeFalse()
        ->and(Schema::hasTable(INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', INCUBATING_SCHEMA_TEST_SINGLE_TABLE_DEPENDENT_NAME)->exists())->toBeFalse();
});

test('preflight rebuilds every live table owned by a cascaded dependent migration', function (): void {
    writeIncubatingTestMigration(INCUBATING_SCHEMA_TEST_DIR, INCUBATING_SCHEMA_TEST_FILE, INCUBATING_SCHEMA_TEST_TABLE);

    Schema::create(INCUBATING_SCHEMA_TEST_TABLE, function (Blueprint $table): void {
        $table->id();
    });

    Schema::create(INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE, function (Blueprint $table): void {
        $table->id();
        $table->foreignId('widget_id')->constrained(INCUBATING_SCHEMA_TEST_TABLE);
    });

    Schema::create(INCUBATING_SCHEMA_TEST_SIBLING_TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('note');
    });

    TableRegistry::query()->create([
        'table_name' => INCUBATING_SCHEMA_TEST_TABLE,
        'module_name' => 'test-mod',
        'module_path' => INCUBATING_SCHEMA_TEST_MODULE_PATH,
        'migration_file' => INCUBATING_SCHEMA_TEST_FILE,
    ]);

    foreach ([INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE, INCUBATING_SCHEMA_TEST_SIBLING_TABLE] as $tableName) {
        TableRegistry::query()->create([
            'table_name' => $tableName,
            'module_name' => 'test-mod',
            'module_path' => INCUBATING_SCHEMA_TEST_MODULE_PATH,
            'migration_file' => INCUBATING_SCHEMA_TEST_MULTI_TABLE_DEPENDENT_NAME.'.php',
        ]);
    }

    DB::table('migrations')->insert([
        ['migration' => INCUBATING_SCHEMA_TEST_FILE_NAME, 'batch' => 1],
        ['migration' => INCUBATING_SCHEMA_TEST_MULTI_TABLE_DEPENDENT_NAME, 'batch' => 1],
    ]);

    $result = app(IncubatingSchemaPreflight::class)->run([base_path(INCUBATING_SCHEMA_TEST_DIR)]);

    expect($result['cascaded'])->toContain(INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE, INCUBATING_SCHEMA_TEST_SIBLING_TABLE)
        ->and($result['tables'])->toContain(INCUBATING_SCHEMA_TEST_TABLE, INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE, INCUBATING_SCHEMA_TEST_SIBLING_TABLE)
        ->and($result['migrations'])->toContain(INCUBATING_SCHEMA_TEST_FILE_NAME, INCUBATING_SCHEMA_TEST_MULTI_TABLE_DEPENDENT_NAME)
        ->and(Schema::hasTable(INCUBATING_SCHEMA_TEST_TABLE))->toBeFalse()
        ->and(Schema::hasTable(INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE))->toBeFalse()
        ->and(Schema::hasTable(INCUBATING_SCHEMA_TEST_SIBLING_TABLE))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', INCUBATING_SCHEMA_TEST_MULTI_TABLE_DEPENDENT_NAME)->exists())->toBeFalse();
});
