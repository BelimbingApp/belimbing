<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Livewire\Scar;

use App\Modules\Core\Quality\Livewire\StatusFilteredSearchableIndex;
use App\Modules\Core\Quality\Models\Scar;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class Index extends StatusFilteredSearchableIndex
{
    protected const string VIEW_NAME = 'livewire.quality.scar.index';

    protected const string VIEW_DATA_KEY = 'scars';

    protected const string SORT_COLUMN = 'created_at';

    /**
     * @var list<string>
     */
    protected const array SEARCH_COLUMNS = ['scar_no', 'supplier_name', 'product_name'];

    public string $search = '';

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    protected function sortableColumns(): array
    {
        return [
            'scar_no' => 'quality_scars.scar_no',
            'ncr_no' => 'ncr_row.ncr_no',
            'supplier_name' => 'quality_scars.supplier_name',
            'product_name' => 'quality_scars.product_name',
            'status' => 'quality_scars.status',
            'owner_name' => 'issue_owner_user.name',
            'created_at' => 'quality_scars.created_at',
        ];
    }

    protected function defaultSortDirections(): array
    {
        return [
            'scar_no' => 'desc',
            'ncr_no' => 'desc',
            'supplier_name' => 'asc',
            'product_name' => 'asc',
            'status' => 'asc',
            'owner_name' => 'asc',
            'created_at' => 'desc',
        ];
    }

    protected function applySearch(EloquentBuilder|QueryBuilder $query, string $search): void
    {
        $query->where(function (EloquentBuilder $builder) use ($search): void {
            $builder->where('quality_scars.scar_no', 'like', '%'.$search.'%')
                ->orWhere('quality_scars.supplier_name', 'like', '%'.$search.'%')
                ->orWhere('quality_scars.product_name', 'like', '%'.$search.'%');
        });
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            'draft' => 'default',
            'issued' => 'info',
            'acknowledged' => 'accent',
            'containment_submitted' => 'accent',
            'under_investigation' => 'warning',
            'response_submitted' => 'accent',
            'under_review' => 'accent',
            'action_required' => 'warning',
            'verification_pending' => 'info',
            'closed' => 'default',
            'rejected' => 'danger',
            'cancelled' => 'default',
            default => 'default',
        };
    }

    protected function baseQuery(): EloquentBuilder
    {
        return Scar::query()
            ->select('quality_scars.*')
            ->leftJoin('quality_ncrs as ncr_row', 'quality_scars.ncr_id', '=', 'ncr_row.id')
            ->leftJoin('users as issue_owner_user', 'quality_scars.issue_owner_user_id', '=', 'issue_owner_user.id')
            ->with('ncr', 'issueOwner');
    }
}
