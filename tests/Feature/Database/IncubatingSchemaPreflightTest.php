<?php

use App\Base\Database\Exceptions\IncubatingSchemaConflictException;
use App\Base\Database\Exceptions\IncubatingSchemaDependencyException;
use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\IncubatingMigrationFiles;
use App\Base\Database\Services\IncubatingSchemaPreflight;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const INCUBATING_SCHEMA_TEST_MODULE_PATH = 'app/Extensions/TestVendor/TestMod';
const INCUBATING_SCHEMA_TEST_DIR = INCUBATING_SCHEMA_TEST_MODULE_PATH.'/Database/Migrations';
const INCUBATING_SCHEMA_DEPENDENT_TEST_DIR = 'app/Extensions/TestVendor/TestDependent/Database/Migrations';
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
const INCUBATING_SCHEMA_TEST_FORWARD_NAME = '2099_01_01_000003_add_mature_value_to_test_incubating_widgets_table';
const INCUBATING_SCHEMA_TEST_FORWARD_FILE = INCUBATING_SCHEMA_TEST_FORWARD_NAME.'.php';
const INCUBATING_SCHEMA_TEST_PRESERVED_VALUE = 'preserve me';

function writeIncubatingSchemaForwardTestMigration(
    bool $incubating,
    bool $replayable = false,
    bool $schemaChange = true,
): void {
    $directory = base_path(INCUBATING_SCHEMA_DEPENDENT_TEST_DIR);

    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    $marker = match (true) {
        $incubating => 'IncubatingSchema',
        $replayable => 'ReplaysAfterIncubatingSchema',
        default => null,
    };
    $import = $marker !== null ? "use App\\Base\\Database\\Concerns\\{$marker};\n" : '';
    $trait = $marker !== null ? "        use {$marker};\n\n" : '';
    $operationImports = $schemaChange
        ? "use Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;"
        : 'use Illuminate\Support\Facades\DB;';
    $operation = $schemaChange
        ? <<<'PHP'
            Schema::table('test_incubating_widgets', function (Blueprint $table): void {
                $table->string('mature_value');
            });
        PHP
        : <<<'PHP'
            DB::table('test_incubating_widgets')->updateOrInsert(['id' => 1], ['id' => 1]);
        PHP;

    file_put_contents($directory.'/'.INCUBATING_SCHEMA_TEST_FORWARD_FILE, <<<PHP
    <?php
    {$import}use Illuminate\Database\Migrations\Migration;
    {$operationImports}

    return new class extends Migration
    {
    {$trait}    public function up(): void
        {
    {$operation}
        }
    };
    PHP);
}

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
        cleanupIncubatingTestMigration(
            INCUBATING_SCHEMA_DEPENDENT_TEST_DIR,
            INCUBATING_SCHEMA_TEST_FORWARD_FILE,
            INCUBATING_SCHEMA_TEST_TABLE,
        );
        TableRegistry::query()->where('table_name', INCUBATING_SCHEMA_TEST_TABLE)->delete();

        DB::table('migrations')->whereIn('migration', [
            INCUBATING_SCHEMA_TEST_SINGLE_TABLE_DEPENDENT_NAME,
            INCUBATING_SCHEMA_TEST_MULTI_TABLE_DEPENDENT_NAME,
            INCUBATING_SCHEMA_TEST_CYCLE_DEPENDENT_NAME,
            INCUBATING_SCHEMA_TEST_FORWARD_NAME,
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

test('incubating metadata detection ignores imports comments strings and closure captures', function (): void {
    $migrationFiles = app(IncubatingMigrationFiles::class);

    $nonDeclarations = [
        '<?php use App\\Base\\Database\\Concerns\\IncubatingSchema;',
        '<?php // use IncubatingSchema;',
        '<?php $example = "use IncubatingSchema;";',
        '<?php return new class { public function run() { return function () use ($value) {}; } };',
    ];

    foreach ($nonDeclarations as $contents) {
        expect($migrationFiles->contentsAreIncubating($contents))->toBeFalse();
    }

    expect($migrationFiles->contentsAreIncubating(
        '<?php return new class extends Migration { use IncubatingSchema; };'
    ))->toBeTrue()
        ->and($migrationFiles->contentsReplayAfterIncubatingSchema(
            '<?php return new class extends Migration { use ReplaysAfterIncubatingSchema; };'
        ))->toBeTrue()
        ->and($migrationFiles->contentsReplayAfterIncubatingSchema(
            '<?php use App\\Base\\Database\\Concerns\\ReplaysAfterIncubatingSchema;'
        ))->toBeFalse();
});

test('created table detection ignores comments and strings', function (): void {
    $contents = <<<'PHP'
    <?php
    // Schema::create('commented_table', fn () => null);
    $example = "Schema::create('string_table', fn () => null);";
    Schema::create('declared_table', fn () => null);
    PHP;

    $migrationFiles = app(IncubatingMigrationFiles::class);

    expect($migrationFiles->createdTables($contents))
        ->toBe(['declared_table'])
        ->and($migrationFiles->referencedTables(
            <<<'PHP'
            <?php
            // DB::statement('ALTER TABLE ignored_table ADD COLUMN value TEXT');
            DB::statement('ALTER TABLE declared_table ADD COLUMN value TEXT');
            PHP,
            ['declared_table', 'ignored_table'],
        ))->toBe(['declared_table']);
});

test('replayable data-only detection rejects schema builders and raw DDL', function (): void {
    $migrationFiles = app(IncubatingMigrationFiles::class);
    $dataOnly = <<<'PHP'
    <?php
    return new class extends Migration {
        use ReplaysAfterIncubatingSchema;

        public function up(): void
        {
            DB::table('widgets')->updateOrInsert(['id' => 1], ['state' => 'ready']);
        }
    };
    PHP;
    $schemaBuilder = <<<'PHP'
    <?php
    return new class extends Migration {
        use ReplaysAfterIncubatingSchema;

        public function up(): void
        {
            Schema::table('widgets', fn (Blueprint $table) => $table->string('state'));
        }
    };
    PHP;
    $schemaIntrospection = <<<'PHP'
    <?php
    return new class extends Migration {
        use ReplaysAfterIncubatingSchema;

        public function up(): void
        {
            if (Schema::hasTable('widgets') && Schema::hasColumn('widgets', 'state')) {
                DB::table('widgets')->update(['state' => 'ready']);
            }
        }
    };
    PHP;
    $dynamicSchemaBuilder = <<<'PHP'
    <?php
    return new class extends Migration {
        use ReplaysAfterIncubatingSchema;

        public function up(): void
        {
            $operation = 'table';
            Schema::{$operation}('widgets', fn (Blueprint $table) => $table->string('state'));
        }
    };
    PHP;
    $rawDdl = <<<'PHP'
    <?php
    return new class extends Migration {
        use ReplaysAfterIncubatingSchema;

        public function up(): void
        {
            DB::unprepared('ALTER TABLE widgets ADD COLUMN state TEXT');
        }
    };
    PHP;

    expect($migrationFiles->replayAfterIncubatingSchemaViolations($dataOnly))->toBe([])
        ->and($migrationFiles->replayAfterIncubatingSchemaViolations($schemaBuilder))->toBe(['Schema::table()'])
        ->and($migrationFiles->replayAfterIncubatingSchemaViolations($schemaIntrospection))->toBe([])
        ->and($migrationFiles->replayAfterIncubatingSchemaViolations($dynamicSchemaBuilder))->toBe(['dynamic Schema call'])
        ->and($migrationFiles->replayAfterIncubatingSchemaViolations($rawDdl))->toBe(['unprepared()', 'raw DDL']);
});

test('preflight refuses a live table claimed by a different migration before dropping it', function (): void {
    writeIncubatingTestMigration(
        INCUBATING_SCHEMA_TEST_DIR,
        INCUBATING_SCHEMA_TEST_FILE,
        INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE,
    );

    Schema::create(INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('mature_value');
    });

    DB::table(INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE)->insert(['mature_value' => INCUBATING_SCHEMA_TEST_PRESERVED_VALUE]);

    TableRegistry::query()->create([
        'table_name' => INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE,
        'module_name' => 'test-mod',
        'module_path' => INCUBATING_SCHEMA_TEST_MODULE_PATH,
        'migration_file' => INCUBATING_SCHEMA_TEST_SINGLE_TABLE_DEPENDENT_NAME.'.php',
    ]);

    DB::table('migrations')->insert([
        'migration' => INCUBATING_SCHEMA_TEST_SINGLE_TABLE_DEPENDENT_NAME,
        'batch' => 1,
    ]);

    expect(fn () => app(IncubatingSchemaPreflight::class)->run([base_path(INCUBATING_SCHEMA_TEST_DIR)]))
        ->toThrow(IncubatingSchemaConflictException::class, 'conflicts with live schema');

    expect(Schema::hasTable(INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE))->toBeTrue()
        ->and(DB::table(INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE)->value('mature_value'))->toBe(INCUBATING_SCHEMA_TEST_PRESERVED_VALUE)
        ->and(DB::table('migrations')->where('migration', INCUBATING_SCHEMA_TEST_SINGLE_TABLE_DEPENDENT_NAME)->exists())->toBeTrue();
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
        'mature_value' => INCUBATING_SCHEMA_TEST_PRESERVED_VALUE,
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
        ->and(DB::table(INCUBATING_SCHEMA_TEST_DEPENDENT_TABLE)->value('mature_value'))->toBe(INCUBATING_SCHEMA_TEST_PRESERVED_VALUE)
        ->and(DB::table('migrations')->where('migration', INCUBATING_SCHEMA_TEST_FILE_NAME)->exists())->toBeTrue()
        ->and(DB::table('migrations')->where('migration', INCUBATING_SCHEMA_TEST_SINGLE_TABLE_DEPENDENT_NAME)->exists())->toBeTrue();
});

test('preflight refuses to replay a table past an applied stable forward migration', function (): void {
    writeIncubatingTestMigration(INCUBATING_SCHEMA_TEST_DIR, INCUBATING_SCHEMA_TEST_FILE, INCUBATING_SCHEMA_TEST_TABLE);
    writeIncubatingSchemaForwardTestMigration(incubating: false);

    Schema::create(INCUBATING_SCHEMA_TEST_TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('mature_value');
    });

    DB::table(INCUBATING_SCHEMA_TEST_TABLE)->insert(['mature_value' => INCUBATING_SCHEMA_TEST_PRESERVED_VALUE]);

    TableRegistry::query()->create([
        'table_name' => INCUBATING_SCHEMA_TEST_TABLE,
        'module_name' => 'test-mod',
        'module_path' => INCUBATING_SCHEMA_TEST_MODULE_PATH,
        'migration_file' => INCUBATING_SCHEMA_TEST_FILE,
    ]);

    DB::table('migrations')->insert([
        ['migration' => INCUBATING_SCHEMA_TEST_FILE_NAME, 'batch' => 1],
        ['migration' => INCUBATING_SCHEMA_TEST_FORWARD_NAME, 'batch' => 2],
    ]);

    expect(fn () => app(IncubatingSchemaPreflight::class)->run([
        base_path(INCUBATING_SCHEMA_TEST_DIR),
        base_path(INCUBATING_SCHEMA_DEPENDENT_TEST_DIR),
    ]))->toThrow(IncubatingSchemaConflictException::class, 'applied stable migration');

    expect(Schema::hasTable(INCUBATING_SCHEMA_TEST_TABLE))->toBeTrue()
        ->and(DB::table(INCUBATING_SCHEMA_TEST_TABLE)->value('mature_value'))->toBe(INCUBATING_SCHEMA_TEST_PRESERVED_VALUE)
        ->and(DB::table('migrations')->where('migration', INCUBATING_SCHEMA_TEST_FORWARD_NAME)->exists())->toBeTrue();
});

test('preflight replays an explicitly idempotent data migration after rebuilding a referenced table', function (): void {
    writeIncubatingTestMigration(INCUBATING_SCHEMA_TEST_DIR, INCUBATING_SCHEMA_TEST_FILE, INCUBATING_SCHEMA_TEST_TABLE);
    writeIncubatingSchemaForwardTestMigration(incubating: false, replayable: true, schemaChange: false);

    Schema::create(INCUBATING_SCHEMA_TEST_TABLE, function (Blueprint $table): void {
        $table->id();
    });

    TableRegistry::query()->create([
        'table_name' => INCUBATING_SCHEMA_TEST_TABLE,
        'module_name' => 'test-mod',
        'module_path' => INCUBATING_SCHEMA_TEST_MODULE_PATH,
        'migration_file' => INCUBATING_SCHEMA_TEST_FILE,
    ]);

    DB::table('migrations')->insert([
        ['migration' => INCUBATING_SCHEMA_TEST_FILE_NAME, 'batch' => 1],
        ['migration' => INCUBATING_SCHEMA_TEST_FORWARD_NAME, 'batch' => 2],
    ]);

    $result = app(IncubatingSchemaPreflight::class)->run([
        base_path(INCUBATING_SCHEMA_TEST_DIR),
        base_path(INCUBATING_SCHEMA_DEPENDENT_TEST_DIR),
    ]);

    expect($result['migrations'])->toContain(
        INCUBATING_SCHEMA_TEST_FILE_NAME,
        INCUBATING_SCHEMA_TEST_FORWARD_NAME,
    )
        ->and(Schema::hasTable(INCUBATING_SCHEMA_TEST_TABLE))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', INCUBATING_SCHEMA_TEST_FORWARD_NAME)->exists())->toBeFalse();
});

test('preflight refuses a replay marker on a schema-changing migration before dropping any table', function (): void {
    writeIncubatingTestMigration(INCUBATING_SCHEMA_TEST_DIR, INCUBATING_SCHEMA_TEST_FILE, INCUBATING_SCHEMA_TEST_TABLE);
    writeIncubatingSchemaForwardTestMigration(incubating: false, replayable: true);

    Schema::create(INCUBATING_SCHEMA_TEST_TABLE, function (Blueprint $table): void {
        $table->id();
    });

    TableRegistry::query()->create([
        'table_name' => INCUBATING_SCHEMA_TEST_TABLE,
        'module_name' => 'test-mod',
        'module_path' => INCUBATING_SCHEMA_TEST_MODULE_PATH,
        'migration_file' => INCUBATING_SCHEMA_TEST_FILE,
    ]);

    DB::table('migrations')->insert([
        ['migration' => INCUBATING_SCHEMA_TEST_FILE_NAME, 'batch' => 1],
        ['migration' => INCUBATING_SCHEMA_TEST_FORWARD_NAME, 'batch' => 2],
    ]);

    expect(fn () => app(IncubatingSchemaPreflight::class)->run([
        base_path(INCUBATING_SCHEMA_TEST_DIR),
        base_path(INCUBATING_SCHEMA_DEPENDENT_TEST_DIR),
    ]))->toThrow(IncubatingSchemaConflictException::class, 'is not data-only');

    expect(Schema::hasTable(INCUBATING_SCHEMA_TEST_TABLE))->toBeTrue()
        ->and(DB::table('migrations')->where('migration', INCUBATING_SCHEMA_TEST_FORWARD_NAME)->exists())->toBeTrue();
});

test('preflight replays the complete chain when later migrations are incubating too', function (): void {
    writeIncubatingTestMigration(INCUBATING_SCHEMA_TEST_DIR, INCUBATING_SCHEMA_TEST_FILE, INCUBATING_SCHEMA_TEST_TABLE);
    writeIncubatingSchemaForwardTestMigration(incubating: true);

    Schema::create(INCUBATING_SCHEMA_TEST_TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('mature_value');
    });

    TableRegistry::query()->create([
        'table_name' => INCUBATING_SCHEMA_TEST_TABLE,
        'module_name' => 'test-mod',
        'module_path' => INCUBATING_SCHEMA_TEST_MODULE_PATH,
        'migration_file' => INCUBATING_SCHEMA_TEST_FILE,
    ]);

    DB::table('migrations')->insert([
        ['migration' => INCUBATING_SCHEMA_TEST_FILE_NAME, 'batch' => 1],
        ['migration' => INCUBATING_SCHEMA_TEST_FORWARD_NAME, 'batch' => 2],
    ]);

    $result = app(IncubatingSchemaPreflight::class)->run([
        base_path(INCUBATING_SCHEMA_TEST_DIR),
        base_path(INCUBATING_SCHEMA_DEPENDENT_TEST_DIR),
    ]);

    expect($result['migrations'])->toContain(
        INCUBATING_SCHEMA_TEST_FILE_NAME,
        INCUBATING_SCHEMA_TEST_FORWARD_NAME,
    )->and(Schema::hasTable(INCUBATING_SCHEMA_TEST_TABLE))->toBeFalse();
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
