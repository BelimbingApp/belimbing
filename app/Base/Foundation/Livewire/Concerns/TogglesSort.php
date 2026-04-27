<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Foundation\Livewire\Concerns;

trait TogglesSort
{
    /**
     * Toggle sort state (column + direction) in a consistent way.
     *
     * Supports both list-based allowlists (['name', 'created_at']) and
     * map-based allowlists (['name' => 'db_column', ...]) where the keys
     * are the allowed sort names.
     *
     * @param  array<int, string>|array<string, mixed>  $allowedColumns
     * @param  array<string, string>  $defaultDir  e.g. ['population' => 'desc']
     */
    protected function toggleSort(
        string $column,
        array $allowedColumns,
        array $defaultDir = [],
        string $sortByProperty = 'sortBy',
        string $sortDirProperty = 'sortDir',
        bool $resetPage = true,
    ): void {
        $allowed = array_is_list($allowedColumns)
            ? in_array($column, $allowedColumns, true)
            : array_key_exists($column, $allowedColumns);

        if (! $allowed) {
            return;
        }

        $currentColumn = (string) ($this->{$sortByProperty} ?? '');
        $currentDir = (string) ($this->{$sortDirProperty} ?? 'asc');

        if ($currentColumn === $column) {
            $this->{$sortDirProperty} = $currentDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->{$sortByProperty} = $column;
            $nextDirection = strtolower($defaultDir[$column] ?? 'asc');
            $this->{$sortDirProperty} = $nextDirection === 'desc' ? 'desc' : 'asc';
        }

        if ($resetPage && method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }
}
