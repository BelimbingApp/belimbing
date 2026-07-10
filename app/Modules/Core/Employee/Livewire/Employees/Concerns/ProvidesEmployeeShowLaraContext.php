<?php

namespace App\Modules\Core\Employee\Livewire\Employees\Concerns;

use App\Modules\Core\AI\DTO\FormFieldSnapshot;
use App\Modules\Core\AI\DTO\FormSnapshot;
use App\Modules\Core\AI\DTO\ModalSnapshot;
use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\DTO\PageSnapshot;

trait ProvidesEmployeeShowLaraContext
{
    public function pageContext(?string $pageUrl = null): PageContext
    {
        return new PageContext(
            route: 'admin.employees.show',
            url: route('admin.employees.show', $this->employee),
            title: $this->employee->full_name,
            module: 'Employee',
            resourceType: 'employee',
            resourceId: $this->employee->id,
            visibleActions: ['Edit fields', 'Change status', 'Manage addresses', 'Manage subordinates'],
        );
    }

    public function pageSnapshot(): PageSnapshot
    {
        $fields = [
            new FormFieldSnapshot('full_name', 'string', $this->employee->full_name),
            new FormFieldSnapshot('short_name', 'string', $this->employee->short_name),
            new FormFieldSnapshot('employee_number', 'string', $this->employee->employee_number),
            new FormFieldSnapshot('email', 'string', $this->employee->email),
            new FormFieldSnapshot('designation', 'string', $this->employee->designation),
            new FormFieldSnapshot('status', 'string', $this->employee->status),
            new FormFieldSnapshot('employee_type', 'string', $this->employee->employee_type),
            new FormFieldSnapshot('company', 'string', $this->employee->company?->name),
            new FormFieldSnapshot('department', 'string', $this->employee->department?->type?->label),
            new FormFieldSnapshot('supervisor', 'string', $this->employee->supervisor?->full_name),
        ];

        $modals = [];

        if ($this->showAttachModal) {
            $modals[] = new ModalSnapshot('attach-address', 'Attach Address', true);
        }

        return new PageSnapshot(
            pageContext: $this->pageContext(),
            forms: [new FormSnapshot('employee-detail', fields: $fields)],
            modals: $modals,
        );
    }
}
