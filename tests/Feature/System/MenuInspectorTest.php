<?php

use App\Base\System\Livewire\MenuInspector\Index;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Livewire;

test('menu inspector paginates its rows so the diagnostic table stays bounded', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->assertViewHas('rows', function (LengthAwarePaginator $rows): bool {
            // The page slice never exceeds the per-page bound, even though the
            // full menu registry has many more items than fit on one page.
            return $rows->perPage() === 25 && $rows->count() <= 25;
        });
});

test('menu inspector resets to the first page when a filter changes', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->call('setPage', 2)
        ->assertSet('paginators.page', 2)
        ->set('search', 'admin')
        ->assertSet('paginators.page', 1);
});
