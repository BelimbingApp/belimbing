<?php

use App\Base\Database\Services\SchemaDrift\DeclaredIndexType;
use App\Base\Database\Services\SchemaDrift\MigrationSourceParser;
use App\Base\Database\Services\SchemaDrift\TableOperationKind;
use Tests\TestCase;

uses(TestCase::class);

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
