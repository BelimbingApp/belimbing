<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Foundation\Livewire;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Livewire\Component;
use Livewire\WithPagination;

abstract class SearchablePaginatedList extends Component
{
    use ResetsPaginationOnSearch;
    use WithPagination;

    public string $search = '';

    final public function render(): View
    {
        $query = $this->query();

        if ($this->search !== '') {
            $this->applySearch($query, $this->search);
        }

        $this->sortQuery($query);

        return view($this->viewName(), [
            $this->viewDataKey() => $query->paginate($this->perPage()),
        ]);
    }

    abstract protected function query(): EloquentBuilder|QueryBuilder;

    abstract protected function viewName(): string;

    abstract protected function viewDataKey(): string;

    abstract protected function applySearch(EloquentBuilder|QueryBuilder $query, string $search): void;

    abstract protected function sortQuery(EloquentBuilder|QueryBuilder $query): void;

    protected function perPage(): int
    {
        return 25;
    }
}
