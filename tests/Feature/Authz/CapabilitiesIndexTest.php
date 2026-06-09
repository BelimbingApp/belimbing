<?php

use App\Base\Authz\Livewire\Capabilities\Index;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(createAdminUser());
});

function visitCapabilitiesIndex(): Testable
{
    return Livewire::test(Index::class);
}

test('capabilities index paginates its rows so the reference table stays bounded', function (): void {
    visitCapabilitiesIndex()
        ->assertViewHas('capabilities', function (LengthAwarePaginator $rows): bool {
            return $rows->perPage() === 50 && $rows->count() <= 50;
        });
});

test('capabilities index resets to the first page when the search changes', function (): void {
    visitCapabilitiesIndex()
        ->call('setPage', 2)
        ->assertSet('paginators.page', 2)
        ->set('search', 'view')
        ->assertSet('paginators.page', 1);
});
