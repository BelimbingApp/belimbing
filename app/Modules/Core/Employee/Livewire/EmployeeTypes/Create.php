<?php
namespace App\Modules\Core\Employee\Livewire\EmployeeTypes;

use App\Modules\Core\Employee\Models\EmployeeType;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Create extends Component
{
    public string $code = '';

    public string $label = '';

    public function createType(): void
    {
        $this->validate([
            'code' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('employee_types', 'code')->whereNull('company_id'),
            ],
            'label' => ['required', 'string', 'max:255'],
        ]);

        EmployeeType::query()->create([
            'code' => $this->code,
            'label' => $this->label,
            'is_system' => false,
        ]);

        session()->flash('success', __('Employee type created.'));
        $this->redirect(route('admin.employee-types.index'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.admin.employee-types.create');
    }
}
