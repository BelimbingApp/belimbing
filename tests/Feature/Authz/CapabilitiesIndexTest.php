<?php

use App\Base\Authz\Livewire\Capabilities\Index;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Livewire;

test('capabilities index paginates its rows so the reference table stays bounded', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->assertViewHas('capabilities', function (LengthAwarePaginator $rows): bool {
            return $rows->perPage() === 50 && $rows->count() <= 50;
        });
});

test('capabilities index resets to the first page when the search changes', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->call('setPage', 2)
        ->assertSet('paginators.page', 2)
        ->set('search', 'view')
        ->assertSet('paginators.page', 1);
});
