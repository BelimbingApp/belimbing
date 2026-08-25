<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const RETIRED_TABLE_STABILITY_CONNECTION = 'retired_table_stability';
const RETIRED_TABLE_STABILITY_TABLE = 'base_database_tables';

function retiredTableStabilityMigration(): Migration
{
    return require app_path(
        'Base/Database/Database/Migrations/0001_01_01_000005_drop_retired_table_stability_column.php',
    );
}

/** @return list<string> */
function retiredTableStabilityIndexNames(): array
{
    return array_column(Schema::getIndexes(RETIRED_TABLE_STABILITY_TABLE), 'name');
}

test('retired table stability migration removes and restores the historical SQLite shape', function (): void {
    $originalConnection = DB::getDefaultConnection();
    $originalDefault = config('database.default');
    config()->set('database.connections.'.RETIRED_TABLE_STABILITY_CONNECTION, [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', RETIRED_TABLE_STABILITY_CONNECTION);
    DB::purge(RETIRED_TABLE_STABILITY_CONNECTION);
    DB::setDefaultConnection(RETIRED_TABLE_STABILITY_CONNECTION);

    try {
        Schema::create(RETIRED_TABLE_STABILITY_TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('table_name')->unique();
            $table->string('module_name')->nullable()->index();
            $table->string('module_path')->nullable()->index();
            $table->string('migration_file')->nullable()->index();
            $table->boolean('is_stable')->default(true);
            $table->timestamp('stabilized_at')->nullable();
            $table->unsignedBigInteger('stabilized_by')->nullable();
            $table->timestamps();
            $table->index('is_stable', 'base_database_tables_is_stable_index');
            $table->index(['module_name', 'is_stable'], 'base_database_tables_module_name_is_stable_index');
        });

        expect(Schema::hasColumn(RETIRED_TABLE_STABILITY_TABLE, 'is_stable'))->toBeTrue()
            ->and(retiredTableStabilityIndexNames())->toContain(
                'base_database_tables_is_stable_index',
                'base_database_tables_module_name_is_stable_index',
            );

        $migration = retiredTableStabilityMigration();
        $migration->up();

        expect(Schema::hasColumn(RETIRED_TABLE_STABILITY_TABLE, 'is_stable'))->toBeFalse()
            ->and(retiredTableStabilityIndexNames())->not->toContain(
                'base_database_tables_is_stable_index',
                'base_database_tables_module_name_is_stable_index',
            );

        $migration->down();

        DB::table(RETIRED_TABLE_STABILITY_TABLE)->insert(['table_name' => 'restored-shape-probe']);

        expect(Schema::hasColumn(RETIRED_TABLE_STABILITY_TABLE, 'is_stable'))->toBeTrue()
            ->and(DB::table(RETIRED_TABLE_STABILITY_TABLE)->value('is_stable'))->toBe(1)
            ->and(retiredTableStabilityIndexNames())->toContain(
                'base_database_tables_is_stable_index',
                'base_database_tables_module_name_is_stable_index',
            );
    } finally {
        DB::setDefaultConnection($originalConnection);
        config()->set('database.default', $originalDefault);
        DB::purge(RETIRED_TABLE_STABILITY_CONNECTION);
    }
});
