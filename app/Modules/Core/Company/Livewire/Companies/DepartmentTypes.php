<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Livewire\Companies;

use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Modules\Core\Company\Models\DepartmentType;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class DepartmentTypes extends Component
{
    use SavesValidatedFields;
    use WithPagination;

    public bool $showCreateModal = false;

    public string $createCode = '';

    public string $createName = '';

    public string $createCategory = 'operational';

    public ?string $createDescription = null;

    public bool $createIsActive = true;

    public function createType(): void
    {
        $validated = $this->validate([
            'createCode' => ['required', 'string', 'max:255', Rule::unique('company_department_types', 'code')],
            'createName' => ['required', 'string', 'max:255'],
            'createCategory' => ['required', 'string', Rule::in(['administrative', 'operational', 'revenue', 'support'])],
            'createDescription' => ['nullable', 'string'],
            'createIsActive' => ['boolean'],
        ]);

        DepartmentType::query()->create([
            'code' => $validated['createCode'],
            'name' => $validated['createName'],
            'category' => $validated['createCategory'],
            'description' => $validated['createDescription'],
            'is_active' => $validated['createIsActive'],
        ]);

        $this->showCreateModal = false;
        $this->reset(['createCode', 'createName', 'createCategory', 'createDescription', 'createIsActive']);
        $this->createCategory = 'operational';
        $this->createIsActive = true;
        Session::flash('success', __('Department type created.'));
    }

    public function saveField(int $typeId, string $field, mixed $value): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', Rule::in(['administrative', 'operational', 'revenue', 'support'])],
            'description' => ['nullable', 'string'],
        ];

        $type = DepartmentType::query()->findOrFail($typeId);
        $this->saveValidatedField($type, $field, $value, $rules);
    }

    public function toggleActive(int $typeId): void
    {
        $type = DepartmentType::query()->findOrFail($typeId);
        $type->is_active = ! $type->is_active;
        $type->save();
    }

    public function deleteType(int $typeId): void
    {
        $type = DepartmentType::query()->withCount('departments')->findOrFail($typeId);

        if ($type->departments_count > 0) {
            Session::flash('error', __('Cannot delete a department type that is in use by departments.'));

            return;
        }

        $type->delete();
        Session::flash('success', __('Department type deleted.'));
    }

    public function render(): View
    {
        return view('livewire.admin.companies.department-types', [
            'types' => DepartmentType::query()
                ->orderBy('category')
                ->orderBy('name')
                ->paginate(15),
        ]);
    }
}
