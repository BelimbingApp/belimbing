<?php
namespace App\Modules\Operation\Quality\Livewire;

use App\Base\Foundation\Livewire\SearchablePaginatedList;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

abstract class StatusFilteredSearchableIndex extends SearchablePaginatedList
{
    public string $statusFilter = '';

    final protected function query(): EloquentBuilder|QueryBuilder
    {
        $query = $this->baseQuery();

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        return $query;
    }

    abstract protected function baseQuery(): EloquentBuilder;
}
