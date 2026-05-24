<?php

use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\IncubatingSchemaPreflight;

const INCUBATING_SCHEMA_TEST_DIR = 'extensions/test-vendor/test-mod/Database/Migrations';
const INCUBATING_SCHEMA_TEST_FILE = '2099_01_01_000000_create_test_incubating_widgets_table.php';
const INCUBATING_SCHEMA_TEST_TABLE = 'test_incubating_widgets';
const INCUBATING_SCHEMA_TEST_DEPRECATED_SCRIPT = 'storage/framework/testing/deprecated-unstable-table-list.sh';
const INCUBATING_SCHEMA_TEST_DISABLED_SCRIPT = 'storage/framework/testing/nonexistent-deprecated-unstable-table-list.sh';

function pointPreflightAtDeprecatedScript(string $path): void
{
    putenv('BLB_DEPRECATED_UNSTABLE_TABLE_LIST='.$path);
    $_ENV['BLB_DEPRECATED_UNSTABLE_TABLE_LIST'] = $path;
    $_SERVER['BLB_DEPRECATED_UNSTABLE_TABLE_LIST'] = $path;
}

afterEach(function (): void {
    $file = base_path(INCUBATING_SCHEMA_TEST_DIR.'/'.INCUBATING_SCHEMA_TEST_FILE);
    $dir = dirname($file);
    $deprecatedScript = base_path(INCUBATING_SCHEMA_TEST_DEPRECATED_SCRIPT);

    TableRegistry::query()->where('table_name', INCUBATING_SCHEMA_TEST_TABLE)->delete();
    putenv('BLB_DEPRECATED_UNSTABLE_TABLE_LIST');
    unset($_ENV['BLB_DEPRECATED_UNSTABLE_TABLE_LIST'], $_SERVER['BLB_DEPRECATED_UNSTABLE_TABLE_LIST']);

    if (is_file($file)) {
        @unlink($file);
    }

    if (is_file($deprecatedScript)) {
        @unlink($deprecatedScript);
    }

    @rmdir($dir);
    @rmdir(dirname($dir));
    @rmdir(dirname($dir, 2));
    @rmdir(dirname($dir, 3));
});

test('preflight discovers incubating migrations declared with trait metadata', function (): void {
    $dir = base_path(INCUBATING_SCHEMA_TEST_DIR);
    $file = $dir.'/'.INCUBATING_SCHEMA_TEST_FILE;
    pointPreflightAtDeprecatedScript(base_path(INCUBATING_SCHEMA_TEST_DISABLED_SCRIPT));

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

test('preflight can resolve incubating state for a registered table from source metadata', function (): void {
    $dir = base_path(INCUBATING_SCHEMA_TEST_DIR);
    $file = $dir.'/'.INCUBATING_SCHEMA_TEST_FILE;
    pointPreflightAtDeprecatedScript(base_path(INCUBATING_SCHEMA_TEST_DISABLED_SCRIPT));

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($file, <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const BLB_SCHEMA_STABLE = false;

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

test('preflight honors deprecated git-tracked unstable table list as a compatibility bridge', function (): void {
    $dir = base_path(INCUBATING_SCHEMA_TEST_DIR);
    $file = $dir.'/'.INCUBATING_SCHEMA_TEST_FILE;
    $deprecatedScript = base_path(INCUBATING_SCHEMA_TEST_DEPRECATED_SCRIPT);

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    if (! is_dir(dirname($deprecatedScript))) {
        mkdir(dirname($deprecatedScript), 0755, true);
    }

    file_put_contents($file, <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_incubating_widgets', function (Blueprint $table): void {
            $table->id();
        });
    }
};
PHP);

    file_put_contents($deprecatedScript, <<<'SH'
#!/usr/bin/env bash
readonly BLB_DEPRECATED_UNSTABLE_TABLE_PATTERNS=(
  test_incubating_widgets
)
SH);

    pointPreflightAtDeprecatedScript($deprecatedScript);

    TableRegistry::query()->create([
        'table_name' => INCUBATING_SCHEMA_TEST_TABLE,
        'module_name' => 'test-mod',
        'module_path' => 'extensions/test-vendor/test-mod',
        'migration_file' => INCUBATING_SCHEMA_TEST_FILE,
    ]);

    $migrations = app(IncubatingSchemaPreflight::class)->incubatingMigrations([$dir]);
    $states = app(IncubatingSchemaPreflight::class)->schemaStatesForTables([INCUBATING_SCHEMA_TEST_TABLE]);

    expect($migrations)->toHaveCount(1)
        ->and($migrations[0]['file'])->toBe(INCUBATING_SCHEMA_TEST_FILE)
        ->and($states[INCUBATING_SCHEMA_TEST_TABLE])->toBe('incubating');
});
