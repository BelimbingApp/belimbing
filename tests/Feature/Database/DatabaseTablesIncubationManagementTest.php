<?php

use App\Base\Database\Livewire\SchemaIncubation\Index as SchemaIncubationIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setupAuthzRoles();
    $this->app['env'] = 'local';
});

test('schema incubation index can add selected tables to source incubation', function (): void {
    $this->actingAs(createAdminUser());
    Process::fake(); // intercept the auto-commit so the test never writes real git history
    $migrationPath = app_path('Modules/Core/AI/Database/Migrations/0200_02_01_000003_create_ai_browser_sessions_table.php');
    $original = file_get_contents($migrationPath);

    try {
        Livewire::test(SchemaIncubationIndex::class)
            ->set('search', 'browser')
            ->set('selectedSearchTables', ['ai_browser_sessions'])
            ->call('moveSelectedToIncubation')
            ->assertSee('create_ai_browser_sessions_table.php [ai_browser_sessions]')
            ->assertSee('Committed in');

        expect(file_get_contents($migrationPath))
            ->toContain('use App\Base\Database\Concerns\IncubatingSchema;')
            ->toContain('use IncubatingSchema;');

        // The rewritten file is staged + committed scoped to that file — never `add -A`.
        Process::assertRan(fn ($p): bool => in_array('commit', $p->command, true) && in_array($migrationPath, $p->command, true));
        Process::assertDidntRun(fn ($p): bool => in_array('-A', $p->command, true));
    } finally {
        file_put_contents($migrationPath, $original);
    }
});

test('schema incubation table pickers render client-reactive selection controls and feedback', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(SchemaIncubationIndex::class)
        ->assertSeeHtml('id="database-incubation-tabs"')
        ->assertSeeHtml('data-selection-header')
        ->assertSeeHtml('data-selection-row')
        ->assertSeeHtml('wire:text="selectedIncubatingTables.length"')
        ->assertSeeHtml('wire:text="selectedSearchTables.length"')
        ->assertSeeHtml('wire:bind:disabled="selectedIncubatingTables.length === 0"')
        ->assertSeeHtml('wire:bind:disabled="selectedSearchTables.length === 0"')
        ->assertDontSee('Move Selected To Incubation');
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
