<?php

namespace App\Modules\Core\Employee\Livewire\Employees;

use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\AI\Contracts\ProvidesLaraPageContext;
use App\Modules\Core\AI\Contracts\ProvidesLaraPageSnapshot;
use App\Modules\Core\Company\Models\Department;
use App\Modules\Core\Employee\Livewire\Employees\Concerns\ProvidesEmployeeShowLaraContext;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\Employee\Models\EmployeeType;
use App\Modules\Core\User\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

class Show extends Component implements ProvidesLaraPageContext, ProvidesLaraPageSnapshot
{
    use ProvidesEmployeeShowLaraContext;
    use SavesValidatedFields;
    use TogglesSort;

    public Employee $employee;

    public int $attachAddressId = 0;

    public array $attachKind = [];

    public bool $attachIsPrimary = false;

    public int $attachPriority = 0;

    public bool $showAttachModal = false;

    public string $subordinatesSortBy = 'full_name';

    public string $subordinatesSortDir = 'asc';

    public string $addressesSortBy = 'label';

    public string $addressesSortDir = 'asc';

    private const SUBORDINATE_SORTABLE = [
        'full_name' => true,
        'designation' => true,
        'status' => true,
        'department' => true,
    ];

    private const ADDRESS_SORTABLE = [
        'label' => true,
        'line1' => true,
        'kind' => true,
        'is_primary' => true,
        'priority' => true,
        'valid_from' => true,
        'valid_to' => true,
    ];

    public function mount(Employee $employee): void
    {
        /** @var User $user */
        $user = auth()->user();

        if (! $user->isPlatformAdmin() && $employee->company_id !== $user->getCompanyId()) {
            abort(403);
        }

        $this->employee = $employee->load([
            'company',
            'department.type',
            'supervisor',
            'user',
            'subordinates.department.type',
            'addresses',
        ]);
    }

    public function sortSubordinates(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SUBORDINATE_SORTABLE,
            defaultDir: [
                'full_name' => 'asc',
                'designation' => 'asc',
                'status' => 'asc',
                'department' => 'asc',
            ],
            sortByProperty: 'subordinatesSortBy',
            sortDirProperty: 'subordinatesSortDir',
            resetPage: false,
        );
    }

    public function sortAddresses(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::ADDRESS_SORTABLE,
            defaultDir: [
                'label' => 'asc',
                'line1' => 'asc',
                'kind' => 'asc',
                'is_primary' => 'desc',
                'priority' => 'asc',
                'valid_from' => 'asc',
                'valid_to' => 'asc',
            ],
            sortByProperty: 'addressesSortBy',
            sortDirProperty: 'addressesSortDir',
            resetPage: false,
        );
    }

    public function saveField(string $field, mixed $value): void
    {
        $rules = [
            'full_name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'job_description' => ['nullable', 'string', 'max:65535'],
            'email' => ['nullable', 'email', 'max:255'],
            'mobile_number' => ['nullable', 'string', 'max:255'],
            'employee_number' => ['required', 'string', 'max:255'],
        ];

        $this->saveValidatedField($this->employee, $field, $value, $rules);
    }

    public function saveStatus(string $status): void
    {
        if (! in_array($status, ['pending', 'probation', 'active', 'inactive', 'terminated'])) {
            $this->notifyError(__('The selected employee status is not valid.'));

            return;
        }

        $this->employee->status = $status;
        $this->employee->save();
        $this->notify(__('Employee status updated.'));
    }

    public function saveEmployeeType(string $type): void
    {
        $exists = EmployeeType::query()->where('code', $type)->exists();
        if (! $exists) {
            $this->notifyError(__('The selected employee type is not valid.'));

            return;
        }

        $this->employee->employee_type = $type;
        if ($type === 'agent') {
            User::query()
                ->where('employee_id', $this->employee->id)
                ->update(['employee_id' => null]);

            $this->employee->unsetRelation('user');
        }
        $this->employee->save();
        $this->notify(__('Employee type updated.'));
    }

    public function saveDepartment(?int $departmentId): void
    {
        if ($departmentId !== null && ! $this->belongsToSameCompany(Department::class, $departmentId)) {
            $this->notifyError(__('The selected department is not available for this tenant.'));

            return;
        }

        $this->employee->department_id = $departmentId ?: null;
        $this->employee->save();
        $this->employee->load('department.type');
        $this->notify(__('Department assignment updated.'));
    }

    public function saveSupervisor(?int $supervisorId): void
    {
        if ($supervisorId !== null && ! $this->belongsToSameCompany(Employee::class, $supervisorId)) {
            $this->notifyError(__('The selected supervisor is not available for this tenant.'));

            return;
        }

        $this->employee->supervisor_id = $supervisorId ?: null;
        $this->employee->save();
        $this->employee->load('supervisor');
        $this->notify(__('Supervisor assignment updated.'));
    }

    /**
     * Verify a related model belongs to the same company as this employee.
     */
    private function belongsToSameCompany(string $modelClass, int $modelId): bool
    {
        /** @var User $user */
        $user = auth()->user();

        if ($user->isPlatformAdmin()) {
            return true;
        }

        $model = $modelClass::query()->find($modelId);

        return $model !== null && $model->company_id === $this->employee->company_id;
    }

    public function saveUser(?int $userId): void
    {
        User::query()
            ->where('employee_id', $this->employee->id)
            ->when($userId !== null, fn ($query) => $query->whereKeyNot($userId))
            ->update(['employee_id' => null]);

        if ($userId !== null) {
            $user = User::query()
                ->whereKey($userId)
                ->where('company_id', $this->employee->company_id)
                ->where(function ($query): void {
                    $query->whereNull('employee_id')
                        ->orWhere('employee_id', $this->employee->id);
                })
                ->first();

            if (! $user) {
                $this->employee->load('user');
                $this->notifyError(__('The selected user is not available for this employee.'));

                return;
            }

            $user->update(['employee_id' => $this->employee->id]);
        }

        $this->employee->load('user');
        $this->notify(__('User link updated.'));
    }

    public function addSubordinate(int $employeeId): void
    {
        $target = Employee::query()->find($employeeId);
        if (! $target || $target->company_id !== $this->employee->company_id) {
            $this->notifyError(__('The selected subordinate is not valid for this employee.'));

            return;
        }

        $target->supervisor_id = $this->employee->id;
        $target->save();
        $this->employee->load('subordinates.department.type');
        $this->notify(__('Subordinate assigned.'));
    }

    public function removeSubordinate(int $employeeId): void
    {
        $target = Employee::query()
            ->where('id', $employeeId)
            ->where('supervisor_id', $this->employee->id)
            ->first();

        if (! $target) {
            return;
        }

        $target->supervisor_id = null;
        $target->save();
        $this->employee->load('subordinates.department.type');
        $this->notify(__('Subordinate removed.'));
    }

    public function attachAddress(): void
    {
        if ($this->attachAddressId === 0) {
            $this->notifyError(__('Choose an address before attaching.'));

            return;
        }

        $this->employee->addresses()->attach($this->attachAddressId, [
            'kind' => $this->attachKind,
            'is_primary' => $this->attachIsPrimary,
            'priority' => $this->attachPriority,
            'valid_from' => now()->toDateString(),
        ]);

        $this->employee->load('addresses');
        $this->showAttachModal = false;
        $this->reset(['attachAddressId', 'attachKind', 'attachIsPrimary', 'attachPriority']);
        $this->notify(__('Address attached.'));
    }

    public function unlinkAddress(int $addressId): void
    {
        $this->employee->addresses()->detach($addressId);
        $this->employee->load('addresses');
        $this->notify(__('Address unlinked.'));
    }

    public function updateAddressPivot(int $addressId, string $field, mixed $value): void
    {
        $allowed = ['is_primary', 'priority'];
        if (! in_array($field, $allowed)) {
            $this->notifyError(__('The selected address setting is not valid.'));

            return;
        }

        if ($field === 'is_primary') {
            $value = (bool) $value;
        } elseif ($field === 'priority') {
            $value = (int) $value;
        }

        $this->employee->addresses()->updateExistingPivot($addressId, [$field => $value]);
        $this->employee->load('addresses');
        $this->notify(__('Address setting updated.'));
    }

    public function saveAddressKinds(int $addressId, array $kinds): void
    {
        $valid = ['headquarters', 'billing', 'shipping', 'branch', 'other'];
        $kinds = array_values(array_intersect($kinds, $valid));

        $this->employee->addresses()->updateExistingPivot($addressId, ['kind' => $kinds]);
        $this->employee->load('addresses');
        $this->notify(__('Address kinds updated.'));
    }

    public function render(): View
    {
        $this->employee->loadMissing('subordinates.department.type');

        return view('livewire.admin.employees.show', [
            'sortedSubordinates' => $this->sortSubordinatesCollection($this->employee->subordinates),
            'sortedAddresses' => $this->sortAddressesCollection($this->employee->addresses),
            'departments' => Department::query()
                ->where('company_id', $this->employee->company_id)
                ->with('type')
                ->get(['id', 'company_id', 'department_type_id']),
            'supervisors' => Employee::query()
                ->where('company_id', $this->employee->company_id)
                ->where('id', '!=', $this->employee->id)
                ->orderBy('full_name')
                ->get(['id', 'full_name']),
            'employeeTypes' => EmployeeType::query()->global()->orderBy('code')->get(['id', 'code', 'label']),
            'users' => User::query()
                ->where(function ($query): void {
                    $query->whereNull('employee_id')
                        ->orWhere('employee_id', $this->employee->id);
                })
                ->orderBy('name')
                ->get(['id', 'name', 'employee_id']),
            'availableSubordinates' => Employee::query()
                ->where('company_id', $this->employee->company_id)
                ->where('id', '!=', $this->employee->id)
                ->where(function ($query) {
                    $query->whereNull('supervisor_id')
                        ->orWhere('supervisor_id', '!=', $this->employee->id);
                })
                ->orderBy('full_name')
                ->get(['id', 'full_name']),
            'availableAddresses' => Address::query()
                ->whereNotIn('id', $this->employee->addresses->pluck('id')->toArray())
                ->orderBy('label')
                ->get(['id', 'label', 'line1', 'locality', 'country_iso']),
        ]);
    }

    /**
     * @param  iterable<int, Employee>  $subordinates
     */
    private function sortSubordinatesCollection(iterable $subordinates): Collection
    {
        $dir = $this->subordinatesSortDir === 'desc' ? -1 : 1;

        return collect($subordinates)
            ->sort(function (Employee $a, Employee $b) use ($dir): int {
                $deptA = (string) ($a->department?->type?->name ?? '');
                $deptB = (string) ($b->department?->type?->name ?? '');

                $primary = match ($this->subordinatesSortBy) {
                    'full_name' => $dir * strcmp((string) $a->full_name, (string) $b->full_name),
                    'designation' => $dir * strcmp((string) ($a->designation ?? ''), (string) ($b->designation ?? '')),
                    'status' => $dir * strcmp((string) $a->status, (string) $b->status),
                    'department' => $dir * strcmp($deptA, $deptB),
                    default => $dir * strcmp((string) $a->full_name, (string) $b->full_name),
                };

                if ($primary !== 0) {
                    return $primary;
                }

                return $a->id <=> $b->id;
            })
            ->values();
    }

    /**
     * @param  iterable<int, Address>  $addresses
     */
    private function sortAddressesCollection(iterable $addresses): Collection
    {
        $dir = $this->addressesSortDir === 'desc' ? -1 : 1;

        return collect($addresses)
            ->sort(function (Address $a, Address $b) use ($dir): int {
                $kindKey = function (Address $address): string {
                    $kinds = $address->pivot->kind ?? [];
                    $kinds = is_array($kinds) ? $kinds : [];
                    sort($kinds);

                    return implode(',', $kinds);
                };

                $primary = match ($this->addressesSortBy) {
                    'label' => $dir * strcmp((string) ($a->label ?? ''), (string) ($b->label ?? '')),
                    'line1' => $dir * strcmp((string) ($a->line1 ?? ''), (string) ($b->line1 ?? '')),
                    'kind' => $dir * strcmp($kindKey($a), $kindKey($b)),
                    'is_primary' => $dir * (((int) ($a->pivot->is_primary ?? false)) <=> ((int) ($b->pivot->is_primary ?? false))),
                    'priority' => $dir * (((int) ($a->pivot->priority ?? 0)) <=> ((int) ($b->pivot->priority ?? 0))),
                    'valid_from' => $dir * strcmp((string) ($a->pivot->valid_from ?? ''), (string) ($b->pivot->valid_from ?? '')),
                    'valid_to' => $dir * strcmp((string) ($a->pivot->valid_to ?? ''), (string) ($b->pivot->valid_to ?? '')),
                    default => $dir * strcmp((string) ($a->label ?? ''), (string) ($b->label ?? '')),
                };

                if ($primary !== 0) {
                    return $primary;
                }

                return $a->id <=> $b->id;
            })
            ->values();
    }
}
