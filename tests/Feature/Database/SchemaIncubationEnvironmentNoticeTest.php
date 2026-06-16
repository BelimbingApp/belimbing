<?php

use App\Base\Database\Livewire\SchemaIncubation\Index;
use Livewire\Livewire;

beforeEach(function (): void {
    setupAuthzRoles();
    $this->actingAs(createAdminUser());
});

test('schema incubation warns when the environment is not local', function (): void {
    $this->app['env'] = 'production';

    Livewire::test(Index::class)
        ->assertSee('Schema incubation is a local development workflow.')
        ->assertSee('This environment is production, not local.');
});

test('schema incubation hides the environment warning when local', function (): void {
    $this->app['env'] = 'local';

    Livewire::test(Index::class)
        ->assertDontSee('Schema incubation is a local development workflow.');
});
