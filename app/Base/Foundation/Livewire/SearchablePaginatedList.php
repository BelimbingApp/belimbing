<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Foundation\Livewire;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Livewire\Component;
use Livewire\WithPagination;

abstract class SearchablePaginatedList extends Component
{
    use ResetsPaginationOnSearch;
    use TogglesSort;
    use WithPagination;

    protected const string VIEW_NAME = '';

    protected const string VIEW_DATA_KEY = '';

    protected const string SORT_COLUMN = '';

    /**
     * @var list<string>
     */
    protected const array SEARCH_COLUMNS = [];

    public string $search = '';

    public string $sortBy = '';

    public string $sortDir = 'asc';

    public function mount(): void
    {
        if ($this->sortBy === '') {
            $this->sortBy = $this->defaultSortBy();
            $this->sortDir = $this->defaultSortDir();
        }
    }

    final public function render(): View
    {
        $query = $this->query();

        if ($this->search !== '') {
            $this->applySearch($query, $this->search);
        }

        $this->sortQuery($query);

        return view($this->viewName(), array_merge(
            [
                $this->viewDataKey() => $query->paginate($this->perPage()),
            ],
            $this->extraViewData(),
        ));
    }

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: $this->sortableColumns(),
            defaultDir: $this->defaultSortDirections(),
        );
    }

    abstract protected function query(): EloquentBuilder|QueryBuilder;

    /**
     * Map UI sort keys to SQL order expressions (typically qualified column names).
     *
     * @return array<string, string>
     */
    protected function sortableColumns(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    protected function defaultSortDirections(): array
    {
        return [];
    }

    protected function defaultSortBy(): string
    {
        return static::SORT_COLUMN;
    }

    protected function defaultSortDir(): string
    {
        return 'desc';
    }

    protected function viewName(): string
    {
        return static::VIEW_NAME;
    }

    protected function viewDataKey(): string
    {
        return static::VIEW_DATA_KEY;
    }

    protected function applySearch(EloquentBuilder|QueryBuilder $query, string $search): void
    {
        $columns = static::SEARCH_COLUMNS;

        if ($columns === []) {
            return;
        }

        $query->where(function ($builder) use ($columns, $search): void {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $builder->{$method}($column, 'like', '%'.$search.'%');
            }
        });
    }

    protected function sortQuery(EloquentBuilder|QueryBuilder $query): void
    {
        $sortable = $this->sortableColumns();

        if ($sortable === []) {
            if (static::SORT_COLUMN !== '') {
                $query->orderByDesc(static::SORT_COLUMN);
            }

            return;
        }

        $sortColumn = $sortable[$this->sortBy] ?? null;

        if (! is_string($sortColumn) || $sortColumn === '') {
            if (static::SORT_COLUMN !== '') {
                $query->orderByDesc(static::SORT_COLUMN);
            }

            return;
        }

        $query->orderBy($sortColumn, $this->sortDir);

        $idColumn = $query instanceof EloquentBuilder
            ? $query->getModel()->getQualifiedKeyName()
            : 'id';

        $query->orderByDesc($idColumn);
    }

    protected function perPage(): int
    {
        return 25;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraViewData(): array
    {
        return [];
    }
}
