<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Address\Livewire\Addresses;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Modules\Core\Address\Models\Address;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use TogglesSort;
    use WithPagination;

    public string $search = '';

    public string $sortBy = 'label';

    public string $sortDir = 'asc';

    private const SORTABLE = [
        'label' => 'label',
        'country_iso' => 'country_iso',
        'verificationStatus' => 'verificationStatus',
    ];

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
        );
    }

    public function with(): array
    {
        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'label';

        return [
            'addresses' => Address::query()
                ->when($this->search, function ($query, $search): void {
                    $query
                        ->where('label', 'like', '%'.$search.'%')
                        ->orWhere('line1', 'like', '%'.$search.'%')
                        ->orWhere('locality', 'like', '%'.$search.'%')
                        ->orWhere('postcode', 'like', '%'.$search.'%')
                        ->orWhere('country_iso', 'like', '%'.$search.'%');
                })
                ->orderBy($sortColumn, $this->sortDir)
                ->orderByDesc('created_at')
                ->paginate(15),
        ];
    }

    public function statusVariant(?string $status): string
    {
        return match ($status) {
            'verified' => 'success',
            'suggested' => 'warning',
            default => 'default',
        };
    }

    public function delete(int $addressId): void
    {
        $address = Address::query()->findOrFail($addressId);

        $linkedCount = DB::table('addressables')
            ->where('address_id', $address->id)
            ->count();

        if ($linkedCount > 0) {
            Session::flash('error', __('Cannot delete an address linked to :count entity(ies). Unlink it first.', ['count' => $linkedCount]));

            return;
        }

        $address->delete();

        Session::flash('success', __('Address deleted successfully.'));
    }

    public function render(): View
    {
        return view('livewire.admin.addresses.index', $this->with());
    }
}
