<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Business\IT\Livewire\Tickets;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Modules\Business\IT\Models\Ticket;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use WithPagination;

    public string $search = '';

    public function priorityVariant(string $priority): string
    {
        return match ($priority) {
            'critical' => 'danger',
            'high' => 'warning',
            'medium' => 'info',
            'low' => 'default',
            default => 'default',
        };
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            'open' => 'info',
            'assigned' => 'accent',
            'in_progress' => 'warning',
            'awaiting_parts' => 'warning',
            'resolved' => 'success',
            'closed' => 'default',
            default => 'default',
        };
    }

    public function render(): View
    {
        return view('livewire.it.tickets.index', [
            'tickets' => Ticket::query()
                ->with('reporter', 'assignee')
                ->when($this->search, function ($query, $search): void {
                    $query->where(function ($q) use ($search): void {
                        $q->where('title', 'like', '%'.$search.'%')
                            ->orWhere('category', 'like', '%'.$search.'%')
                            ->orWhere('status', 'like', '%'.$search.'%')
                            ->orWhereHas('reporter', function ($rq) use ($search): void {
                                $rq->where('name', 'like', '%'.$search.'%');
                            });
                    });
                })
                ->latest()
                ->paginate(25),
        ]);
    }
}
