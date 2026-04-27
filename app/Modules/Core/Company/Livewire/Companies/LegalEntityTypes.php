<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Livewire\Companies;

use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Modules\Core\Company\Models\LegalEntityType;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class LegalEntityTypes extends Component
{
    use SavesValidatedFields;
    use WithPagination;

    public bool $showCreateModal = false;

    public string $createCode = '';

    public string $createName = '';

    public ?string $createDescription = null;

    public bool $createIsActive = true;

    public function createType(): void
    {
        $validated = $this->validate([
            'createCode' => ['required', 'string', 'max:255', Rule::unique('company_legal_entity_types', 'code')],
            'createName' => ['required', 'string', 'max:255'],
            'createDescription' => ['nullable', 'string'],
            'createIsActive' => ['boolean'],
        ]);

        LegalEntityType::query()->create([
            'code' => $validated['createCode'],
            'name' => $validated['createName'],
            'description' => $validated['createDescription'],
            'is_active' => $validated['createIsActive'],
        ]);

        $this->showCreateModal = false;
        $this->reset(['createCode', 'createName', 'createDescription', 'createIsActive']);
        $this->createIsActive = true;
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

    public function render(): View
    {
        return view('livewire.admin.companies.legal-entity-types', [
            'types' => LegalEntityType::query()
                ->orderBy('name')
                ->paginate(15),
        ]);
    }
}
