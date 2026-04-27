<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Employee\Livewire\EmployeeTypes;

use App\Modules\Core\Employee\Models\EmployeeType;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Edit extends Component
{
    public EmployeeType $employeeType;

    public string $label = '';

    public function mount(EmployeeType $employeeType): void
    {
        $this->employeeType = $employeeType;
        if ($employeeType->is_system) {
            abort(403, __('System employee types cannot be edited.'));
        }
        $this->label = $employeeType->label;
    }

    public function save(): void
    {
        $this->validate([
            'label' => ['required', 'string', 'max:255'],
        ]);

        $this->employeeType->update(['label' => $this->label]);

        session()->flash('success', __('Employee type updated.'));
        $this->redirect(route('admin.employee-types.index'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.admin.employee-types.edit');
    }
}
