<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Livewire\Concerns;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\CompanyRelationship;
use App\Modules\Core\Company\Models\Department;
use Illuminate\Support\Collection;

trait SortsCompanyProfileRelations
{
    private const CHILD_SORTABLE = [
        'name' => true,
        'status' => true,
        'legal_entity_type' => true,
        'jurisdiction' => true,
    ];

    private const DEPARTMENT_SORTABLE = [
        'department_type' => true,
        'category' => true,
        'status' => true,
        'head' => true,
    ];

    private const RELATIONSHIP_SORTABLE = [
        'company_name' => true,
        'relationship_type' => true,
        'direction' => true,
        'effective_from' => true,
        'effective_to' => true,
        'is_active' => true,
    ];

    public function sortChildren(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::CHILD_SORTABLE,
            defaultDir: [
                'name' => 'asc',
                'status' => 'asc',
                'legal_entity_type' => 'asc',
                'jurisdiction' => 'asc',
            ],
            sortByProperty: 'childrenSortBy',
            sortDirProperty: 'childrenSortDir',
            resetPage: false,
        );
    }

    public function sortDepartments(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::DEPARTMENT_SORTABLE,
            defaultDir: [
                'department_type' => 'asc',
                'category' => 'asc',
                'status' => 'asc',
                'head' => 'asc',
            ],
            sortByProperty: 'departmentsSortBy',
            sortDirProperty: 'departmentsSortDir',
            resetPage: false,
        );
    }

    public function sortRelationships(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::RELATIONSHIP_SORTABLE,
            defaultDir: [
                'company_name' => 'asc',
                'relationship_type' => 'asc',
                'direction' => 'asc',
                'effective_from' => 'asc',
                'effective_to' => 'asc',
                'is_active' => 'desc',
            ],
            sortByProperty: 'relationshipsSortBy',
            sortDirProperty: 'relationshipsSortDir',
            resetPage: false,
        );
    }

    /**
     * @param  Collection<int, Company>  $children
     * @return Collection<int, Company>
     */
    private function sortChildrenCollection(Collection $children): Collection
    {
        $dir = $this->childrenSortDir === 'desc' ? -1 : 1;

        return $children
            ->sort(fn (Company $a, Company $b): int => $this->compareChildCompany($a, $b, $dir))
            ->values();
    }

    private function compareChildCompany(Company $a, Company $b, int $dir): int
    {
        $primary = $dir * match ($this->childrenSortBy) {
            'name' => strcmp((string) $a->name, (string) $b->name),
            'status' => strcmp((string) $a->status, (string) $b->status),
            'legal_entity_type' => strcmp(
                (string) ($a->legalEntityType?->name ?? ''),
                (string) ($b->legalEntityType?->name ?? ''),
            ),
            'jurisdiction' => strcmp(
                strtoupper((string) ($a->jurisdiction ?? '')),
                strtoupper((string) ($b->jurisdiction ?? '')),
            ),
            default => strcmp((string) $a->name, (string) $b->name),
        };

        if ($primary !== 0) {
            return $primary;
        }

        return $a->id <=> $b->id;
    }

    /**
     * @param  Collection<int, Department>  $departments
     * @return Collection<int, Department>
     */
    private function sortDepartmentsCollection(Collection $departments): Collection
    {
        $dir = $this->departmentsSortDir === 'desc' ? -1 : 1;

        return $departments
            ->sort(fn (Department $a, Department $b): int => $this->compareDepartment($a, $b, $dir))
            ->values();
    }

    private function compareDepartment(Department $a, Department $b, int $dir): int
    {
        $primary = $dir * match ($this->departmentsSortBy) {
            'department_type' => strcmp(
                (string) ($a->type?->name ?? ''),
                (string) ($b->type?->name ?? ''),
            ),
            'category' => strcmp(
                (string) ($a->type?->category ?? ''),
                (string) ($b->type?->category ?? ''),
            ),
            'status' => strcmp((string) $a->status, (string) $b->status),
            'head' => strcmp($this->departmentHeadName($a), $this->departmentHeadName($b)),
            default => strcmp((string) ($a->type?->name ?? ''), (string) ($b->type?->name ?? '')),
        };

        if ($primary !== 0) {
            return $primary;
        }

        return $a->id <=> $b->id;
    }

    private function departmentHeadName(Department $department): string
    {
        return $department->head?->displayName() ?? '';
    }

    /**
     * @return Collection<int, object>
     */
    private function allRelationshipRows(): Collection
    {
        $outgoing = $this->company->relationships->map(fn (CompanyRelationship $relationship) => (object) [
            'id' => $relationship->id,
            'direction_key' => 'outgoing',
            'company' => $relationship->relatedCompany,
            'type' => $relationship->type,
            'direction' => __('Outgoing'),
            'effective_from' => $relationship->effective_from,
            'effective_to' => $relationship->effective_to,
            'is_active' => $relationship->isActive(),
        ]);

        $incoming = $this->company->inverseRelationships->map(fn (CompanyRelationship $relationship) => (object) [
            'id' => $relationship->id,
            'direction_key' => 'incoming',
            'company' => $relationship->company,
            'type' => $relationship->type,
            'direction' => __('Incoming'),
            'effective_from' => $relationship->effective_from,
            'effective_to' => $relationship->effective_to,
            'is_active' => $relationship->isActive(),
        ]);

        return $outgoing->concat($incoming)->filter(fn (object $row): bool => $row->company !== null);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    private function sortRelationshipsCollection(Collection $rows): Collection
    {
        $dir = $this->relationshipsSortDir === 'desc' ? -1 : 1;

        return $rows
            ->sort(fn (object $a, object $b): int => $this->compareRelationshipRow($a, $b, $dir))
            ->values();
    }

    private function compareRelationshipRow(object $a, object $b, int $dir): int
    {
        $primary = $dir * match ($this->relationshipsSortBy) {
            'company_name' => strcmp((string) $a->company->name, (string) $b->company->name),
            'relationship_type' => strcmp(
                (string) ($a->type?->name ?? ''),
                (string) ($b->type?->name ?? ''),
            ),
            'direction' => strcmp((string) $a->direction_key, (string) $b->direction_key),
            'effective_from' => strcmp($this->dateKey($a->effective_from), $this->dateKey($b->effective_from)),
            'effective_to' => strcmp($this->dateKey($a->effective_to), $this->dateKey($b->effective_to)),
            'is_active' => ((int) (bool) $a->is_active) <=> ((int) (bool) $b->is_active),
            default => strcmp((string) $a->company->name, (string) $b->company->name),
        };

        if ($primary !== 0) {
            return $primary;
        }

        $tie = strcmp((string) $a->direction_key, (string) $b->direction_key);

        if ($tie !== 0) {
            return $tie;
        }

        return $a->id <=> $b->id;
    }

    private function dateKey(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) ($value ?? '');
    }
}
