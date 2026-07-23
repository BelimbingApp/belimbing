<?php

use App\Base\Database\Exceptions\IncubatingSchemaDependencyException;
use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\IncubatingSchemaPreflight;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const INCUBATING_SCHEMA_TEST_MODULE_PATH = 'extensions/test-vendor/test-mod';
const INCUBATING_SCHEMA_TEST_DIR = INCUBATING_SCHEMA_TEST_MODULE_PATH.'/Database/Migrations';
const INCUBATING_SCHEMA_DEPENDENT_TEST_DIR = 'extensions/test-vendor/test-dependent/Database/Migrations';
const INCUBATING_SCHEMA_TEST_FILE = '2099_01_01_000000_create_test_incubating_widgets_table.php';
const INCUBATING_SCHEMA_TEST_FILE_NAME = '2099_01_01_000000_create_test_incubating_widgets_table';
const INCUBATING_SCHEMA_TEST_TABLE = 'test_incubating_widgets';
const INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE = 'test_stable_widget_parts';
const INCUBATING_SCHEMA_TEST_SIBLING_TABLE = 'test_stable_widget_part_notes';
const INCUBATING_SCHEMA_TEST_CYCLE_TABLE = 'test_incubating_widget_cycles';
const INCUBATING_SCHEMA_TEST_SINGLE_TABLE_DEPENDENT_NAME = '2099_01_01_000001_create_test_stable_widget_parts_table';
const INCUBATING_SCHEMA_TEST_MULTI_TABLE_DEPENDENT_NAME = '2099_01_01_000001_create_test_stable_widget_part_tables';
const INCUBATING_SCHEMA_TEST_MULTI_TABLE_DEPENDENT_FILE = INCUBATING_SCHEMA_TEST_MULTI_TABLE_DEPENDENT_NAME.'.php';
const INCUBATING_SCHEMA_TEST_CYCLE_DEPENDENT_NAME = '2099_01_01_000002_create_test_incubating_widget_cycles_table';
const INCUBATING_SCHEMA_TEST_CYCLE_DEPENDENT_FILE = INCUBATING_SCHEMA_TEST_CYCLE_DEPENDENT_NAME.'.php';

afterEach(function (): void {
    $connection = Schema::getConnection();
    $deferForeignKeys = $connection->getDriverName() === 'sqlite' && $connection->transactionLevel() > 0;

    if ($deferForeignKeys) {
        $connection->statement('PRAGMA defer_foreign_keys = ON');
    }

    Schema::disableForeignKeyConstraints();

    try {
        // Dependent/sibling/cycle tables from the cascade tests (no on-disk migration file).
        foreach ([
            INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE,
            INCUBATING_SCHEMA_TEST_SIBLING_TABLE,
            INCUBATING_SCHEMA_TEST_CYCLE_TABLE,
        ] as $table) {
            Schema::dropIfExists($table);
            TableRegistry::query()->where('table_name', $table)->delete();
        }

        // Incubating migration: drop table, clear ledger rows, remove the file.
        cleanupIncubatingTestMigration(INCUBATING_SCHEMA_TEST_DIR, INCUBATING_SCHEMA_TEST_FILE, INCUBATING_SCHEMA_TEST_TABLE);
        cleanupIncubatingTestMigration(
            INCUBATING_SCHEMA_DEPENDENT_TEST_DIR,
            INCUBATING_SCHEMA_TEST_MULTI_TABLE_DEPENDENT_FILE,
            INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE,
        );
        cleanupIncubatingTestMigration(
            INCUBATING_SCHEMA_DEPENDENT_TEST_DIR,
            INCUBATING_SCHEMA_TEST_CYCLE_DEPENDENT_FILE,
            INCUBATING_SCHEMA_TEST_CYCLE_TABLE,
        );
        TableRegistry::query()->where('table_name', INCUBATING_SCHEMA_TEST_TABLE)->delete();

        DB::table('migrations')->whereIn('migration', [
            INCUBATING_SCHEMA_TEST_SINGLE_TABLE_DEPENDENT_NAME,
            INCUBATING_SCHEMA_TEST_MULTI_TABLE_DEPENDENT_NAME,
            INCUBATING_SCHEMA_TEST_CYCLE_DEPENDENT_NAME,
        ])->delete();
    } finally {
        Schema::enableForeignKeyConstraints();

        if ($deferForeignKeys) {
            $connection->statement('PRAGMA defer_foreign_keys = OFF');
        }
    }
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

test('preflight refuses stable dependents before dropping any table', function (): void {
    writeIncubatingTestMigration(INCUBATING_SCHEMA_TEST_DIR, INCUBATING_SCHEMA_TEST_FILE, INCUBATING_SCHEMA_TEST_TABLE);

    Schema::create(INCUBATING_SCHEMA_TEST_TABLE, function (Blueprint $table): void {
        $table->id();
    });

    Schema::create(INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE, function (Blueprint $table): void {
        $table->id();
        $table->foreignId('widget_id')->constrained(INCUBATING_SCHEMA_TEST_TABLE);
        $table->string('mature_value');
    });

    $widgetId = DB::table(INCUBATING_SCHEMA_TEST_TABLE)->insertGetId([]);
    DB::table(INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE)->insert([
        'widget_id' => $widgetId,
        'mature_value' => 'preserve me',
    ]);

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

    expect(fn () => app(IncubatingSchemaPreflight::class)->run([base_path(INCUBATING_SCHEMA_TEST_DIR)]))
        ->toThrow(IncubatingSchemaDependencyException::class, 'non-incubating tables depend on it');

    expect(Schema::hasTable(INCUBATING_SCHEMA_TEST_TABLE))->toBeTrue()
        ->and(Schema::hasTable(INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE))->toBeTrue()
        ->and(DB::table(INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE)->value('mature_value'))->toBe('preserve me')
        ->and(DB::table('migrations')->where('migration', INCUBATING_SCHEMA_TEST_FILE_NAME)->exists())->toBeTrue()
        ->and(DB::table('migrations')->where('migration', INCUBATING_SCHEMA_TEST_SINGLE_TABLE_DEPENDENT_NAME)->exists())->toBeTrue();
});

test('preflight rebuilds every live table owned by a cascaded incubating migration', function (): void {
    writeIncubatingTestMigration(INCUBATING_SCHEMA_TEST_DIR, INCUBATING_SCHEMA_TEST_FILE, INCUBATING_SCHEMA_TEST_TABLE);
    writeIncubatingTestMigration(
        INCUBATING_SCHEMA_DEPENDENT_TEST_DIR,
        INCUBATING_SCHEMA_TEST_MULTI_TABLE_DEPENDENT_FILE,
        INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE,
    );

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
            'migration_file' => INCUBATING_SCHEMA_TEST_MULTI_TABLE_DEPENDENT_FILE,
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

test('preflight drops mutually-referencing tables through the foreign-key cycle fallback', function (): void {
    writeIncubatingTestMigration(INCUBATING_SCHEMA_TEST_DIR, INCUBATING_SCHEMA_TEST_FILE, INCUBATING_SCHEMA_TEST_TABLE);
    writeIncubatingTestMigration(
        INCUBATING_SCHEMA_DEPENDENT_TEST_DIR,
        INCUBATING_SCHEMA_TEST_CYCLE_DEPENDENT_FILE,
        INCUBATING_SCHEMA_TEST_CYCLE_TABLE,
    );

    Schema::create(INCUBATING_SCHEMA_TEST_TABLE, function (Blueprint $table): void {
        $table->id();
        $table->foreignId('cycle_id')->nullable()->constrained(INCUBATING_SCHEMA_TEST_CYCLE_TABLE);
    });

    Schema::create(INCUBATING_SCHEMA_TEST_CYCLE_TABLE, function (Blueprint $table): void {
        $table->id();
        $table->foreignId('widget_id')->constrained(INCUBATING_SCHEMA_TEST_TABLE);
    });

    // Rows that reference each other, so a DROP TABLE under foreign-key
    // enforcement would trip the implicit DELETE's RESTRICT and fail without
    // the cycle fallback disabling/deferring SQLite checks.
    $widgetId = DB::table(INCUBATING_SCHEMA_TEST_TABLE)->insertGetId([]);
    $cycleId = DB::table(INCUBATING_SCHEMA_TEST_CYCLE_TABLE)->insertGetId(['widget_id' => $widgetId]);
    DB::table(INCUBATING_SCHEMA_TEST_TABLE)->where('id', $widgetId)->update(['cycle_id' => $cycleId]);

    TableRegistry::query()->create([
        'table_name' => INCUBATING_SCHEMA_TEST_TABLE,
        'module_name' => 'test-mod',
        'module_path' => INCUBATING_SCHEMA_TEST_MODULE_PATH,
        'migration_file' => INCUBATING_SCHEMA_TEST_FILE,
    ]);

    TableRegistry::query()->create([
        'table_name' => INCUBATING_SCHEMA_TEST_CYCLE_TABLE,
        'module_name' => 'test-mod',
        'module_path' => INCUBATING_SCHEMA_TEST_MODULE_PATH,
        'migration_file' => INCUBATING_SCHEMA_TEST_CYCLE_DEPENDENT_FILE,
    ]);

    DB::table('migrations')->insert([
        ['migration' => INCUBATING_SCHEMA_TEST_FILE_NAME, 'batch' => 1],
        ['migration' => INCUBATING_SCHEMA_TEST_CYCLE_DEPENDENT_NAME, 'batch' => 1],
    ]);

    $result = app(IncubatingSchemaPreflight::class)->run([base_path(INCUBATING_SCHEMA_TEST_DIR)]);

    expect($result['tables'])->toContain(INCUBATING_SCHEMA_TEST_TABLE, INCUBATING_SCHEMA_TEST_CYCLE_TABLE)
        ->and(Schema::hasTable(INCUBATING_SCHEMA_TEST_TABLE))->toBeFalse()
        ->and(Schema::hasTable(INCUBATING_SCHEMA_TEST_CYCLE_TABLE))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', INCUBATING_SCHEMA_TEST_FILE_NAME)->exists())->toBeFalse()
        ->and(DB::table('migrations')->where('migration', INCUBATING_SCHEMA_TEST_CYCLE_DEPENDENT_NAME)->exists())->toBeFalse();
});
