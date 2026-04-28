<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Operation\IT\Livewire\Tickets;

use App\Base\Foundation\Livewire\SearchablePaginatedList;
use App\Modules\Operation\IT\Models\Ticket;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class Index extends SearchablePaginatedList
{
    protected const string VIEW_NAME = 'livewire.it.tickets.index';

    protected const string VIEW_DATA_KEY = 'tickets';

    protected const string SORT_COLUMN = 'created_at';

    public string $search = '';

    protected function sortableColumns(): array
    {
        return [
            'id' => 'operation_it_tickets.id',
            'title' => 'operation_it_tickets.title',
            'reporter_name' => 'reporter_employee.full_name',
            'priority' => 'operation_it_tickets.priority',
            'status' => 'operation_it_tickets.status',
            'category' => 'operation_it_tickets.category',
            'created_at' => 'operation_it_tickets.created_at',
        ];
    }

    protected function defaultSortDirections(): array
    {
        return [
            'id' => 'desc',
            'title' => 'asc',
            'reporter_name' => 'asc',
            'priority' => 'desc',
            'status' => 'asc',
            'category' => 'asc',
            'created_at' => 'desc',
        ];
    }

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
            'blocked' => 'danger',
            'awaiting_parts' => 'warning',
            'review' => 'accent',
            'resolved' => 'success',
            'closed' => 'default',
            default => 'default',
        };
    }

    protected function query(): EloquentBuilder|QueryBuilder
    {
        return Ticket::query()
            ->select('operation_it_tickets.*')
            ->leftJoin('employees as reporter_employee', 'operation_it_tickets.reporter_id', '=', 'reporter_employee.id')
            ->with('reporter', 'assignee');
    }

    protected function applySearch(EloquentBuilder|QueryBuilder $query, string $search): void
    {
        $query->where(function (EloquentBuilder $builder) use ($search): void {
            $builder->where('title', 'like', '%'.$search.'%')
                ->orWhere('category', 'like', '%'.$search.'%')
                ->orWhere('status', 'like', '%'.$search.'%')
                ->orWhereHas('reporter', function (EloquentBuilder $reporterQuery) use ($search): void {
                    $reporterQuery->where('full_name', 'like', '%'.$search.'%')
                        ->orWhere('short_name', 'like', '%'.$search.'%');
                });
        });
    }
}
