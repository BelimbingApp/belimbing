<?php

use App\Base\Database\Services\SchemaDrift\DeclaredSchema;
use App\Base\Database\Services\SchemaDrift\MigrationSourceParser;
use App\Base\Database\Services\SchemaDrift\SchemaDriftComparator;
use App\Base\Database\Services\SchemaDrift\SchemaDriftFindingKind;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('reports exact column and index drift without classifying extra tables as drift', function (): void {
    Schema::dropIfExists('schema_drift_test_widgets');
    Schema::create('schema_drift_test_widgets', function (Blueprint $table): void {
        $table->string('legacy')->unique();
    });
    Schema::create('schema_drift_test_residue', function (Blueprint $table): void {
        $table->string('ignored');
    });

    try {
        $migration = <<<'PHP'
            <?php
            return new class {
                public function up(): void
                {
                    Schema::create('schema_drift_test_widgets', function ($table): void {
                        $table->string('name')->index();
                    });
                }
            };
            PHP;
        $declared = DeclaredSchema::fromMigrations([
            app(MigrationSourceParser::class)->parseContents($migration, 'widgets.php'),
        ]);

        $findings = app(SchemaDriftComparator::class)->compare($declared, Schema::getFacadeRoot());

        expect(array_map(
            fn ($finding) => [$finding->kind, $finding->object],
            $findings,
        ))->toBe([
            [SchemaDriftFindingKind::MISSING_COLUMN, 'name'],
            [SchemaDriftFindingKind::MISSING_INDEX, 'index(name)'],
            [SchemaDriftFindingKind::UNEXPECTED_COLUMN, 'legacy'],
            [SchemaDriftFindingKind::UNEXPECTED_UNIQUE_INDEX, 'unique(legacy)'],
        ]);
    } finally {
        Schema::dropIfExists('schema_drift_test_residue');
        Schema::dropIfExists('schema_drift_test_widgets');
    }
});
