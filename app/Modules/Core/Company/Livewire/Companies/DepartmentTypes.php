<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Livewire\Companies;

use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Modules\Core\Company\Models\DepartmentType;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class DepartmentTypes extends Component
{
    use SavesValidatedFields;
    use WithPagination;

    public bool $showCreateModal = false;

    public string $create_code = '';

    public string $create_name = '';

    public string $create_category = 'operational';

    public ?string $create_description = null;

    public bool $create_is_active = true;

    public function createType(): void
    {
        $validated = $this->validate([
            'create_code' => ['required', 'string', 'max:255', Rule::unique('company_department_types', 'code')],
            'create_name' => ['required', 'string', 'max:255'],
            'create_category' => ['required', 'string', Rule::in(['administrative', 'operational', 'revenue', 'support'])],
            'create_description' => ['nullable', 'string'],
            'create_is_active' => ['boolean'],
        ]);

        DepartmentType::query()->create([
            'code' => $validated['create_code'],
            'name' => $validated['create_name'],
            'category' => $validated['create_category'],
            'description' => $validated['create_description'],
            'is_active' => $validated['create_is_active'],
        ]);

        $this->showCreateModal = false;
        $this->reset(['create_code', 'create_name', 'create_category', 'create_description', 'create_is_active']);
        $this->create_category = 'operational';
        $this->create_is_active = true;
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

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.companies.department-types', [
            'types' => DepartmentType::query()
                ->orderBy('category')
                ->orderBy('name')
                ->paginate(15),
        ]);
    }
}
