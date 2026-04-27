<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Employee\Livewire\EmployeeTypes;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Modules\Core\Employee\Models\EmployeeType;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use WithPagination;

    public string $search = '';

    public function delete(int $id): void
    {
        $type = EmployeeType::query()->findOrFail($id);
        if ($type->is_system) {
            return;
        }
        if ($type->employees_count > 0) {
            session()->flash('error', __('Cannot delete: employees are using this type.'));

            return;
        }
        $type->delete();
        session()->flash('success', __('Employee type deleted.'));
    }

    public function render(): View
    {
        $authUser = auth()->user();
        $authActor = Actor::forUser($authUser);
        $canCreate = app(AuthorizationService::class)->can($authActor, 'core.employee_type.create')->allowed;

        return view('livewire.admin.employee-types.index', [
            'canCreate' => $canCreate,
            'employeeTypes' => EmployeeType::query()
                ->global()
                ->withCount('employees')
                ->when($this->search, fn ($q) => $q->where('code', 'like', '%'.$this->search.'%')
                    ->orWhere('label', 'like', '%'.$this->search.'%'))
                ->orderByDesc('is_system')
                ->orderBy('code')
                ->paginate(15),
        ]);
    }
}
