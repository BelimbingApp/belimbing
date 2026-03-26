<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Livewire\Ncr;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Modules\Core\Quality\Models\Ncr;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

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

    public function render(): View
    {
        return view('livewire.quality.ncr.index', [
            'ncrs' => Ncr::query()
                ->with('createdByUser', 'currentOwner')
                ->when($this->search, function ($query, $search): void {
                    $query->where(function ($q) use ($search): void {
                        $q->where('ncr_no', 'like', '%'.$search.'%')
                            ->orWhere('title', 'like', '%'.$search.'%')
                            ->orWhere('reported_by_name', 'like', '%'.$search.'%');
                    });
                })
                ->when($this->statusFilter, function ($query, $status): void {
                    $query->where('status', $status);
                })
                ->latest()
                ->paginate(25),
        ]);
    }
}
