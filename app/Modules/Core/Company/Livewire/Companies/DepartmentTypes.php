<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Livewire\Companies;

use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Modules\Core\Company\Models\DepartmentType;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class DepartmentTypes extends Component
{
    use SavesValidatedFields;
    use TogglesSort;
    use WithPagination;

    /**
     * @var list<string>
     */
    private const CATEGORY_OPTIONS = [
        'administrative',
        'operational',
        'revenue',
        'support',
    ];

    public bool $showCreateModal = false;

    public string $createCode = '';

    public string $createName = '';

    public string $createCategory = 'operational';

    public ?string $createDescription = null;

    public bool $createIsActive = true;

    public string $sortBy = 'category';

    public string $sortDir = 'asc';

    private const SORTABLE = [
        'code' => 'code',
        'name' => 'name',
        'category' => 'category',
        'is_active' => 'is_active',
    ];

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'code' => 'asc',
                'name' => 'asc',
                'category' => 'asc',
                'is_active' => 'desc',
            ],
        );
    }

    public function createType(): void
    {
        $validated = $this->validate([
            'createCode' => ['required', 'string', 'max:255', Rule::unique('company_department_types', 'code')],
            'createName' => ['required', 'string', 'max:255'],
            'createCategory' => ['required', 'string', Rule::in(self::CATEGORY_OPTIONS)],
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
            'category' => ['required', 'string', Rule::in(self::CATEGORY_OPTIONS)],
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
        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'category';

        return view('livewire.admin.companies.department-types', [
            'types' => DepartmentType::query()
                ->orderBy($sortColumn, $this->sortDir)
                ->orderByDesc('id')
                ->paginate(15),
        ]);
    }
}
