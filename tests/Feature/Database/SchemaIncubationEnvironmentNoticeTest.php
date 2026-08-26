<?php

use App\Base\Database\Exceptions\IncubatingSchemaMutationException;
use App\Base\Database\Livewire\SchemaIncubation\Index;
use App\Base\Database\Services\MigrationIncubationManager;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

beforeEach(function (): void {
    setupAuthzRoles();
    $this->actingAs(createAdminUser());
});

test('schema incubation warns when the environment is not local', function (): void {
    $this->app['env'] = 'production';

    Livewire::test(Index::class)
        ->assertSee('Schema incubation is a local development workflow.')
        ->assertSee('This environment is production, not local.')
        ->assertSee('Source-editing actions are disabled here')
        ->assertSeeHtml('wire:bind:disabled="selectedIncubatingTables.length === 0 || true"')
        ->assertSeeHtml('wire:bind:disabled="selectedSearchTables.length === 0 || true"');
});

test('schema incubation hides the environment warning when local', function (): void {
    $this->app['env'] = 'local';

    Livewire::test(Index::class)
        ->assertDontSee('Schema incubation is a local development workflow.');
});

test('schema incubation refuses source edits outside local before touching git or migration files', function (string $action, string $selection): void {
    $this->app['env'] = 'production';
    Process::fake();
    $message = __('Schema incubation source edits are disabled outside the local environment. Make and push migration-source changes from a development checkout.');

    Livewire::test(Index::class)
        ->set($selection, ['ai_browser_sessions'])
        ->call($action)
        ->assertDispatched('notify', variant: 'warning', message: $message);

    Process::assertNothingRan();
})->with([
    'incubate' => ['moveSelectedToIncubation', 'selectedSearchTables'],
    'un-incubate' => ['removeSelectedFromIncubation', 'selectedIncubatingTables'],
]);

test('the incubation service also fails closed outside local', function (): void {
    $this->app['env'] = 'production';

    expect(fn () => app(MigrationIncubationManager::class)->markTablesIncubating([]))
        ->toThrow(IncubatingSchemaMutationException::class, 'source edits are local-only');
});
