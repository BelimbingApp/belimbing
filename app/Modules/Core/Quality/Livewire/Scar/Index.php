<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Livewire\Scar;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Modules\Core\Quality\Models\Scar;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

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

    public function render(): View
    {
        return view('livewire.quality.scar.index', [
            'scars' => Scar::query()
                ->with('ncr', 'issueOwner')
                ->when($this->search, function ($query, $search): void {
                    $query->where(function ($q) use ($search): void {
                        $q->where('scar_no', 'like', '%'.$search.'%')
                            ->orWhere('supplier_name', 'like', '%'.$search.'%')
                            ->orWhere('product_name', 'like', '%'.$search.'%');
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
