<?php

use App\Base\Foundation\Livewire\Concerns\TogglesSort;

function sortToggleHarness(): object
{
    return new class
    {
        use TogglesSort {
            toggleSort as public;
        }

        public string $sortBy = 'name';

        public string $sortDir = 'asc';

        public string $tableSortBy = 'created_at';

        public string $tableSortDir = 'desc';

        public bool $pageReset = false;

        public function resetPage(): void
        {
            $this->pageReset = true;
        }
    };
}

it('toggles the active sort column direction and resets pagination', function (): void {
    $component = sortToggleHarness();

    $component->toggleSort('name', ['name', 'created_at']);

    expect($component->sortBy)->toBe('name')
        ->and($component->sortDir)->toBe('desc')
        ->and($component->pageReset)->toBeTrue();
});

it('switches to a new allowed mapped column with its default direction', function (): void {
    $component = sortToggleHarness();

    $component->toggleSort(
        column: 'total',
        allowedColumns: ['name' => 'users.name', 'total' => 'total'],
        defaultDir: ['total' => 'DESC'],
    );

    expect($component->sortBy)->toBe('total')
        ->and($component->sortDir)->toBe('desc');
});

it('ignores disallowed columns', function (): void {
    $component = sortToggleHarness();

    $component->toggleSort('email', ['name']);

    expect($component->sortBy)->toBe('name')
        ->and($component->sortDir)->toBe('asc')
        ->and($component->pageReset)->toBeFalse();
});

it('supports alternate sort property names without pagination reset', function (): void {
    $component = sortToggleHarness();

    $component->toggleSort(
        column: 'workflow',
        allowedColumns: ['workflow', 'owner'],
        sortByProperty: 'tableSortBy',
        sortDirProperty: 'tableSortDir',
        resetPage: false,
    );

    expect($component->tableSortBy)->toBe('workflow')
        ->and($component->tableSortDir)->toBe('asc')
        ->and($component->pageReset)->toBeFalse();
});
