<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Livewire\Companies;

use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\CompanyRelationship;
use App\Modules\Core\Company\Models\RelationshipType;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

class Relationships extends Component
{
    use TogglesSort;

    public Company $company;

    public bool $showCreateModal = false;

    public int $createRelatedCompanyId = 0;

    public int $createRelationshipTypeId = 0;

    public ?string $createEffectiveFrom = null;

    public ?string $createEffectiveTo = null;

    public bool $showEditModal = false;

    public ?int $editRelationshipId = null;

    public ?string $editEffectiveFrom = null;

    public ?string $editEffectiveTo = null;

    public string $sortBy = 'other';

    public string $sortDir = 'asc';

    private const SORTABLE = [
        'other' => true,
        'type' => true,
        'direction' => true,
        'effective_from' => true,
        'effective_to' => true,
        'active' => true,
    ];

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'other' => 'asc',
                'type' => 'asc',
                'direction' => 'asc',
                'effective_from' => 'asc',
                'effective_to' => 'asc',
                'active' => 'desc',
            ],
            resetPage: false,
        );
    }

    public function createRelationship(): void
    {
        if ($this->createRelatedCompanyId === 0 || $this->createRelationshipTypeId === 0) {
            return;
        }

        CompanyRelationship::query()->create([
            'company_id' => $this->company->id,
            'related_company_id' => $this->createRelatedCompanyId,
            'relationship_type_id' => $this->createRelationshipTypeId,
            'effective_from' => $this->createEffectiveFrom,
            'effective_to' => $this->createEffectiveTo,
        ]);

        $this->showCreateModal = false;
        $this->reset(['createRelatedCompanyId', 'createRelationshipTypeId', 'createEffectiveFrom', 'createEffectiveTo']);
        Session::flash('success', __('Relationship created.'));
    }

    public function editRelationship(int $relationshipId): void
    {
        $rel = CompanyRelationship::query()->findOrFail($relationshipId);
        $this->editRelationshipId = $rel->id;
        $this->editEffectiveFrom = $rel->effective_from?->format('Y-m-d');
        $this->editEffectiveTo = $rel->effective_to?->format('Y-m-d');
        $this->showEditModal = true;
    }

    public function updateRelationship(): void
    {
        if (! $this->editRelationshipId) {
            return;
        }

        $rel = CompanyRelationship::query()->findOrFail($this->editRelationshipId);
        $rel->effective_from = $this->editEffectiveFrom;
        $rel->effective_to = $this->editEffectiveTo;
        $rel->save();

        $this->showEditModal = false;
        $this->reset(['editRelationshipId', 'editEffectiveFrom', 'editEffectiveTo']);
        Session::flash('success', __('Relationship updated.'));
    }

    public function deleteRelationship(int $relationshipId): void
    {
        CompanyRelationship::query()->findOrFail($relationshipId)->delete();
        Session::flash('success', __('Relationship deleted.'));
    }

    public function render(): View
    {
        $outgoing = CompanyRelationship::query()
            ->where('company_id', $this->company->id)
            ->with(['relatedCompany', 'type'])
            ->get()
            ->map(fn ($r) => (object) ['relationship' => $r, 'direction' => 'outgoing', 'other' => $r->relatedCompany]);

        $incoming = CompanyRelationship::query()
            ->where('related_company_id', $this->company->id)
            ->with(['company', 'type'])
            ->get()
            ->map(fn ($r) => (object) ['relationship' => $r, 'direction' => 'incoming', 'other' => $r->company]);

        $allRelationships = $outgoing->merge($incoming);

        $sorted = $this->sortRelationshipsCollection($allRelationships);

        return view('livewire.admin.companies.relationships', [
            'allRelationships' => $sorted,
            'availableCompanies' => Company::query()
                ->where('id', '!=', $this->company->id)
                ->orderBy('name')
                ->get(['id', 'name']),
            'relationshipTypes' => RelationshipType::query()
                ->active()
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * @param  Collection<int, object{relationship: CompanyRelationship, direction: string, other: Company}>  $rows
     * @return Collection<int, object{relationship: CompanyRelationship, direction: string, other: Company}>
     */
    private function sortRelationshipsCollection(Collection $rows): Collection
    {
        $dir = $this->sortDir === 'desc' ? -1 : 1;

        $cmp = function (object $a, object $b) use ($dir): int {
            $primary = match ($this->sortBy) {
                'other' => $dir * strcmp((string) $a->other->name, (string) $b->other->name),
                'type' => $dir * strcmp((string) $a->relationship->type->name, (string) $b->relationship->type->name),
                'direction' => $dir * strcmp((string) $a->direction, (string) $b->direction),
                'effective_from' => $dir * ($a->relationship->effective_from?->getTimestamp() <=> $b->relationship->effective_from?->getTimestamp()),
                'effective_to' => $dir * ($a->relationship->effective_to?->getTimestamp() <=> $b->relationship->effective_to?->getTimestamp()),
                'active' => $dir * ((int) $a->relationship->isActive() <=> (int) $b->relationship->isActive()),
                default => $dir * strcmp((string) $a->other->name, (string) $b->other->name),
            };

            if ($primary !== 0) {
                return $primary;
            }

            return $a->relationship->id <=> $b->relationship->id;
        };

        return $rows->sort($cmp)->values();
    }
}
