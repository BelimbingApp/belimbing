<?php

namespace App\Modules\People\Attendance\Livewire;

use App\Modules\Core\Company\Models\Department;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Attendance\Livewire\Concerns\InteractsWithAttendanceScreen;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use App\Modules\People\Attendance\Models\AttendanceRosterPattern;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use App\Modules\People\Settings\Models\PeopleReferenceEntry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class Rosters extends Component
{
    use InteractsWithAttendanceScreen;
    use WithPagination;

    public string $rosterSearch = '';

    public string $rosterDepartmentId = '';

    public string $rosterSupervisorId = '';

    public string $rosterOrganizationUnitId = '';

    public string $rosterCostCenterId = '';

    public string $rosterWorkforceClassId = '';

    public string $rosterEmploymentGroupId = '';

    public string $rosterWorkCalendarId = '';

    public string $rosterPayRateType = '';

    public string $rosterEmployeeStatus = 'active';

    public bool $rosterSelectAllFiltered = false;

    /**
     * @var list<string>
     */
    public array $selectedRosterEmployeeIds = [];

    public string $rosterEmployeeId = '';

    public string $rosterPatternId = '';

    public string $rosterShiftTemplateId = '';

    public string $rosterPolicyGroupId = '';

    public string $rosterEffectiveFrom = '';

    public string $rosterEffectiveTo = '';

    public string $rosterPublishState = 'draft';

    public function mount(): void
    {
        $this->rosterEffectiveFrom = now()->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, $this->rosterFilterProperties(), true)) {
            $this->resetPage();
            $this->rosterSelectAllFiltered = false;
        }
    }

    public function selectVisibleRosterEmployees(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $ids = $this->filteredEmployeesQuery()
            ->orderBy('employees.full_name')
            ->orderBy('employees.id')
            ->limit(25)
            ->pluck('employees.id')
            ->map(fn (int $id): string => (string) $id)
            ->all();

        $this->selectedRosterEmployeeIds = array_values(array_unique([
            ...$this->selectedRosterEmployeeIds,
            ...$ids,
        ]));
    }

    public function selectAllFilteredRosterEmployees(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->rosterSelectAllFiltered = true;
        $this->selectedRosterEmployeeIds = [];
    }

    public function clearRosterSelection(): void
    {
        $this->rosterSelectAllFiltered = false;
        $this->selectedRosterEmployeeIds = [];
        $this->rosterEmployeeId = '';
    }

    public function clearRosterFilters(): void
    {
        $this->reset($this->rosterFilterProperties());
        $this->rosterEmployeeStatus = 'active';
        $this->clearRosterSelection();
        $this->resetPage();
    }

    public function saveRosterAssignment(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $companyId = $this->companyId();
        $validated = $this->validate([
            'rosterEmployeeId' => ['nullable', 'integer', Rule::exists(Employee::class, 'id')->where('company_id', $companyId)],
            'selectedRosterEmployeeIds' => ['array'],
            'selectedRosterEmployeeIds.*' => ['integer', Rule::exists(Employee::class, 'id')->where('company_id', $companyId)],
            'rosterPatternId' => ['nullable', 'integer', Rule::exists(AttendanceRosterPattern::class, 'id')->where('company_id', $companyId)],
            'rosterShiftTemplateId' => ['required', 'integer', Rule::exists(AttendanceShiftTemplate::class, 'id')->where('company_id', $companyId)],
            'rosterPolicyGroupId' => ['required', 'integer', Rule::exists(AttendancePolicyGroup::class, 'id')->where('company_id', $companyId)],
            'rosterEffectiveFrom' => ['required', 'date'],
            'rosterEffectiveTo' => ['nullable', 'date', 'after_or_equal:rosterEffectiveFrom'],
            'rosterPublishState' => ['required', Rule::in(['draft', 'published'])],
        ]);

        $employeeIds = $this->selectedRosterEmployeeIds();

        if ($employeeIds === []) {
            $this->addError('selectedRosterEmployeeIds', __('Select at least one employee to roster.'));

            return;
        }

        $effectiveTo = $this->blankToNull($validated['rosterEffectiveTo'] ?? null);
        $created = 0;
        $skipped = 0;

        foreach ($employeeIds as $employeeId) {
            if ($this->hasRosterOverlap($employeeId, $validated['rosterEffectiveFrom'], $effectiveTo)) {
                $skipped++;

                continue;
            }

            AttendanceRosterAssignment::query()->create([
                'company_id' => $companyId,
                'employee_id' => $employeeId,
                'attendance_roster_pattern_id' => $this->blankToNull($validated['rosterPatternId'] ?? null),
                'attendance_shift_template_id' => (int) $validated['rosterShiftTemplateId'],
                'attendance_policy_group_id' => (int) $validated['rosterPolicyGroupId'],
                'effective_from' => $validated['rosterEffectiveFrom'],
                'effective_to' => $effectiveTo,
                'publish_state' => $validated['rosterPublishState'],
                'lock_state' => 'open',
                'revision' => 1,
                'exceptions' => [],
                'metadata' => [
                    'created_from' => 'attendance_roster_builder',
                    'selection_mode' => $this->rosterSelectAllFiltered ? 'all_filtered' : 'selected_employees',
                    'filters' => $this->rosterFilters(),
                ],
            ]);

            $created++;
        }

        if ($created === 0) {
            $this->addError('rosterEffectiveFrom', __('Every selected employee already has a roster assignment in that date range.'));

            return;
        }

        $this->resetForm();
        session()->flash('success', trans_choice(
            'Roster assignment saved. :skipped skipped because of existing roster overlaps.|:count roster assignments saved. :skipped skipped because of existing roster overlaps.',
            $created,
            ['count' => $created, 'skipped' => $skipped],
        ));
    }

    public function deleteRosterAssignment(int $assignmentId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        AttendanceRosterAssignment::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($assignmentId)
            ->delete();

        session()->flash('success', __('Roster assignment deleted.'));
    }

    public function render(): View
    {
        $companyId = $this->companyId();
        $schemaReady = $this->schemaReady();

        return view('livewire.people.attendance.rosters', [
            'schemaReady' => $schemaReady,
            'canManage' => $this->canAttendance('people.attendance.manage'),
            'employees' => $schemaReady
                ? $this->filteredEmployeesQuery()
                    ->orderBy('full_name')
                    ->orderBy('id')
                    ->paginate(25)
                : collect(),
            'filteredEmployeeCount' => $schemaReady ? $this->filteredEmployeesQuery()->count() : 0,
            'selectedEmployeeCount' => $schemaReady ? count($this->selectedRosterEmployeeIds()) : 0,
            'departments' => $schemaReady
                ? Department::query()
                    ->where('company_id', $companyId)
                    ->with('type')
                    ->orderBy('name')
                    ->get()
                : collect(),
            'supervisors' => $schemaReady
                ? Employee::query()
                    ->where('company_id', $companyId)
                    ->whereNotNull('id')
                    ->orderBy('full_name')
                    ->get(['id', 'full_name', 'employee_number'])
                : collect(),
            'organizationUnits' => $this->referenceOptions(PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT, $schemaReady),
            'costCenters' => $this->referenceOptions(PeopleReferenceEntry::TYPE_COST_CENTER, $schemaReady),
            'employmentGroups' => $this->referenceOptions(PeopleReferenceEntry::TYPE_EMPLOYMENT_GROUP, $schemaReady),
            'workforceClasses' => $this->referenceOptions(PeopleReferenceEntry::TYPE_WORKFORCE_CLASS, $schemaReady),
            'workCalendars' => $this->referenceOptions(PeopleReferenceEntry::TYPE_WORK_CALENDAR, $schemaReady),
            'shiftTemplates' => $schemaReady
                ? AttendanceShiftTemplate::query()
                    ->where('company_id', $companyId)
                    ->orderBy('code')
                    ->get()
                : collect(),
            'policyGroups' => $schemaReady
                ? AttendancePolicyGroup::query()
                    ->where('company_id', $companyId)
                    ->orderBy('code')
                    ->get()
                : collect(),
            'rosterPatterns' => $schemaReady
                ? AttendanceRosterPattern::query()
                    ->where('company_id', $companyId)
                    ->orderBy('code')
                    ->get()
                : collect(),
            'rosterAssignments' => $schemaReady
                ? AttendanceRosterAssignment::query()
                    ->where('company_id', $companyId)
                    ->with(['employee', 'shiftTemplate', 'policyGroup', 'rosterPattern'])
                    ->latest('effective_from')
                    ->limit(40)
                    ->get()
                : collect(),
        ]);
    }

    private function hasRosterOverlap(int $employeeId, string $effectiveFrom, ?string $effectiveTo): bool
    {
        $rangeEnd = $effectiveTo ?? '9999-12-31';

        return AttendanceRosterAssignment::query()
            ->where('company_id', $this->companyId())
            ->where('employee_id', $employeeId)
            ->where('effective_from', '<=', $rangeEnd)
            ->where(function ($query) use ($effectiveFrom): void {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $effectiveFrom);
            })
            ->exists();
    }

    private function resetForm(): void
    {
        $this->rosterEmployeeId = '';
        $this->selectedRosterEmployeeIds = [];
        $this->rosterSelectAllFiltered = false;
        $this->rosterPatternId = '';
        $this->rosterShiftTemplateId = '';
        $this->rosterPolicyGroupId = '';
        $this->rosterEffectiveFrom = now()->toDateString();
        $this->rosterEffectiveTo = '';
        $this->rosterPublishState = 'draft';
    }

    /**
     * @return Builder<Employee>
     */
    private function filteredEmployeesQuery(): Builder
    {
        $query = Employee::query()
            ->select('employees.*')
            ->leftJoin('employee_work_profiles', 'employee_work_profiles.employee_id', '=', 'employees.id')
            ->where('employees.company_id', $this->companyId());

        $search = trim($this->rosterSearch);
        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $like = '%'.$search.'%';

                $searchQuery->where('employees.full_name', 'like', $like)
                    ->orWhere('employees.short_name', 'like', $like)
                    ->orWhere('employees.employee_number', 'like', $like)
                    ->orWhere('employees.designation', 'like', $like);
            });
        }

        $this->applyIntegerFilter($query, 'employees.department_id', $this->rosterDepartmentId);
        $this->applyIntegerFilter($query, 'employees.supervisor_id', $this->rosterSupervisorId);
        $this->applyIntegerFilter($query, 'employee_work_profiles.organization_unit_id', $this->rosterOrganizationUnitId);
        $this->applyIntegerFilter($query, 'employee_work_profiles.cost_center_id', $this->rosterCostCenterId);
        $this->applyIntegerFilter($query, 'employee_work_profiles.workforce_class_id', $this->rosterWorkforceClassId);
        $this->applyIntegerFilter($query, 'employee_work_profiles.employment_group_id', $this->rosterEmploymentGroupId);
        $this->applyIntegerFilter($query, 'employee_work_profiles.work_calendar_id', $this->rosterWorkCalendarId);

        if ($this->rosterPayRateType !== '') {
            $query->where('employee_work_profiles.pay_rate_type', $this->rosterPayRateType);
        }

        if ($this->rosterEmployeeStatus !== '') {
            $query->where('employees.status', $this->rosterEmployeeStatus);
        }

        return $query->with(['department.type', 'workProfile.organizationUnit', 'workProfile.costCenter', 'workProfile.workforceClass']);
    }

    /**
     * @return list<int>
     */
    private function selectedRosterEmployeeIds(): array
    {
        if ($this->rosterSelectAllFiltered) {
            return $this->filteredEmployeesQuery()
                ->orderBy('employees.id')
                ->limit(500)
                ->pluck('employees.id')
                ->map(fn (int $id): int => $id)
                ->all();
        }

        $ids = array_filter($this->selectedRosterEmployeeIds, fn (mixed $id): bool => filter_var($id, FILTER_VALIDATE_INT) !== false);

        if ($ids === [] && filter_var($this->rosterEmployeeId, FILTER_VALIDATE_INT) !== false) {
            $ids = [$this->rosterEmployeeId];
        }

        $companyId = $this->companyId();

        return Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', array_map('intval', $ids))
            ->pluck('id')
            ->map(fn (int $id): int => $id)
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function rosterFilters(): array
    {
        return [
            'search' => $this->rosterSearch,
            'department_id' => $this->rosterDepartmentId,
            'supervisor_id' => $this->rosterSupervisorId,
            'organization_unit_id' => $this->rosterOrganizationUnitId,
            'cost_center_id' => $this->rosterCostCenterId,
            'workforce_class_id' => $this->rosterWorkforceClassId,
            'employment_group_id' => $this->rosterEmploymentGroupId,
            'work_calendar_id' => $this->rosterWorkCalendarId,
            'pay_rate_type' => $this->rosterPayRateType,
            'status' => $this->rosterEmployeeStatus,
        ];
    }

    /**
     * @return list<string>
     */
    private function rosterFilterProperties(): array
    {
        return [
            'rosterSearch',
            'rosterDepartmentId',
            'rosterSupervisorId',
            'rosterOrganizationUnitId',
            'rosterCostCenterId',
            'rosterWorkforceClassId',
            'rosterEmploymentGroupId',
            'rosterWorkCalendarId',
            'rosterPayRateType',
            'rosterEmployeeStatus',
        ];
    }

    private function applyIntegerFilter(Builder $query, string $column, mixed $value): void
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            return;
        }

        $query->where($column, (int) $value);
    }

    private function referenceOptions(string $type, bool $schemaReady)
    {
        if (! $schemaReady) {
            return collect();
        }

        return PeopleReferenceEntry::query()
            ->where('company_id', $this->companyId())
            ->where('type', $type)
            ->orderBy('name')
            ->get();
    }
}
