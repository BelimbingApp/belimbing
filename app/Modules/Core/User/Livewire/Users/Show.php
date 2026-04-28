<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\User\Livewire\Users;

use App\Base\Authz\Capability\CapabilityRegistry;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Livewire\Concerns\ChecksCapabilityAuthorization;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Authz\Services\EffectivePermissions;
use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Modules\Core\Company\Livewire\Concerns\SortsExternalAccesses;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Livewire\Concerns\ValidatesPasswordConfirmation;
use App\Modules\Core\User\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Show extends Component
{
    use ChecksCapabilityAuthorization;
    use SavesValidatedFields;
    use SortsExternalAccesses;
    use TogglesSort;
    use ValidatesPasswordConfirmation;

    public User $user;

    public string $employeesSortBy = 'employee_number';

    public string $employeesSortDir = 'asc';

    public string $externalAccessesSortBy = 'company';

    public string $externalAccessesSortDir = 'asc';

    private const EMPLOYEE_SORTABLE = [
        'employee_number' => true,
        'company' => true,
        'department' => true,
        'designation' => true,
        'status' => true,
        'employment_start' => true,
    ];

    private const EXTERNAL_ACCESS_SORTABLE = [
        'company' => true,
        'permissions' => true,
        'access_status' => true,
        'granted_at' => true,
        'expires_at' => true,
    ];

    public string $password = '';

    public string $passwordConfirmation = '';

    public array $selectedRoleIds = [];

    public array $selectedCapabilityKeys = [];

    public ?int $linkEmployeeId = null;

    public bool $showAddEmployeeModal = false;

    public ?int $newEmpCompanyId = null;

    public string $newEmpEmployeeNumber = '';

    public string $newEmpFullName = '';

    public ?string $newEmpDesignation = null;

    public ?string $newEmpEmploymentStart = null;

    public function mount(User $user): void
    {
        $this->user = $user->load([
            'company',
            'externalAccesses.company',
            'employee.company',
            'employee.department.type',
        ]);
        $this->newEmpCompanyId = $user->company_id;
    }

    public function sortEmployees(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::EMPLOYEE_SORTABLE,
            defaultDir: [
                'employee_number' => 'asc',
                'company' => 'asc',
                'department' => 'asc',
                'designation' => 'asc',
                'status' => 'asc',
                'employment_start' => 'asc',
            ],
            sortByProperty: 'employeesSortBy',
            sortDirProperty: 'employeesSortDir',
            resetPage: false,
        );
    }

    public function sortUserExternalAccesses(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::EXTERNAL_ACCESS_SORTABLE,
            defaultDir: [
                'company' => 'asc',
                'permissions' => 'asc',
                'access_status' => 'asc',
                'granted_at' => 'asc',
                'expires_at' => 'asc',
            ],
            sortByProperty: 'externalAccessesSortBy',
            sortDirProperty: 'externalAccessesSortDir',
            resetPage: false,
        );
    }

    /**
     * Save a field value via inline editing.
     */
    public function saveField(string $field, mixed $value): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user->id),
            ],
        ];

        $this->saveValidatedField(
            $this->user,
            $field,
            $value,
            $rules,
            function (User $user, string $validatedField): void {
                if ($validatedField === 'email' && $user->isDirty('email')) {
                    $user->email_verified_at = null;
                }
            }
        );
    }

    /**
     * Save the company assignment via inline select.
     */
    public function saveCompany(?int $companyId): void
    {
        $this->user->company_id = $companyId ?: null;
        $this->user->save();
        $this->user->load('company');
    }

    /**
     * Update the user's password.
     */
    public function updatePassword(): void
    {
        $validated = $this->validate($this->passwordValidationRules());

        $this->user->password = Hash::make($validated['password']);
        $this->user->save();

        $this->reset(['password', 'passwordConfirmation']);

        Session::flash('success', __('Password updated successfully.'));
    }

    /**
     * Assign selected roles to this user.
     */
    public function assignRoles(): void
    {
        if (! $this->checkCapability('core.user.update')) {
            return;
        }

        if (empty($this->selectedRoleIds) || $this->user->company_id === null) {
            return;
        }

        foreach ($this->selectedRoleIds as $roleId) {
            PrincipalRole::query()->firstOrCreate([
                'company_id' => $this->user->company_id,
                'principal_type' => PrincipalType::USER->value,
                'principal_id' => $this->user->id,
                'role_id' => (int) $roleId,
            ]);
        }

        $this->selectedRoleIds = [];
    }

    /**
     * Remove a role assignment from this user.
     */
    public function removeRole(int $principalRoleId): void
    {
        if (! $this->checkCapability('core.user.update')) {
            return;
        }

        PrincipalRole::query()
            ->where('id', $principalRoleId)
            ->where('principal_id', $this->user->id)
            ->where('principal_type', PrincipalType::USER->value)
            ->delete();
    }

    /**
     * Add direct capabilities to this user.
     */
    public function addCapabilities(): void
    {
        if (! $this->checkCapability('core.user.update')) {
            return;
        }

        if (empty($this->selectedCapabilityKeys) || $this->user->company_id === null) {
            return;
        }

        foreach ($this->selectedCapabilityKeys as $capKey) {
            PrincipalCapability::query()->firstOrCreate(
                [
                    'company_id' => $this->user->company_id,
                    'principal_type' => PrincipalType::USER->value,
                    'principal_id' => $this->user->id,
                    'capability_key' => $capKey,
                ],
                [
                    'is_allowed' => true,
                ]
            );
        }

        $this->selectedCapabilityKeys = [];
    }

    /**
     * Remove a direct capability (grant or deny) from this user.
     */
    public function removeCapability(int $capabilityId): void
    {
        if (! $this->checkCapability('core.user.update')) {
            return;
        }

        PrincipalCapability::query()
            ->where('id', $capabilityId)
            ->where('principal_id', $this->user->id)
            ->where('principal_type', PrincipalType::USER->value)
            ->delete();
    }

    /**
     * Deny a role-granted capability for this user.
     */
    public function denyCapability(string $capabilityKey): void
    {
        if (! $this->checkCapability('core.user.update')) {
            return;
        }

        if ($this->user->company_id === null) {
            return;
        }

        PrincipalCapability::query()->firstOrCreate(
            [
                'company_id' => $this->user->company_id,
                'principal_type' => PrincipalType::USER->value,
                'principal_id' => $this->user->id,
                'capability_key' => $capabilityKey,
            ],
            [
                'is_allowed' => false,
            ]
        );
    }

    /**
     * Link an employee record to this user.
     */
    public function linkEmployee(int $employeeId): void
    {
        if (! $this->checkCapability('core.user.update')) {
            return;
        }

        $employee = Employee::query()->find($employeeId);
        if (! $employee) {
            return;
        }

        $alreadyLinked = User::query()->where('employee_id', $employeeId)->exists();
        if ($alreadyLinked) {
            return;
        }

        $this->user->update(['employee_id' => $employeeId]);
        $this->user->load('employee.company', 'employee.department.type');
        $this->linkEmployeeId = null;
    }

    /**
     * Unlink an employee record from this user.
     */
    public function unlinkEmployee(int $employeeId): void
    {
        if (! $this->checkCapability('core.user.update')) {
            return;
        }

        if ($this->user->employee_id !== $employeeId) {
            return;
        }

        $this->user->update(['employee_id' => null]);
        $this->user->load('employee.company', 'employee.department.type');
    }

    /**
     * Create a new employee record linked to this user.
     */
    public function addEmployee(): void
    {
        if (! $this->checkCapability('core.user.update')) {
            return;
        }

        $validated = $this->validate([
            'newEmpCompanyId' => ['required', 'integer', 'exists:companies,id'],
            'newEmpEmployeeNumber' => ['required', 'string', 'max:255'],
            'newEmpFullName' => ['required', 'string', 'max:255'],
            'newEmpDesignation' => ['nullable', 'string', 'max:255'],
            'newEmpEmploymentStart' => ['nullable', 'date'],
        ]);

        $employee = Employee::query()->create([
            'company_id' => $validated['newEmpCompanyId'],
            'employee_number' => $validated['newEmpEmployeeNumber'],
            'full_name' => $validated['newEmpFullName'],
            'designation' => $validated['newEmpDesignation'],
            'employment_start' => $validated['newEmpEmploymentStart'],
            'status' => 'active',
        ]);

        $this->user->update(['employee_id' => $employee->id]);
        $this->user->load('employee.company', 'employee.department.type');
        $this->showAddEmployeeModal = false;
        $this->reset([
            'newEmpCompanyId', 'newEmpEmployeeNumber', 'newEmpFullName',
            'newEmpDesignation', 'newEmpEmploymentStart',
        ]);
        Session::flash('success', __('Employee record created.'));
    }

    public function render(): View
    {
        $authUser = auth()->user();

        $authActor = Actor::forUser($authUser);

        $canManageRoles = app(AuthorizationService::class)
            ->can($authActor, 'core.user.update')
            ->allowed;

        $assignedRoles = PrincipalRole::query()
            ->with('role')
            ->where('principal_type', PrincipalType::USER->value)
            ->where('principal_id', $this->user->id)
            ->get();

        $assignedRoleIds = $assignedRoles->pluck('role_id')->all();
        $hasGrantAll = $assignedRoles->contains(fn ($pr) => $pr->role->grant_all);

        $availableRoles = Role::query()
            ->with('company')
            ->whereNotIn('id', $assignedRoleIds)
            ->orderBy('name')
            ->get();

        // Direct capabilities — keyed by capability_key → id
        $directEntries = PrincipalCapability::query()
            ->where('principal_type', PrincipalType::USER->value)
            ->where('principal_id', $this->user->id)
            ->get(['id', 'capability_key', 'is_allowed']);

        $directGrantIds = [];
        $directDenyIds = [];

        foreach ($directEntries as $entry) {
            if ($entry->is_allowed) {
                $directGrantIds[$entry->capability_key] = $entry->id;
            } else {
                $directDenyIds[$entry->capability_key] = $entry->id;
            }
        }

        $effectivePermissions = [];
        $effectiveKeys = [];

        if ($this->user->company_id !== null) {
            $actor = Actor::forUser($this->user);

            $permissions = EffectivePermissions::forActor($actor);
            $effectiveKeys = $permissions->allowed();
            sort($effectiveKeys);

            foreach ($effectiveKeys as $capability) {
                $domain = explode('.', $capability, 2)[0];
                $effectivePermissions[$domain][] = $capability;
            }
        }

        // Denied capabilities grouped by domain (for red badges)
        $deniedKeys = array_keys($directDenyIds);
        sort($deniedKeys);

        $deniedPermissions = [];
        foreach ($deniedKeys as $cap) {
            $domain = explode('.', $cap, 2)[0];
            $deniedPermissions[$domain][] = $cap;
        }

        // Available = all capabilities minus effective and denied
        $excludedKeys = array_merge($effectiveKeys, $deniedKeys);
        $allCapabilities = app(CapabilityRegistry::class)->all();
        sort($allCapabilities);

        $availableCapabilities = [];
        foreach ($allCapabilities as $cap) {
            if (in_array($cap, $excludedKeys, true)) {
                continue;
            }
            $domain = explode('.', $cap, 2)[0];
            $availableCapabilities[$domain][] = $cap;
        }

        $this->user->loadMissing(['externalAccesses.company', 'employee.company', 'employee.department.type']);

        $employees = collect($this->user->employee ? [$this->user->employee] : []);
        $sortedEmployees = $this->sortEmployeesCollection($employees);
        $sortedExternalAccesses = $this->sortExternalAccessesByColumn(
            collect($this->user->externalAccesses),
            $this->externalAccessesSortBy,
            $this->externalAccessesSortDir,
            'company',
        );

        return view('livewire.admin.users.show', [
            'sortedEmployees' => $sortedEmployees,
            'sortedExternalAccesses' => $sortedExternalAccesses,
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'assignedRoles' => $assignedRoles,
            'availableRoles' => $availableRoles,
            'canManageRoles' => $canManageRoles,
            'hasGrantAll' => $hasGrantAll,
            'directGrantIds' => $directGrantIds,
            'directDenyIds' => $directDenyIds,
            'deniedPermissions' => $deniedPermissions,
            'availableCapabilities' => $availableCapabilities,
            'effectivePermissions' => $effectivePermissions,
            'unlinkableEmployees' => Employee::query()
                ->whereNotIn('id', User::query()->whereNotNull('employee_id')->pluck('employee_id'))
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'employee_number', 'company_id']),
        ]);
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, Employee>
     */
    private function sortEmployeesCollection(Collection $employees): Collection
    {
        $dir = $this->employeesSortDir === 'desc' ? -1 : 1;

        return $employees
            ->sort(function (Employee $a, Employee $b) use ($dir): int {
                $deptLabel = function (Employee $e): string {
                    return (string) ($e->department?->type?->name ?? '');
                };

                $start = function (Employee $e): string {
                    $v = $e->employment_start;

                    if ($v instanceof \DateTimeInterface) {
                        return $v->format('Y-m-d');
                    }

                    return (string) ($v ?? '');
                };

                $primary = match ($this->employeesSortBy) {
                    'employee_number' => $dir * strcmp((string) ($a->employee_number ?? ''), (string) ($b->employee_number ?? '')),
                    'company' => $dir * strcmp(
                        (string) ($a->company?->name ?? ''),
                        (string) ($b->company?->name ?? ''),
                    ),
                    'department' => $dir * strcmp($deptLabel($a), $deptLabel($b)),
                    'designation' => $dir * strcmp((string) ($a->designation ?? ''), (string) ($b->designation ?? '')),
                    'status' => $dir * strcmp((string) $a->status, (string) $b->status),
                    'employment_start' => $dir * strcmp($start($a), $start($b)),
                    default => $dir * strcmp((string) ($a->employee_number ?? ''), (string) ($b->employee_number ?? '')),
                };

                if ($primary !== 0) {
                    return $primary;
                }

                return $a->id <=> $b->id;
            })
            ->values();
    }
}
