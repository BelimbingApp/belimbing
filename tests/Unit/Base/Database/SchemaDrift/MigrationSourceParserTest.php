<?php

use App\Base\Database\Services\SchemaDrift\DeclaredIndexType;
use App\Base\Database\Services\SchemaDrift\MigrationSourceParser;
use App\Base\Database\Services\SchemaDrift\ParsedMigration;
use App\Base\Database\Services\SchemaDrift\TableOperationKind;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Parse a migration whose up() body is the given statements.
 *
 * The scaffold around a one-statement migration is ten identical lines. Written
 * out per case it is most of the case, which is both harder to read and enough
 * repetition to fail the duplication gate on new code.
 */
function parseMigrationUp(string $body): ParsedMigration
{
    return app(MigrationSourceParser::class)->parseContents(
        "<?php\nreturn new class {\n    public function up(): void\n    {\n".$body."\n    }\n};\n"
    );
}

it('replays migration operations in source order and matches Laravel fluent index priority', function (): void {
    $migration = <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('widgets', function (Blueprint $table): void {
                    $table->string('email')->index()->unique();
                    $table->dropUnique(['email']);
                    $table->index('email');
                });
            }
        };
        PHP;

    $parsed = app(MigrationSourceParser::class)->parseContents($migration);

    expect($parsed->unreadable)->toBe([])
        ->and(array_map(fn ($operation) => $operation->kind, $parsed->operations))->toBe([
            TableOperationKind::CREATE_TABLE,
            TableOperationKind::ADD_COLUMN,
            TableOperationKind::ADD_INDEX,
            TableOperationKind::DROP_INDEX,
            TableOperationKind::ADD_INDEX,
        ])
        ->and($parsed->operations[2]->index?->type)->toBe(DeclaredIndexType::UNIQUE)
        ->and($parsed->operations[4]->index?->type)->toBe(DeclaredIndexType::INDEX);
});

it('loads source-resolvable migration trait helpers', function (): void {
    $parsed = app(MigrationSourceParser::class)->parse(
        app_path('Base/Audit/Database/Migrations/0100_01_17_000000_create_base_audit_mutations_table.php'),
    );

    $columns = array_values(array_filter(array_map(
        fn ($operation) => $operation->kind === TableOperationKind::ADD_COLUMN ? $operation->name : null,
        $parsed->operations,
    )));

    expect($parsed->unreadable)->toBe([])
        ->and($columns)->toContain('company_id', 'actor_type', 'actor_id');
});

it('fails closed for runtime-dependent schema loops but ignores data-only loops', function (): void {
    $parser = app(MigrationSourceParser::class);
    $schemaLoop = <<<'PHP'
        <?php
        return new class {
            public function up(): void
            {
                foreach (config('tables') as $table) {
                    Schema::create($table, function ($blueprint): void {
                        $blueprint->id();
                    });
                }
            }
        };
        PHP;
    $dataLoop = <<<'PHP'
        <?php
        return new class {
            public function up(): void
            {
                foreach (DB::table('widgets')->get() as $widget) {
                    DB::table('widgets')->where('id', $widget->id)->update(['seen' => true]);
                }
            }
        };
        PHP;

    expect($parser->parseContents($schemaLoop)->unreadable)->toHaveCount(1)
        ->and($parser->parseContents($dataLoop)->unreadable)->toBe([]);
});

it('fails closed for runtime-dependent schema conditionals', function (): void {
    $migration = <<<'PHP'
        <?php
        return new class {
            public function up(): void
            {
                if (config('features.widgets')) {
                    Schema::create('widgets', function ($table): void {
                        $table->id();
                    });
                }
            }
        };
        PHP;

    $parsed = app(MigrationSourceParser::class)->parseContents($migration);

    expect($parsed->unreadable)->toHaveCount(1)
        ->and($parsed->unreadable[0]['reason'])->toContain('runtime-dependent conditional');
});

it('detects runtime-dependent Blueprint mutations nested inside a schema callback', function (): void {
    $migration = <<<'PHP'
        <?php
        return new class {
            public function up(): void
            {
                Schema::create('widgets', function ($table): void {
                    if (config('features.widget_notes')) {
                        $this->addNotes($table);
                    }
                });
            }

            private function addNotes($table): void
            {
                $table->text('notes');
            }
        };
        PHP;

    $parsed = app(MigrationSourceParser::class)->parseContents($migration);

    expect($parsed->unreadable)->toHaveCount(1)
        ->and($parsed->unreadable[0]['reason'])->toContain('runtime-dependent conditional');
});

it('resolves finite live-filtered column lists as an idempotent drop postcondition', function (): void {
    $migration = <<<'PHP'
        <?php
        return new class {
            public function up(): void
            {
                $legacyColumns = collect(['old_price', 'old_volume'])
                    ->filter(fn (string $column): bool => Schema::hasColumn('widgets', $column))
                    ->values()
                    ->all();

                Schema::table('widgets', function ($table) use ($legacyColumns): void {
                    $table->dropColumn($legacyColumns);
                });
            }
        };
        PHP;

    $parsed = app(MigrationSourceParser::class)->parseContents($migration);

    expect($parsed->unreadable)->toBe([])
        ->and(array_map(fn ($operation) => $operation->name, $parsed->operations))->toBe([
            'old_price',
            'old_volume',
        ]);
});

it('tracks raw indexes by explicit name without claiming their definition was compared', function (): void {
    $migration = <<<'PHP'
        <?php
        return new class {
            public function up(): void
            {
                DB::statement("CREATE UNIQUE INDEX widgets_scope_unique ON widgets (name, COALESCE(scope, ''))");
            }
        };
        PHP;

    $parsed = app(MigrationSourceParser::class)->parseContents($migration);
    $index = $parsed->operations[0]->index;

    expect($parsed->unreadable)->toBe([])
        ->and($index?->name)->toBe('widgets_scope_unique')
        ->and($index?->compareByName)->toBeTrue()
        ->and($index?->columns)->toBe([]);
});

it('ignores raw checks and triggers that are outside the compared schema scope', function (): void {
    $migration = <<<'PHP'
        <?php
        return new class {
            public function up(): void
            {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::statement('ALTER TABLE widgets ADD CONSTRAINT widgets_state_check CHECK (state IS NOT NULL)');
                }

                if (DB::connection()->getDriverName() === 'sqlite') {
                    DB::statement("CREATE TRIGGER widgets_state_insert BEFORE INSERT ON widgets WHEN NEW.state IS NULL BEGIN SELECT RAISE(ABORT, 'State required'); END");
                }
            }
        };
        PHP;

    $parsed = app(MigrationSourceParser::class)->parseContents($migration);

    expect($parsed->unreadable)->toBe([])
        ->and($parsed->operations)->toBe([]);
});

it('ignores the plpgsql function a trigger is built from, not only the trigger', function (): void {
    // The exemption listed CREATE TRIGGER but not the function it calls, so a
    // portable guard was readable on SQLite and unreadable on PostgreSQL --
    // reporting the whole migration INCOMPLETE on the one driver where the
    // trigger does any work. A function is no more comparable than a trigger.
    // Both statements sit inside driver conditionals on purpose. The exemption
    // is consulted from two places, and this is the one that produced #498:
    // MutationDetector decides whether an enclosing if() counts as a schema
    // mutation. A bare statement exercises only the reporting path and would
    // leave the property this test exists for unasserted.
    $parsed = parseMigrationUp(
        "        if (DB::connection()->getDriverName() === 'pgsql') {\n"
        .'            DB::unprepared("CREATE OR REPLACE FUNCTION widgets_guard() RETURNS trigger AS $$ BEGIN RETURN NEW; END; $$ LANGUAGE plpgsql; CREATE TRIGGER widgets_guard_trigger BEFORE UPDATE ON widgets FOR EACH ROW EXECUTE FUNCTION widgets_guard();");'."\n"
        ."        }\n"
        ."\n"
        ."        if (DB::connection()->getDriverName() === 'sqlite') {\n"
        .'            DB::statement("CREATE TRIGGER widgets_guard_trigger BEFORE UPDATE ON widgets BEGIN SELECT RAISE(ABORT, \'immutable\'); END");'."\n"
        .'        }'
    );

    expect($parsed->unreadable)->toBe([])
        ->and($parsed->operations)->toBe([]);
});

it('exempts the drop forms too, so replacing a trigger does not depend on statement order', function (): void {
    // Revising a trigger is ordinarily DROP then CREATE. Only the first
    // statement of a string is inspected, so leaving DROP out would have made
    // statement order decide whether migrate came back clean.
    // Inside a driver conditional for the same reason as the plpgsql case: the
    // drop arm has to be exempt on the MutationDetector path too, or replacing
    // a trigger inside an if() still condemns the whole conditional.
    $parsed = parseMigrationUp(
        "        if (DB::connection()->getDriverName() === 'pgsql') {\n"
        ."            DB::unprepared('DROP TRIGGER IF EXISTS g ON widgets; CREATE TRIGGER g BEFORE UPDATE ON widgets FOR EACH ROW EXECUTE FUNCTION widgets_guard();');\n"
        ."            DB::statement('DROP FUNCTION IF EXISTS widgets_guard()');\n"
        ."            DB::statement('CREATE OR REPLACE TRIGGER g BEFORE UPDATE ON widgets FOR EACH ROW EXECUTE FUNCTION widgets_guard()');\n"
        .'        }'
    );

    expect($parsed->unreadable)->toBe([])
        ->and($parsed->operations)->toBe([]);
});

it('matches every exemption arm case-insensitively', function (): void {
    // Pins the /i on both arms. Without it a lower-cased migration -- which
    // Laravel's own generated SQL is full of -- goes unreadable.
    expect(parseMigrationUp(
        "        DB::statement('create or replace trigger g before update on widgets execute function f()');"
    )->unreadable)->toBe([]);

    expect(parseMigrationUp(
        "        DB::statement('drop function if exists widgets_guard()');"
    )->unreadable)->toBe([]);
});

it('matches the exemption on whole words, so an identifier that merely starts with the keyword is not one', function (): void {
    // Pins the \b on both arms. These strings are not valid SQL, and that is
    // the point: the guard must not depend on the input being well formed to
    // avoid treating TRIGGER_LOG or FUNCTIONAL_AREA as TRIGGER or FUNCTION.
    expect(parseMigrationUp(
        "        DB::statement('CREATE TRIGGER_LOG widgets (id integer)');"
    )->unreadable)->not->toBe([]);

    expect(parseMigrationUp(
        "        DB::statement('DROP FUNCTIONAL_AREA widgets');"
    )->unreadable)->not->toBe([]);
});

it('still refuses a raw statement that is neither trigger, function, nor a supported index form', function (): void {
    $parsed = parseMigrationUp(
        "        DB::statement('CREATE MATERIALIZED VIEW widget_totals AS SELECT 1');"
    );

    expect($parsed->unreadable)->not->toBe([]);
});
