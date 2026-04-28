<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Livewire\Ncr;

use App\Modules\Core\Quality\Livewire\StatusFilteredSearchableIndex;
use App\Modules\Core\Quality\Models\Ncr;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class Index extends StatusFilteredSearchableIndex
{
    protected const string VIEW_NAME = 'livewire.quality.ncr.index';

    protected const string VIEW_DATA_KEY = 'ncrs';

    protected const string SORT_COLUMN = 'created_at';

    /**
     * @var list<string>
     */
    protected const array SEARCH_COLUMNS = ['ncr_no', 'title', 'reported_by_name'];

    public string $search = '';

    public string $kindFilter = '';

    public function updatedKindFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    protected function sortableColumns(): array
    {
        return [
            'ncr_no' => 'quality_ncrs.ncr_no',
            'title' => 'quality_ncrs.title',
            'ncr_kind' => 'quality_ncrs.ncr_kind',
            'severity' => 'quality_ncrs.severity',
            'status' => 'quality_ncrs.status',
            'reported_by_name' => 'quality_ncrs.reported_by_name',
            'created_at' => 'quality_ncrs.created_at',
        ];
    }

    protected function defaultSortDirections(): array
    {
        return [
            'ncr_no' => 'desc',
            'title' => 'asc',
            'ncr_kind' => 'asc',
            'severity' => 'desc',
            'status' => 'asc',
            'reported_by_name' => 'asc',
            'created_at' => 'desc',
        ];
    }

    protected function applySearch(EloquentBuilder|QueryBuilder $query, string $search): void
    {
        $query->where(function (EloquentBuilder $builder) use ($search): void {
            $builder->where('quality_ncrs.ncr_no', 'like', '%'.$search.'%')
                ->orWhere('quality_ncrs.title', 'like', '%'.$search.'%')
                ->orWhere('quality_ncrs.reported_by_name', 'like', '%'.$search.'%');
        });
    }

    public function severityVariant(string $severity): string
    {
        return match ($severity) {
            'critical' => 'danger',
            'major' => 'warning',
            'minor' => 'info',
            'observation' => 'default',
            default => 'default',
        };
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            'open' => 'info',
            'under_triage' => 'accent',
            'assigned' => 'accent',
            'in_progress' => 'warning',
            'under_review' => 'accent',
            'verified' => 'success',
            'closed' => 'default',
            'rejected' => 'danger',
            default => 'default',
        };
    }

    protected function baseQuery(): EloquentBuilder
    {
        $query = Ncr::query()
            ->with('createdByUser', 'currentOwner');

        if ($this->kindFilter !== '') {
            $query->where('ncr_kind', $this->kindFilter);
        }

        return $query;
    }
}
