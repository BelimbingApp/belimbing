<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

/**
 * Snapshot of a data table visible on the current page.
 */
final readonly class TableSnapshot
{
    /**
     * @param  string  $id  Table identifier
     * @param  list<string>  $columns  Column headers
     * @param  int  $totalRows  Total row count (before pagination)
     * @param  int  $currentPage  Current page number
     * @param  int  $perPage  Rows per page
     * @param  string|null  $sortColumn  Current sort column
     * @param  string|null  $sortDirection  'asc' or 'desc'
     */
    public function __construct(
        public string $id,
        public array $columns = [],
        public int $totalRows = 0,
        public int $currentPage = 1,
        public int $perPage = 15,
        public ?string $sortColumn = null,
        public ?string $sortDirection = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            columns: $data['columns'] ?? [],
            totalRows: $data['total_rows'] ?? 0,
            currentPage: $data['current_page'] ?? 1,
            perPage: $data['per_page'] ?? 15,
            sortColumn: $data['sort_column'] ?? null,
            sortDirection: $data['sort_direction'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'columns' => $this->columns !== [] ? $this->columns : null,
            'total_rows' => $this->totalRows,
            'current_page' => $this->currentPage,
            'per_page' => $this->perPage,
            'sort_column' => $this->sortColumn,
            'sort_direction' => $this->sortDirection,
        ], fn (mixed $v): bool => $v !== null);
    }
}
