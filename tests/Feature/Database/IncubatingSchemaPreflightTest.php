<?php

use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\IncubatingSchemaPreflight;

const INCUBATING_SCHEMA_TEST_DIR = 'extensions/test-vendor/test-mod/Database/Migrations';
const INCUBATING_SCHEMA_TEST_FILE = '2099_01_01_000000_create_test_incubating_widgets_table.php';
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

test('preflight can resolve incubating state for a registered table from source metadata', function (): void {
    $dir = base_path(INCUBATING_SCHEMA_TEST_DIR);
    $file = $dir.'/'.INCUBATING_SCHEMA_TEST_FILE;

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
        'is_stable' => true,
        'stabilized_at' => now(),
    ]);

    expect(app(IncubatingSchemaPreflight::class)->tableIsIncubating(INCUBATING_SCHEMA_TEST_TABLE))->toBeTrue();
});
