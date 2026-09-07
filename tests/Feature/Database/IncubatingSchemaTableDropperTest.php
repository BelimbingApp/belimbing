<?php

use App\Base\Database\Services\IncubatingSchemaTableDropper;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A DROP TABLE on SQLite performs an implicit DELETE when foreign keys are
 * on; that delete does not fire the dropped table's own triggers, but its
 * ON DELETE CASCADE actions do fire the triggers of referencing tables that
 * still hold rows. Cyclic incubating tables reach exactly that path: they
 * are dropped together, and inside a transaction SQLite ignores the
 * PRAGMA that would switch foreign keys off. A trigger there that calls a
 * connection-local PHP function (sqliteCreateFunction) fails with
 * "no such function" after a process restart and blocks the rebuild.
 */
const INCUBATING_DROP_PARENT = 'test_incubating_drop_parent';
const INCUBATING_DROP_CHILD = 'test_incubating_drop_child';

function createIncubatingDropCycle(): void
{
    Schema::dropIfExists(INCUBATING_DROP_CHILD);
    Schema::dropIfExists(INCUBATING_DROP_PARENT);

    Schema::create(INCUBATING_DROP_PARENT, function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('child_id')->nullable();
        $table->foreign('child_id')->references('id')->on(INCUBATING_DROP_CHILD)->cascadeOnDelete();
    });
    Schema::create(INCUBATING_DROP_CHILD, function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('parent_id');
        $table->foreign('parent_id')->references('id')->on(INCUBATING_DROP_PARENT)->cascadeOnDelete();
    });

    DB::unprepared(<<<'SQL'
        CREATE TRIGGER test_incubating_drop_child_guard
        BEFORE DELETE ON test_incubating_drop_child
        FOR EACH ROW
        BEGIN
            SELECT CASE WHEN missing_php_only_function() <> 1
                THEN RAISE(ABORT, 'blocked') END;
        END;
        SQL);

    DB::table(INCUBATING_DROP_PARENT)->insert(['id' => 1, 'child_id' => null]);
    DB::table(INCUBATING_DROP_CHILD)->insert(['id' => 1, 'parent_id' => 1]);
}

afterEach(function (): void {
    if (Schema::getConnection()->getDriverName() !== 'sqlite') {
        return; // nothing was created on other drivers; DROP TRIGGER syntax differs there
    }

    // The trigger survives a rolled-back drop; remove it before the plain drops.
    DB::unprepared('DROP TRIGGER IF EXISTS test_incubating_drop_child_guard');
    Schema::dropIfExists(INCUBATING_DROP_CHILD);
    Schema::dropIfExists(INCUBATING_DROP_PARENT);
});

test('a cascade into a table whose trigger calls a missing php function fails a plain drop inside a transaction', function (): void {
    if (Schema::getConnection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite-only: implicit DELETE on DROP TABLE and connection-local functions.');
    }

    createIncubatingDropCycle();

    expect(fn () => DB::table(INCUBATING_DROP_CHILD)->delete())->toThrow(QueryException::class);

    expect(fn () => DB::transaction(function (): void {
        Schema::disableForeignKeyConstraints();
        Schema::drop(INCUBATING_DROP_PARENT);
    }))->toThrow(QueryException::class, 'no such function');
});

test('sqlite incubating drops remove triggers of the planned tables before dropping a cycle inside a transaction', function (): void {
    if (Schema::getConnection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite-only: implicit DELETE on DROP TABLE and connection-local functions.');
    }

    createIncubatingDropCycle();

    $dropper = app(IncubatingSchemaTableDropper::class);
    $tables = [INCUBATING_DROP_PARENT, INCUBATING_DROP_CHILD];

    DB::transaction(fn () => $dropper->drop($tables, $dropper->foreignKeysByTable($tables)));

    expect(Schema::hasTable(INCUBATING_DROP_PARENT))->toBeFalse()
        ->and(Schema::hasTable(INCUBATING_DROP_CHILD))->toBeFalse()
        ->and(DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', INCUBATING_DROP_CHILD)->count())->toBe(0);
});
