<?php

use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Company\Models\Department;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\Employee\Models\EmployeeType;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\Session;
use Livewire\Volt\Component;

new class extends Component
{
    public Employee $employee;

    public int $attach_address_id = 0;
    public array $attach_kind = [];
    public bool $attach_is_primary = false;
    public int $attach_priority = 0;
    public bool $showAttachModal = false;

    public function mount(Employee $employee): void
    {
        $this->employee = $employee->load([
            'company',
            'department.type',
            'supervisor',
            'user',
            'subordinates',
            'addresses',
        ]);
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

        if (! isset($rules[$field])) {
            return;
        }

        $validated = validator([$field => $value], [$field => $rules[$field]])->validate();

        $this->employee->$field = $validated[$field];
        $this->employee->save();
    }

    public function saveStatus(string $status): void
    {
        if (! in_array($status, ['pending', 'probation', 'active', 'inactive', 'terminated'])) {
            return;
        }

        $this->employee->status = $status;
        $this->employee->save();
    }

    public function saveEmployeeType(string $type): void
    {
        $exists = EmployeeType::query()->where('code', $type)->exists();
        if (! $exists) {
            return;
        }

        $this->employee->employee_type = $type;
        if ($type === 'digital_worker') {
            $this->employee->user_id = null;
        }
        $this->employee->save();
    }

    public function saveDepartment(?int $departmentId): void
    {
        $this->employee->department_id = $departmentId ?: null;
        $this->employee->save();
        $this->employee->load('department.type');
    }

    public function saveSupervisor(?int $supervisorId): void
    {
        $this->employee->supervisor_id = $supervisorId ?: null;
        $this->employee->save();
        $this->employee->load('supervisor');
    }

    public function saveUser(?int $userId): void
    {
        $this->employee->user_id = $userId ?: null;
        $this->employee->save();
        $this->employee->load('user');
    }

    public function addSubordinate(int $employeeId): void
    {
        $target = Employee::query()->find($employeeId);
        if (!$target || $target->company_id !== $this->employee->company_id) {
            return;
        }

        $target->supervisor_id = $this->employee->id;
        $target->save();
        $this->employee->load('subordinates');
    }

    public function removeSubordinate(int $employeeId): void
    {
        $target = Employee::query()
            ->where('id', $employeeId)
            ->where('supervisor_id', $this->employee->id)
            ->first();

        if (!$target) {
            return;
        }

        $target->supervisor_id = null;
        $target->save();
        $this->employee->load('subordinates');
    }

    public function attachAddress(): void
    {
        if ($this->attach_address_id === 0) {
            return;
        }

        $this->employee->addresses()->attach($this->attach_address_id, [
            'kind' => $this->attach_kind,
            'is_primary' => $this->attach_is_primary,
            'priority' => $this->attach_priority,
            'valid_from' => now()->toDateString(),
        ]);

        $this->employee->load('addresses');
        $this->showAttachModal = false;
        $this->reset(['attach_address_id', 'attach_kind', 'attach_is_primary', 'attach_priority']);
        Session::flash('success', __('Address attached.'));
    }

    public function unlinkAddress(int $addressId): void
    {
        $this->employee->addresses()->detach($addressId);
        $this->employee->load('addresses');
        Session::flash('success', __('Address unlinked.'));
    }

    public function updateAddressPivot(int $addressId, string $field, mixed $value): void
    {
        $allowed = ['is_primary', 'priority'];
        if (!in_array($field, $allowed)) {
            return;
        }

        if ($field === 'is_primary') {
            $value = (bool) $value;
        } elseif ($field === 'priority') {
            $value = (int) $value;
        }

        $this->employee->addresses()->updateExistingPivot($addressId, [$field => $value]);
        $this->employee->load('addresses');
    }

    public function saveAddressKinds(int $addressId, array $kinds): void
    {
        $valid = ['headquarters', 'billing', 'shipping', 'branch', 'other'];
        $kinds = array_values(array_intersect($kinds, $valid));

        $this->employee->addresses()->updateExistingPivot($addressId, ['kind' => $kinds]);
        $this->employee->load('addresses');
    }

    public function with(): array
    {
        return [
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
                ->orderBy('name')
                ->get(['id', 'name']),
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
        ];
    }
}; ?>

<div>
    <x-slot name="title">{{ $employee->displayName() }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$employee->displayName()" :subtitle="$employee->designation ?? $employee->job_description">
            <x-slot name="actions">
                <a href="{{ route('admin.employees.index') }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back to List') }}
                </a>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Employee Details') }}</h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div x-data="{ editing: false, val: '{{ addslashes($employee->full_name) }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Full Name') }}</dt>
                        <dd class="text-sm text-ink">
                            <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span x-text="val || '-'"></span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <input
                                x-show="editing"
                                x-ref="input"
                                x-model="val"
                                @keydown.enter="editing = false; $wire.saveField('full_name', val)"
                                @keydown.escape="editing = false; val = '{{ addslashes($employee->full_name) }}'"
                                @blur="editing = false; $wire.saveField('full_name', val)"
                                type="text"
                                class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            />
                        </dd>
                    </div>
                    <div x-data="{ editing: false, val: '{{ addslashes($employee->short_name ?? '') }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Short Name') }}</dt>
                        <dd class="text-sm text-ink">
                            <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span x-text="val || '-'"></span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <input
                                x-show="editing"
                                x-ref="input"
                                x-model="val"
                                @keydown.enter="editing = false; $wire.saveField('short_name', val)"
                                @keydown.escape="editing = false; val = '{{ addslashes($employee->short_name ?? '') }}'"
                                @blur="editing = false; $wire.saveField('short_name', val)"
                                type="text"
                                class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            />
                        </dd>
                    </div>
                    <div x-data="{ editing: false, val: '{{ addslashes($employee->employee_number) }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Employee Number') }}</dt>
                        <dd class="text-sm text-ink">
                            <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span class="font-mono" x-text="val || '-'"></span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <input
                                x-show="editing"
                                x-ref="input"
                                x-model="val"
                                @keydown.enter="editing = false; $wire.saveField('employee_number', val)"
                                @keydown.escape="editing = false; val = '{{ addslashes($employee->employee_number) }}'"
                                @blur="editing = false; $wire.saveField('employee_number', val)"
                                type="text"
                                class="w-full px-1 -mx-1 py-0.5 text-sm font-mono border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            />
                        </dd>
                    </div>
                    @if($employee->isDigitalWorker())
                    <div x-data="{ editing: false, val: '{{ addslashes($employee->job_description ?? '') }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Job Description') }}</dt>
                        <dd class="text-sm text-ink">
                            <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input?.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span x-text="val || '-'"></span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <textarea
                                x-show="editing"
                                x-ref="input"
                                x-model="val"
                                @blur="editing = false; $wire.saveField('job_description', val)"
                                rows="2"
                                class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            ></textarea>
                        </dd>
                    </div>
                    @endif
                    <div x-data="{ editing: false, val: '{{ addslashes($employee->designation ?? '') }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Designation') }}</dt>
                        <dd class="text-sm text-ink">
                            <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span x-text="val || '-'"></span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <input
                                x-show="editing"
                                x-ref="input"
                                x-model="val"
                                @keydown.enter="editing = false; $wire.saveField('designation', val)"
                                @keydown.escape="editing = false; val = '{{ addslashes($employee->designation ?? '') }}'"
                                @blur="editing = false; $wire.saveField('designation', val)"
                                type="text"
                                class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            />
                        </dd>
                    </div>
                    <div x-data="{ editing: false, val: '{{ addslashes($employee->email ?? '') }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Email') }}</dt>
                        <dd class="text-sm text-ink">
                            <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span x-text="val || '-'"></span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <input
                                x-show="editing"
                                x-ref="input"
                                x-model="val"
                                @keydown.enter="editing = false; $wire.saveField('email', val)"
                                @keydown.escape="editing = false; val = '{{ addslashes($employee->email ?? '') }}'"
                                @blur="editing = false; $wire.saveField('email', val)"
                                type="text"
                                class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            />
                        </dd>
                    </div>
                    <div x-data="{ editing: false, val: '{{ addslashes($employee->mobile_number ?? '') }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Mobile Number') }}</dt>
                        <dd class="text-sm text-ink">
                            <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span x-text="val || '-'"></span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <input
                                x-show="editing"
                                x-ref="input"
                                x-model="val"
                                @keydown.enter="editing = false; $wire.saveField('mobile_number', val)"
                                @keydown.escape="editing = false; val = '{{ addslashes($employee->mobile_number ?? '') }}'"
                                @blur="editing = false; $wire.saveField('mobile_number', val)"
                                type="text"
                                class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            />
                        </dd>
                    </div>
                </div>
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Employment Information') }}</h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Company') }}</dt>
                        <dd class="text-sm text-ink px-1 -mx-1 py-0.5">{{ $employee->company?->name ?? '-' }}</dd>
                    </div>
                    <div x-data="{ editing: false, val: '{{ $employee->department_id ?? '' }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Department') }}</dt>
                        <dd class="text-sm text-ink">
                            <div x-show="!editing" @click="editing = true" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span>
                                    @if($employee->department)
                                        {{ $employee->department->type->name ?? '-' }}
                                    @else
                                        <span class="text-muted">{{ __('None') }}</span>
                                    @endif
                                </span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <select
                                x-show="editing"
                                x-model="val"
                                @change="editing = false; $wire.saveDepartment(val ? parseInt(val) : null)"
                                @keydown.escape="editing = false; val = '{{ $employee->department_id ?? '' }}'"
                                @blur="editing = false"
                                class="px-2 py-1 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            >
                                <option value="">{{ __('None') }}</option>
                                @foreach($departments as $dept)
                                    <option value="{{ $dept->id }}">{{ $dept->type->name }}</option>
                                @endforeach
                            </select>
                        </dd>
                    </div>
                    <div x-data="{ editing: false, val: '{{ $employee->supervisor_id ?? '' }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Supervisor') }}</dt>
                        <dd class="text-sm text-ink">
                            <div x-show="!editing" @click="editing = true" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span>
                                    @if($employee->supervisor)
                                        {{ $employee->supervisor->full_name }}
                                    @else
                                        <span class="text-muted">{{ __('None') }}</span>
                                    @endif
                                </span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <select
                                x-show="editing"
                                x-model="val"
                                @change="editing = false; $wire.saveSupervisor(val ? parseInt(val) : null)"
                                @keydown.escape="editing = false; val = '{{ $employee->supervisor_id ?? '' }}'"
                                @blur="editing = false"
                                class="px-2 py-1 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            >
                                <option value="">{{ __('None') }}</option>
                                @foreach($supervisors as $sup)
                                    <option value="{{ $sup->id }}">{{ $sup->full_name }}</option>
                                @endforeach
                            </select>
                        </dd>
                    </div>
                    <div x-data="{ editing: false, val: '{{ $employee->employee_type ?? '' }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Employee Type') }}</dt>
                        <dd class="mt-0.5">
                            <div x-show="!editing" @click="editing = true" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                @if($employee->isDigitalWorker())
                                    <x-ui.badge variant="info">{{ __('Digital Worker') }}</x-ui.badge>
                                @else
                                    <span class="text-sm text-ink">{{ $employee->employee_type ? ucwords(str_replace('_', ' ', $employee->employee_type)) : '-' }}</span>
                                @endif
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <select
                                x-show="editing"
                                x-model="val"
                                @change="editing = false; $wire.saveEmployeeType(val)"
                                @keydown.escape="editing = false; val = '{{ $employee->employee_type ?? '' }}'"
                                @blur="editing = false"
                                class="px-2 py-1 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            >
                                <optgroup label="{{ __('Human') }}">
                                    @foreach($employeeTypes->where('code', '!=', 'digital_worker') as $type)
                                        <option value="{{ $type->code }}">{{ $type->label }}</option>
                                    @endforeach
                                </optgroup>
                                <optgroup label="{{ __('Digital Worker') }}">
                                    @foreach($employeeTypes->where('code', 'digital_worker') as $type)
                                        <option value="{{ $type->code }}">{{ $type->label }}</option>
                                    @endforeach
                                </optgroup>
                            </select>
                        </dd>
                    </div>
                    <div x-data="{ editing: false, val: '{{ $employee->status }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Status') }}</dt>
                        <dd class="mt-0.5">
                            <div x-show="!editing" @click="editing = true" class="group flex items-center gap-1.5 cursor-pointer">
                                <x-ui.badge :variant="match($employee->status) {
                                    'active' => 'success',
                                    'terminated' => 'danger',
                                    'probation' => 'warning',
                                    default => 'default',
                                }">{{ ucfirst($employee->status) }}</x-ui.badge>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <select
                                x-show="editing"
                                x-model="val"
                                @change="editing = false; $wire.saveStatus(val)"
                                @keydown.escape="editing = false; val = '{{ $employee->status }}'"
                                @blur="editing = false"
                                class="px-2 py-1 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            >
                                <option value="pending">{{ __('Pending') }}</option>
                                <option value="probation">{{ __('Probation') }}</option>
                                <option value="active">{{ __('Active') }}</option>
                                <option value="inactive">{{ __('Inactive') }}</option>
                                <option value="terminated">{{ __('Terminated') }}</option>
                            </select>
                        </dd>
                    </div>
                    @if(!$employee->isDigitalWorker())
                    <div x-data="{ editing: false, val: '{{ $employee->user_id ?? '' }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('User') }}</dt>
                        <dd class="text-sm text-ink">
                            <div x-show="!editing" @click="editing = true" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span>
                                    @if($employee->user)
                                        <a href="{{ route('admin.users.show', $employee->user) }}" wire:navigate class="text-accent hover:underline" @click.stop>{{ $employee->user->name }}</a>
                                    @else
                                        <span class="text-muted">{{ __('None') }}</span>
                                    @endif
                                </span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <select
                                x-show="editing"
                                x-model="val"
                                @change="editing = false; $wire.saveUser(val ? parseInt(val) : null)"
                                @keydown.escape="editing = false; val = '{{ $employee->user_id ?? '' }}'"
                                @blur="editing = false"
                                class="px-2 py-1 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            >
                                <option value="">{{ __('None') }}</option>
                                @foreach($users as $u)
                                    <option value="{{ $u->id }}">{{ $u->name }}</option>
                                @endforeach
                            </select>
                        </dd>
                    </div>
                    @endif
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Employment Start') }}</dt>
                        <dd class="text-sm text-ink px-1 -mx-1 py-0.5 tabular-nums">{{ $employee->employment_start?->format('Y-m-d') ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Employment End') }}</dt>
                        <dd class="text-sm text-ink px-1 -mx-1 py-0.5 tabular-nums">{{ $employee->employment_end?->format('Y-m-d') ?? '-' }}</dd>
                    </div>
                </div>
        </x-ui.card>

        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">
                    {{ __('Subordinates') }}
                    <x-ui.badge>{{ $employee->subordinates->count() }}</x-ui.badge>
                </h3>
                <div x-data="{ adding: false, selected: '' }">
                    <x-ui.button x-show="!adding" variant="primary" size="sm" @click="adding = true">
                        <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                        {{ __('Add') }}
                    </x-ui.button>
                    <div x-show="adding" class="flex items-center gap-2">
                        <select
                            x-model="selected"
                            class="px-2 py-1 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                        >
                            <option value="">{{ __('Select employee...') }}</option>
                            @foreach($availableSubordinates as $avail)
                                <option value="{{ $avail->id }}">{{ $avail->full_name }}</option>
                            @endforeach
                        </select>
                        <x-ui.button variant="primary" size="sm" @click="if (selected) { $wire.addSubordinate(parseInt(selected)); selected = ''; adding = false; }">
                            {{ __('Assign') }}
                        </x-ui.button>
                        <x-ui.button variant="ghost" size="sm" @click="adding = false; selected = ''">
                            {{ __('Cancel') }}
                        </x-ui.button>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Name') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Designation') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Department') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($employee->subordinates as $sub)
                            <tr wire:key="sub-{{ $sub->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink font-medium">
                                    <a href="{{ route('admin.employees.show', $sub) }}" wire:navigate class="text-accent hover:underline">{{ $sub->displayName() }}</a>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $sub->designation ?? '-' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.badge :variant="match($sub->status) {
                                        'active' => 'success',
                                        'terminated' => 'danger',
                                        'probation' => 'warning',
                                        default => 'default',
                                    }">{{ ucfirst($sub->status) }}</x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $sub->department?->type?->name ?? '-' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    <x-ui.button
                                        variant="danger-ghost"
                                        size="sm"
                                        wire:click="removeSubordinate({{ $sub->id }})"
                                        wire:confirm="{{ __('Remove this employee as subordinate?') }}"
                                    >
                                        <x-icon name="heroicon-o-x-mark" class="w-4 h-4" />
                                        {{ __('Remove') }}
                                    </x-ui.button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No subordinates.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">
                    {{ __('Addresses') }}
                    <x-ui.badge>{{ $employee->addresses->count() }}</x-ui.badge>
                </h3>
                <x-ui.button variant="primary" size="sm" wire:click="$set('showAttachModal', true)">
                    <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                    {{ __('Attach Address') }}
                </x-ui.button>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Label') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Address') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Kind') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Primary') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Priority') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Valid From') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Valid To') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($employee->addresses as $address)
                            <tr wire:key="address-{{ $address->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink font-medium">
                                    <a href="{{ route('admin.addresses.show', $address) }}" wire:navigate class="text-accent hover:underline">{{ $address->label ?? '-' }}</a>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ collect([$address->line1, $address->locality, $address->country_iso])->filter()->implode(', ') }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted"
                                    x-data="{ editing: false, selected: @js($address->pivot->kind ?? []) }"
                                >
                                    <div x-show="!editing" @click="editing = true" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                        <div class="flex flex-wrap gap-1">
                                            <template x-for="k in selected" :key="k">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-subtle text-ink border border-border-default" x-text="k.charAt(0).toUpperCase() + k.slice(1)"></span>
                                            </template>
                                            <span x-show="selected.length === 0" class="text-muted">-</span>
                                        </div>
                                        <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity shrink-0" />
                                    </div>
                                    <div x-show="editing" class="space-y-1">
                                        @foreach(['headquarters', 'billing', 'shipping', 'branch', 'other'] as $kindOption)
                                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                                <input type="checkbox" value="{{ $kindOption }}" x-model="selected" class="rounded border-border-input accent-accent focus:ring-accent" />
                                                {{ __(ucfirst($kindOption)) }}
                                            </label>
                                        @endforeach
                                        <div class="flex items-center gap-2 mt-1">
                                            <button @click="$wire.saveAddressKinds({{ $address->id }}, selected); editing = false" class="px-2 py-0.5 text-xs font-medium rounded bg-accent text-accent-on hover:bg-accent-hover transition-colors">{{ __('Save') }}</button>
                                            <button @click="editing = false; selected = @js($address->pivot->kind ?? [])" class="px-2 py-0.5 text-xs font-medium rounded hover:bg-surface-subtle text-muted transition-colors">{{ __('Cancel') }}</button>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    <button
                                        wire:click="updateAddressPivot({{ $address->id }}, 'is_primary', {{ $address->pivot->is_primary ? '0' : '1' }})"
                                        class="cursor-pointer"
                                        title="{{ __('Toggle primary') }}"
                                    >
                                        @if($address->pivot->is_primary)
                                            <x-ui.badge variant="success">{{ __('Yes') }}</x-ui.badge>
                                        @else
                                            <span class="text-muted hover:text-ink transition-colors">{{ __('No') }}</span>
                                        @endif
                                    </button>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums"
                                    x-data="{ editing: false, val: '{{ $address->pivot->priority }}' }"
                                >
                                    <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                        <span x-text="val"></span>
                                        <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                                    </div>
                                    <input
                                        x-show="editing"
                                        x-ref="input"
                                        x-model="val"
                                        @keydown.enter="editing = false; $wire.updateAddressPivot({{ $address->id }}, 'priority', val)"
                                        @keydown.escape="editing = false; val = '{{ $address->pivot->priority }}'"
                                        @blur="editing = false; $wire.updateAddressPivot({{ $address->id }}, 'priority', val)"
                                        type="number"
                                        min="0"
                                        class="w-16 px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                                    />
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $address->pivot->valid_from ?? '-' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $address->pivot->valid_to ?? '-' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    <x-ui.button
                                        variant="danger-ghost"
                                        size="sm"
                                        wire:click="unlinkAddress({{ $address->id }})"
                                        wire:confirm="{{ __('Are you sure you want to unlink this address?') }}"
                                    >
                                        <x-icon name="heroicon-o-link-slash" class="w-4 h-4" />
                                        {{ __('Unlink') }}
                                    </x-ui.button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No addresses linked.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <x-ui.modal wire:model="showAttachModal" class="max-w-lg">
            <div class="p-6 space-y-4">
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Attach Address') }}</h3>

                <div class="space-y-1">
                    <label class="block text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Address') }}</label>
                    <x-ui.select wire:model="attach_address_id">
                        <option value="0">{{ __('Select an address...') }}</option>
                        @foreach($availableAddresses as $addr)
                            <option value="{{ $addr->id }}">{{ $addr->label }} — {{ collect([$addr->line1, $addr->locality, $addr->country_iso])->filter()->implode(', ') }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="space-y-1">
                    <label class="block text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Kind') }}</label>
                    <div class="flex flex-wrap gap-x-4 gap-y-1">
                        @foreach(['headquarters', 'billing', 'shipping', 'branch', 'other'] as $kindOption)
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="checkbox" value="{{ $kindOption }}" wire:model="attach_kind" class="rounded border-border-input accent-accent focus:ring-accent" />
                                {{ __(ucfirst($kindOption)) }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <x-ui.checkbox wire:model="attach_is_primary" label="{{ __('Primary Address') }}" />

                <div>
                    <x-ui.input wire:model="attach_priority" label="{{ __('Priority') }}" type="number" />
                    <p class="text-xs text-muted mt-1">{{ __('Lower number = higher priority. Used to order addresses of the same kind (0 = top).') }}</p>
                </div>

                <div class="flex items-center gap-4 pt-2">
                    <x-ui.button variant="primary" wire:click="attachAddress">{{ __('Attach') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="$set('showAttachModal', false)">{{ __('Cancel') }}</x-ui.button>
                </div>
            </div>
        </x-ui.modal>
    </div>
</div>
