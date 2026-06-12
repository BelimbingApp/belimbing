<?php

use App\Base\Database\Livewire\SchemaIncubation\Index as SchemaIncubationIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const DATABASE_TABLES_INCUBATION_PEOPLE_DOMAIN_PATH = 'Modules/People';
const DATABASE_TABLES_INCUBATION_PEOPLE_DOMAIN_SKIP = 'People domain not installed';

beforeEach(function (): void {
    setupAuthzRoles();
    $this->app['env'] = 'local';
})->skip(fn (): bool => ! is_dir(app_path(DATABASE_TABLES_INCUBATION_PEOPLE_DOMAIN_PATH)), DATABASE_TABLES_INCUBATION_PEOPLE_DOMAIN_SKIP);

test('schema incubation index can add selected tables to source incubation', function (): void {
    $this->actingAs(createAdminUser());
    $migrationPath = app_path('Modules/Core/AI/Database/Migrations/0200_02_01_000003_create_ai_browser_sessions_table.php');
    $original = file_get_contents($migrationPath);

    try {
        Livewire::test(SchemaIncubationIndex::class)
            ->set('search', 'browser')
            ->set('selectedSearchTables', ['ai_browser_sessions'])
            ->call('moveSelectedToIncubation')
            ->assertSee('create_ai_browser_sessions_table.php [ai_browser_sessions]');

        expect(file_get_contents($migrationPath))
            ->toContain('use App\Base\Database\Concerns\IncubatingSchema;')
            ->toContain('use IncubatingSchema;');
    } finally {
        file_put_contents($migrationPath, $original);
    }
})->skip(fn (): bool => ! is_dir(app_path(DATABASE_TABLES_INCUBATION_PEOPLE_DOMAIN_PATH)), DATABASE_TABLES_INCUBATION_PEOPLE_DOMAIN_SKIP);

test('schema incubation index can remove selected tables from source incubation', function (): void {
    $this->actingAs(createAdminUser());

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
})->skip(fn (): bool => ! is_dir(app_path(DATABASE_TABLES_INCUBATION_PEOPLE_DOMAIN_PATH)), DATABASE_TABLES_INCUBATION_PEOPLE_DOMAIN_SKIP);

test('schema incubation index can filter currently incubating tables by table name', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(SchemaIncubationIndex::class)
        ->set('incubatingSearch', 'leave_type')
        ->assertSee('people_leave_types')
        ->assertDontSee('people_leave_policies');
})->skip(fn (): bool => ! is_dir(app_path(DATABASE_TABLES_INCUBATION_PEOPLE_DOMAIN_PATH)), DATABASE_TABLES_INCUBATION_PEOPLE_DOMAIN_SKIP);

test('schema incubation index can filter currently incubating tables by module', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(SchemaIncubationIndex::class)
        ->set('incubatingModule', 'Leave')
        ->assertSee('people_leave_types')
        ->assertDontSee('people_claim_types');
});

test('schema incubation page select checkbox only selects filtered incubating tables', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(SchemaIncubationIndex::class)
        ->set('incubatingModule', 'Leave')
        ->set('incubatingSearch', 'leave_type')
        ->set('selectIncubatingPage', true)
        ->assertSet('selectedIncubatingTables', ['people_leave_types']);
});

test('schema incubation index hides non-incubating stable tables from the main incubation list', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(SchemaIncubationIndex::class)
        ->assertDontSee('ai_browser_sessions');
});

test('schema incubation index supports wildcard search on table names', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(SchemaIncubationIndex::class)
        ->set('search', 'browser')
        ->assertSee('ai_browser_sessions');
});
