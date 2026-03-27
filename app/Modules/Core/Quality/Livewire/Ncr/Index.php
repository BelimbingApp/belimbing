<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Livewire\Ncr;

use App\Modules\Core\Quality\Livewire\StatusFilteredSearchableIndex;
use App\Modules\Core\Quality\Models\Ncr;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

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
