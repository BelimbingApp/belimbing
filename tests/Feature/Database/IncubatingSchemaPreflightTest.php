<?php

use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\IncubatingSchemaPreflight;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const INCUBATING_SCHEMA_TEST_DIR = 'extensions/test-vendor/test-mod/Database/Migrations';
const INCUBATING_SCHEMA_TEST_FILE = '2099_01_01_000000_create_test_incubating_widgets_table.php';
const INCUBATING_SCHEMA_TEST_FILE_NAME = '2099_01_01_000000_create_test_incubating_widgets_table';
const INCUBATING_SCHEMA_TEST_TABLE = 'test_incubating_widgets';

afterEach(function (): void {
    $file = base_path(INCUBATING_SCHEMA_TEST_DIR.'/'.INCUBATING_SCHEMA_TEST_FILE);
    $dir = dirname($file);

    TableRegistry::query()->where('table_name', INCUBATING_SCHEMA_TEST_TABLE)->delete();

    if (is_file($file)) {
        @unlink($file);
    }

    @rmdir($dir);
    @rmdir(dirname($dir));
    @rmdir(dirname($dir, 2));
    @rmdir(dirname($dir, 3));
});

test('preflight discovers incubating migrations declared with trait metadata', function (): void {
    $dir = base_path(INCUBATING_SCHEMA_TEST_DIR);
    $file = $dir.'/'.INCUBATING_SCHEMA_TEST_FILE;

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($file, <<<'PHP'
<?php
use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use IncubatingSchema;

    public function up(): void
    {
        Schema::create('test_incubating_widgets', function (Blueprint $table): void {
            $table->id();
        });
    }
};
PHP);

    $migrations = app(IncubatingSchemaPreflight::class)->incubatingMigrations([$dir]);

    expect($migrations)->toHaveCount(1)
        ->and($migrations[0]['file'])->toBe(INCUBATING_SCHEMA_TEST_FILE)
        ->and($migrations[0]['tables'])->toBe([INCUBATING_SCHEMA_TEST_TABLE]);
});

test('preflight can resolve incubating state for a registered table from trait metadata', function (): void {
    $dir = base_path(INCUBATING_SCHEMA_TEST_DIR);
    $file = $dir.'/'.INCUBATING_SCHEMA_TEST_FILE;

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($file, <<<'PHP'
<?php
use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use IncubatingSchema;

    public function up(): void
    {
        Schema::create('test_incubating_widgets', function (Blueprint $table): void {
            $table->id();
        });
    }
};
PHP);

    TableRegistry::query()->create([
        'table_name' => INCUBATING_SCHEMA_TEST_TABLE,
        'module_name' => 'test-mod',
        'module_path' => 'extensions/test-vendor/test-mod',
        'migration_file' => INCUBATING_SCHEMA_TEST_FILE,
    ]);

    expect(app(IncubatingSchemaPreflight::class)->tableIsIncubating(INCUBATING_SCHEMA_TEST_TABLE))->toBeTrue();
});

test('preflight cascades the rebuild into stable tables that depend on an incubating table', function (): void {
    $dir = base_path(INCUBATING_SCHEMA_TEST_DIR);
    $file = $dir.'/'.INCUBATING_SCHEMA_TEST_FILE;

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($file, <<<'PHP'
<?php
use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use IncubatingSchema;

    public function up(): void
    {
        Schema::create('test_incubating_widgets', function (Blueprint $table): void {
            $table->id();
        });
    }
};
PHP);

    $dependentTable = 'test_stable_widget_parts';
    $dependentMigrationFile = '2099_01_01_000001_create_test_stable_widget_parts_table.php';
    $dependentMigrationName = '2099_01_01_000001_create_test_stable_widget_parts_table';

    Schema::create(INCUBATING_SCHEMA_TEST_TABLE, function (Blueprint $table): void {
        $table->id();
    });

    Schema::create($dependentTable, function (Blueprint $table): void {
        $table->id();
        $table->foreignId('widget_id')->constrained(INCUBATING_SCHEMA_TEST_TABLE);
    });

    TableRegistry::query()->create([
        'table_name' => INCUBATING_SCHEMA_TEST_TABLE,
        'module_name' => 'test-mod',
        'module_path' => 'extensions/test-vendor/test-mod',
        'migration_file' => INCUBATING_SCHEMA_TEST_FILE,
    ]);

    TableRegistry::query()->create([
        'table_name' => $dependentTable,
        'module_name' => 'test-mod',
        'module_path' => 'extensions/test-vendor/test-mod',
        'migration_file' => $dependentMigrationFile,
    ]);

    DB::table('migrations')->insert([
        ['migration' => INCUBATING_SCHEMA_TEST_FILE_NAME, 'batch' => 1],
        ['migration' => $dependentMigrationName, 'batch' => 1],
    ]);

    try {
        $result = app(IncubatingSchemaPreflight::class)->run([$dir]);

        expect($result['cascaded'])->toBe([$dependentTable])
            ->and($result['tables'])->toContain(INCUBATING_SCHEMA_TEST_TABLE, $dependentTable)
            ->and($result['migrations'])->toContain(INCUBATING_SCHEMA_TEST_FILE_NAME, $dependentMigrationName)
            ->and(Schema::hasTable(INCUBATING_SCHEMA_TEST_TABLE))->toBeFalse()
            ->and(Schema::hasTable($dependentTable))->toBeFalse()
            ->and(DB::table('migrations')->where('migration', $dependentMigrationName)->exists())->toBeFalse();
    } finally {
        Schema::dropIfExists($dependentTable);
        Schema::dropIfExists(INCUBATING_SCHEMA_TEST_TABLE);
        TableRegistry::query()->where('table_name', $dependentTable)->delete();
        DB::table('migrations')->whereIn('migration', [INCUBATING_SCHEMA_TEST_FILE_NAME, $dependentMigrationName])->delete();
    }
});
