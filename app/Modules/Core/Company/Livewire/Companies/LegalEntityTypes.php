<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Livewire\Companies;

use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Modules\Core\Company\Models\LegalEntityType;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class LegalEntityTypes extends Component
{
    use SavesValidatedFields;
    use WithPagination;

    public bool $showCreateModal = false;

    public string $create_code = '';

    public string $create_name = '';

    public ?string $create_description = null;

    public bool $create_is_active = true;

    public function createType(): void
    {
        $validated = $this->validate([
            'create_code' => ['required', 'string', 'max:255', Rule::unique('company_legal_entity_types', 'code')],
            'create_name' => ['required', 'string', 'max:255'],
            'create_description' => ['nullable', 'string'],
            'create_is_active' => ['boolean'],
        ]);

        LegalEntityType::query()->create([
            'code' => $validated['create_code'],
            'name' => $validated['create_name'],
            'description' => $validated['create_description'],
            'is_active' => $validated['create_is_active'],
        ]);

        $this->showCreateModal = false;
        $this->reset(['create_code', 'create_name', 'create_description', 'create_is_active']);
        $this->create_is_active = true;
        Session::flash('success', __('Legal entity type created.'));
    }

    public function saveField(int $typeId, string $field, mixed $value): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];

        $type = LegalEntityType::query()->findOrFail($typeId);
        $this->saveValidatedField($type, $field, $value, $rules);
    }

    public function toggleActive(int $typeId): void
    {
        $type = LegalEntityType::query()->findOrFail($typeId);
        $type->is_active = ! $type->is_active;
        $type->save();
    }

    public function deleteType(int $typeId): void
    {
        $type = LegalEntityType::query()->withCount('companies')->findOrFail($typeId);

        if ($type->companies_count > 0) {
            Session::flash('error', __('Cannot delete a legal entity type that is in use by companies.'));

            return;
        }

        $type->delete();
        Session::flash('success', __('Legal entity type deleted.'));
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.companies.legal-entity-types', [
            'types' => LegalEntityType::query()
                ->orderBy('name')
                ->paginate(15),
        ]);
    }
}
