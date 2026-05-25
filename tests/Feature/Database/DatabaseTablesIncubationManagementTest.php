<?php

use App\Base\Database\Livewire\SchemaIncubation\Index as SchemaIncubationIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

const DATABASE_TABLES_INCUBATION_TEST_SCRIPT = 'storage/framework/testing/database-tables-incubation-list.sh';

function pointDatabaseTablesIncubationUiAtScript(string $path): void
{
    putenv('BLB_DEPRECATED_UNSTABLE_TABLE_LIST='.$path);
    $_ENV['BLB_DEPRECATED_UNSTABLE_TABLE_LIST'] = $path;
    $_SERVER['BLB_DEPRECATED_UNSTABLE_TABLE_LIST'] = $path;
}

function writeDatabaseTablesIncubationScript(array $patterns = []): string
{
    $path = base_path(DATABASE_TABLES_INCUBATION_TEST_SCRIPT);

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    $lines = implode(PHP_EOL, array_map(
        fn (string $pattern): string => '  '.$pattern,
        $patterns,
    ));

    file_put_contents($path, "#!/usr/bin/env bash\nreadonly BLB_DEPRECATED_UNSTABLE_TABLE_PATTERNS=(\n{$lines}\n)\n");
    pointDatabaseTablesIncubationUiAtScript($path);

    return $path;
}

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setupAuthzRoles();
    $this->app['env'] = 'local';
});

afterEach(function (): void {
    $path = base_path(DATABASE_TABLES_INCUBATION_TEST_SCRIPT);

    putenv('BLB_DEPRECATED_UNSTABLE_TABLE_LIST');
    unset($_ENV['BLB_DEPRECATED_UNSTABLE_TABLE_LIST'], $_SERVER['BLB_DEPRECATED_UNSTABLE_TABLE_LIST']);

    if (is_file($path)) {
        @unlink($path);
    }
});

test('schema incubation index can add selected tables to source incubation', function (): void {
    $this->actingAs(createAdminUser());
    $path = writeDatabaseTablesIncubationScript();
    $migrationPath = app_path('Modules/Core/User/Database/Migrations/0200_01_20_000000_create_users_table.php');
    $original = file_get_contents($migrationPath);

    try {
        Livewire::test(SchemaIncubationIndex::class)
            ->set('search', 'use*')
            ->set('selectedSearchTables', ['users'])
            ->call('moveSelectedToIncubation')
            ->assertSee('create_users_table.php [users]');

        expect(file_get_contents($migrationPath))
            ->toContain('use App\Base\Database\Concerns\IncubatingSchema;')
            ->toContain('use IncubatingSchema;')
            ->and(file_get_contents($path))->not()->toContain('  users');
    } finally {
        file_put_contents($migrationPath, $original);
    }
});

test('schema incubation index can remove selected tables from source incubation', function (): void {
    $this->actingAs(createAdminUser());
    writeDatabaseTablesIncubationScript();

    $migrationPath = app_path('Modules/People/Leave/Database/Migrations/0320_02_01_000000_create_people_leave_core_tables.php');
    $original = file_get_contents($migrationPath);

    try {
        Livewire::test(SchemaIncubationIndex::class)
            ->set('selectedIncubatingTables', ['people_leave_types'])
            ->call('removeSelectedFromIncubation')
            ->assertSee('create_people_leave_core_tables.php [people_leave_types]');

        expect(file_get_contents($migrationPath))->not()->toContain('use IncubatingSchema;');
    } finally {
        file_put_contents($migrationPath, $original);
    }
});

test('schema incubation index leaves deprecated-script tables alone during removal', function (): void {
    $this->actingAs(createAdminUser());
    writeDatabaseTablesIncubationScript(['use*']);

    Livewire::test(SchemaIncubationIndex::class)
        ->set('selectedIncubatingTables', ['users'])
        ->call('removeSelectedFromIncubation')
        ->assertSee('managed by deprecated compatibility list');
});

test('schema incubation index supports wildcard search on table names', function (): void {
    $this->actingAs(createAdminUser());
    writeDatabaseTablesIncubationScript();

    Livewire::test(SchemaIncubationIndex::class)
        ->set('search', 'use*')
        ->assertSee('users');
});
