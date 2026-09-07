<?php

use App\Base\Database\Services\IncubatingSchemaTableDropper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('sqlite incubating drops remove triggers that call missing php functions before drop table', function (): void {
    if (Schema::getConnection()->getDriverName() !== 'sqlite') {
        // Mechanism: sqliteCreateFunction + sqlite_master trigger cleanup.
        skip('SQLite-only trigger cleanup path.');
    }

    $table = 'test_incubating_sqlite_trigger_drop';

    Schema::dropIfExists($table);
    Schema::create($table, function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->string('status')->default('draft');
    });

    DB::unprepared(<<<SQL
        CREATE TRIGGER {$table}_guard
        BEFORE DELETE ON {$table}
        FOR EACH ROW
        BEGIN
            SELECT CASE WHEN missing_php_only_function() <> 1
                THEN RAISE(ABORT, 'blocked') END;
        END;
        SQL);

    DB::table($table)->insert(['status' => 'submitted']);

    expect(fn () => DB::table($table)->delete())
        ->toThrow(\Illuminate\Database\QueryException::class);

    $dropper = app(IncubatingSchemaTableDropper::class);
    $dropper->drop([$table], $dropper->foreignKeysByTable([$table]));

    expect(Schema::hasTable($table))->toBeFalse()
        ->and(DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', $table)->count())->toBe(0);
});
