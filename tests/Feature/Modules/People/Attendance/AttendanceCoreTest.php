<?php

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use App\Modules\People\Attendance\Exceptions\AttendanceClockEventIngestionException;
use App\Modules\People\Attendance\Exceptions\AttendanceLifecycleException;
use App\Modules\People\Attendance\Livewire\MyAttendance;
use App\Modules\People\Attendance\Models\AttendanceClockEvent;
use App\Modules\People\Attendance\Models\AttendanceDay;
use App\Modules\People\Attendance\Models\AttendanceOvertimeRequest;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use App\Modules\People\Attendance\Models\AttendanceRosterPattern;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use App\Modules\People\Attendance\Services\AttendanceCalendarResolver;
use App\Modules\People\Attendance\Services\AttendanceDayProjectionService;
use App\Modules\People\Attendance\Services\AttendanceDayResolverService;
use App\Modules\People\Attendance\Services\AttendanceLifecycleService;
use App\Modules\People\Attendance\Services\AttendanceOvertimeService;
use App\Modules\People\Attendance\Services\AttendancePolicyGroupResolver;
use App\Modules\People\Attendance\Services\ClockEventIngestionService;
use App\Modules\People\Payroll\Models\PayrollCalendar;
use App\Modules\People\Payroll\Models\PayrollInput;
use App\Modules\People\Payroll\Models\PayrollPeriod;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Settings\Models\EmployeeWorkProfile;
use App\Modules\People\Settings\Models\PeopleCalendarException;
use App\Modules\People\Settings\Models\PeopleReferenceEntry;
use Livewire\Livewire;

it('projects attendance day metrics from clock events', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $shift = AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'DAY',
        'name' => 'Day Shift',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
    ]);
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'STD',
        'name' => 'Standard Attendance',
        'effective_from' => '2026-01-01',
    ]);
    $day = AttendanceDay::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_shift_template_id' => $shift->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'attendance_date' => '2026-05-13',
        'shift_starts_at' => '2026-05-13 08:00:00',
        'shift_ends_at' => '2026-05-13 17:00:00',
        'expected_minutes' => 480,
        'payroll_period_date' => '2026-05-13',
    ]);

    AttendanceClockEvent::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_day_id' => $day->id,
        'event_type' => AttendanceClockEvent::TYPE_IN,
        'occurred_at' => '2026-05-13 08:12:00',
        'source' => AttendanceClockEvent::SOURCE_WEB,
    ]);
    AttendanceClockEvent::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_day_id' => $day->id,
        'event_type' => AttendanceClockEvent::TYPE_OUT,
        'occurred_at' => '2026-05-13 17:30:00',
        'source' => AttendanceClockEvent::SOURCE_WEB,
    ]);

    app(AttendanceDayProjectionService::class)->project($day)->save();

    $day->refresh();

    expect($day->status)->toBe(AttendanceDay::STATUS_EXCEPTION_PENDING)
        ->and($day->worked_minutes)->toBe(558)
        ->and($day->payable_minutes)->toBe(480)
        ->and($day->late_minutes)->toBe(12)
        ->and($day->early_out_minutes)->toBe(0)
        ->and($day->absent_minutes)->toBe(0)
        ->and($day->overtime_candidate_minutes)->toBe(78)
        ->and($day->exception_tags)->toBe(['late_in']);
});

it('deducts unpaid breaks from worked time but keeps paid breaks counted', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $shift = AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'PROD_12H',
        'name' => 'Production 12h',
        'starts_at' => '07:00:00',
        'ends_at' => '19:00:00',
        'expected_work_minutes' => 660,
        'break_windows' => [
            ['label' => 'Lunch', 'starts_at' => '12:00', 'ends_at' => '13:00', 'paid' => false],
            ['label' => 'Tea', 'starts_at' => '15:30', 'ends_at' => '15:45', 'paid' => true],
        ],
        'effective_from' => '2026-01-01',
    ]);
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'PROD',
        'name' => 'Production',
        'effective_from' => '2026-01-01',
    ]);
    $day = AttendanceDay::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_shift_template_id' => $shift->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'attendance_date' => '2026-05-13',
        'expected_minutes' => 660,
        'payroll_period_date' => '2026-05-13',
    ]);
    AttendanceClockEvent::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_day_id' => $day->id,
        'event_type' => AttendanceClockEvent::TYPE_IN,
        'occurred_at' => '2026-05-13 07:00:00',
        'source' => AttendanceClockEvent::SOURCE_WEB,
    ]);
    AttendanceClockEvent::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_day_id' => $day->id,
        'event_type' => AttendanceClockEvent::TYPE_OUT,
        'occurred_at' => '2026-05-13 19:00:00',
        'source' => AttendanceClockEvent::SOURCE_WEB,
    ]);

    app(AttendanceDayProjectionService::class)->project($day)->save();
    $day->refresh();

    // 07:00–19:00 = 720 min raw. Unpaid lunch (60 min) deducted; paid tea kept. Worked = 660.
    expect($day->worked_minutes)->toBe(660)
        ->and($day->break_minutes)->toBe(75)
        ->and($day->payable_minutes)->toBe(660)
        ->and($day->overtime_candidate_minutes)->toBe(0);
});

it('applies lateness grace and rounding from the policy group', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $shift = AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'DAY',
        'name' => 'Day',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
    ]);
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'GRACE',
        'name' => 'Grace policy',
        'effective_from' => '2026-01-01',
        'lateness_rules' => [
            'grace' => ['in' => 10, 'out' => 5],
            'daily_rounding' => ['method' => 'ceiling', 'minutes' => 5],
        ],
    ]);
    $day = AttendanceDay::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_shift_template_id' => $shift->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'attendance_date' => '2026-05-13',
        'expected_minutes' => 480,
        'payroll_period_date' => '2026-05-13',
    ]);
    AttendanceClockEvent::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_day_id' => $day->id,
        'event_type' => AttendanceClockEvent::TYPE_IN,
        'occurred_at' => '2026-05-13 08:12:00',
        'source' => AttendanceClockEvent::SOURCE_WEB,
    ]);
    AttendanceClockEvent::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_day_id' => $day->id,
        'event_type' => AttendanceClockEvent::TYPE_OUT,
        'occurred_at' => '2026-05-13 16:57:00',
        'source' => AttendanceClockEvent::SOURCE_WEB,
    ]);

    app(AttendanceDayProjectionService::class)->project($day)->save();
    $day->refresh();

    // Late: 12 min - 10 min grace = 2 min → ceiling 5 = 5.
    // Early out: 3 min - 5 min grace = 0.
    expect($day->late_minutes)->toBe(5)
        ->and($day->early_out_minutes)->toBe(0);
});

it('rounds worked minutes and suppresses overtime candidates below the OT minimum', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $shift = AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'DAY',
        'name' => 'Day',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
    ]);
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'TIGHT',
        'name' => 'Tight policy',
        'effective_from' => '2026-01-01',
        'work_hour_rules' => ['daily_rounding' => ['method' => 'nearest', 'minutes' => 15]],
        'overtime_rules' => ['late_ot' => ['enabled' => true, 'minimum_minutes' => 60]],
    ]);
    $day = AttendanceDay::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_shift_template_id' => $shift->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'attendance_date' => '2026-05-13',
        'expected_minutes' => 480,
        'payroll_period_date' => '2026-05-13',
    ]);
    AttendanceClockEvent::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_day_id' => $day->id,
        'event_type' => AttendanceClockEvent::TYPE_IN,
        'occurred_at' => '2026-05-13 08:00:00',
        'source' => AttendanceClockEvent::SOURCE_WEB,
    ]);
    AttendanceClockEvent::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_day_id' => $day->id,
        'event_type' => AttendanceClockEvent::TYPE_OUT,
        'occurred_at' => '2026-05-13 17:25:00',
        'source' => AttendanceClockEvent::SOURCE_WEB,
    ]);

    app(AttendanceDayProjectionService::class)->project($day)->save();
    $day->refresh();

    // Raw worked = 565 min; nearest 15 = 570. Excess over 480 = 90 → above 60-min threshold → kept as OT candidate.
    expect($day->worked_minutes)->toBe(570)
        ->and($day->overtime_candidate_minutes)->toBe(90);
});

it('zeroes the overtime candidate when the excess falls below the OT minimum threshold', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $shift = AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'DAY',
        'name' => 'Day',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 540,
        'effective_from' => '2026-01-01',
    ]);
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'OT60',
        'name' => '60-min OT threshold',
        'effective_from' => '2026-01-01',
        'overtime_rules' => ['late_ot' => ['enabled' => true, 'minimum_minutes' => 60]],
    ]);
    $day = AttendanceDay::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_shift_template_id' => $shift->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'attendance_date' => '2026-05-13',
        'expected_minutes' => 540,
        'payroll_period_date' => '2026-05-13',
    ]);
    AttendanceClockEvent::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_day_id' => $day->id,
        'event_type' => AttendanceClockEvent::TYPE_IN,
        'occurred_at' => '2026-05-13 08:00:00',
        'source' => AttendanceClockEvent::SOURCE_WEB,
    ]);
    AttendanceClockEvent::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_day_id' => $day->id,
        'event_type' => AttendanceClockEvent::TYPE_OUT,
        'occurred_at' => '2026-05-13 17:30:00',
        'source' => AttendanceClockEvent::SOURCE_WEB,
    ]);

    app(AttendanceDayProjectionService::class)->project($day)->save();
    $day->refresh();

    // 570 worked, 30-min excess over 540 expected; below the 60-min OT threshold → suppressed.
    expect($day->worked_minutes)->toBe(570)
        ->and($day->overtime_candidate_minutes)->toBe(0);
});

it('prefers employee roster policy groups over cohort defaults', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $defaultGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'DEFAULT',
        'name' => 'Default',
        'effective_from' => '2026-01-01',
    ]);
    $employeeGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'EMPLOYEE',
        'name' => 'Employee Specific',
        'effective_from' => '2026-01-01',
    ]);

    AttendanceRosterAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => null,
        'attendance_policy_group_id' => $defaultGroup->id,
        'effective_from' => '2026-01-01',
        'publish_state' => 'published',
    ]);
    AttendanceRosterAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_policy_group_id' => $employeeGroup->id,
        'effective_from' => '2026-01-01',
        'publish_state' => 'published',
    ]);

    $resolved = app(AttendancePolicyGroupResolver::class)->resolveForEmployee($employee, '2026-05-13');

    expect($resolved?->is($employeeGroup))->toBeTrue();
});

it('ingests web clock events through an append-only service and projects partial punches as exceptions', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $actor = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
    ]);

    $event = app(ClockEventIngestionService::class)->recordWebClock(
        employee: $employee,
        eventType: AttendanceClockEvent::TYPE_IN,
        actorUserId: $actor->id,
        ipAddress: '127.0.0.1',
        occurredAt: '2026-05-13 08:05:00',
        timezone: 'Asia/Singapore',
    );

    $day = AttendanceDay::query()->whereKey($event->attendance_day_id)->firstOrFail();

    expect($event->source)->toBe(AttendanceClockEvent::SOURCE_WEB)
        ->and($event->timezone)->toBe('Asia/Singapore')
        ->and($event->actor_user_id)->toBe($actor->id)
        ->and($event->ip_address)->toBe('127.0.0.1')
        ->and($day->status)->toBe(AttendanceDay::STATUS_EXCEPTION_PENDING)
        ->and($day->exception_tags)->toBe(['missing_clock_out']);
});

it('records manual corrections without mutating the original clock event', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $actor = User::factory()->create(['company_id' => $company->id]);
    $service = app(ClockEventIngestionService::class);

    $original = $service->importClockEvent(
        employee: $employee,
        eventType: AttendanceClockEvent::TYPE_IN,
        occurredAt: '2026-05-13 08:30:00',
        sourceSystem: 'legacy-device',
        sourceCode: 'PUNCH-1001',
        attributes: ['timezone' => 'Asia/Singapore'],
    );

    $correction = $service->correctClockEvent(
        correctedEvent: $original,
        eventType: AttendanceClockEvent::TYPE_IN,
        occurredAt: '2026-05-13 08:00:00',
        actorUserId: $actor->id,
    );

    $original->refresh();

    expect($original->occurred_at->format('H:i:s'))->toBe('08:30:00')
        ->and($correction->source)->toBe(AttendanceClockEvent::SOURCE_MANUAL)
        ->and($correction->corrects_clock_event_id)->toBe($original->id)
        ->and(AttendanceClockEvent::query()->count())->toBe(2);
});

it('blocks new clock events on locked attendance days', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $actor = User::factory()->create(['company_id' => $company->id]);

    AttendanceDay::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_date' => '2026-05-13',
        'status' => AttendanceDay::STATUS_LOCKED,
        'locked_at' => now(),
    ]);

    app(ClockEventIngestionService::class)->recordManualClock(
        employee: $employee,
        eventType: AttendanceClockEvent::TYPE_IN,
        occurredAt: '2026-05-13 08:00:00',
        actorUserId: $actor->id,
        attributes: ['timezone' => 'Asia/Singapore'],
    );
})->throws(AttendanceClockEventIngestionException::class);

it('resolves attendance days from rotating roster assignments', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $dayShift = AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'DAY',
        'name' => 'Day Shift',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
    ]);
    $nightShift = AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'NIGHT',
        'name' => 'Night Shift',
        'starts_at' => '20:00:00',
        'ends_at' => '08:00:00',
        'crosses_midnight' => true,
        'expected_work_minutes' => 720,
        'effective_from' => '2026-01-01',
    ]);
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'STD',
        'name' => 'Standard Attendance',
        'effective_from' => '2026-01-01',
    ]);
    $pattern = AttendanceRosterPattern::query()->create([
        'company_id' => $company->id,
        'code' => 'ROTATE',
        'name' => 'Rotation',
        'pattern_type' => AttendanceRosterPattern::TYPE_ROTATING,
        'pattern_definition' => [
            'cycle_days' => 2,
            'days' => [
                ['offset' => 0, 'shift_code' => $dayShift->code],
                ['offset' => 1, 'shift_code' => $nightShift->code],
            ],
        ],
        'status' => AttendanceRosterPattern::STATUS_PUBLISHED,
    ]);
    AttendanceRosterAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_roster_pattern_id' => $pattern->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'effective_from' => '2026-05-13',
        'publish_state' => 'published',
    ]);

    $day = app(AttendanceDayResolverService::class)->resolve($employee, '2026-05-14');

    expect($day->shiftTemplate?->is($nightShift))->toBeTrue()
        ->and($day->status)->toBe(AttendanceDay::STATUS_SCHEDULED)
        ->and($day->expected_minutes)->toBe(720)
        ->and($day->shift_ends_at?->toDateString())->toBe('2026-05-15');
});

it('flags a public holiday from the employee work calendar as a holiday day type', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $workCalendar = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id,
        'type' => PeopleReferenceEntry::TYPE_WORK_CALENDAR,
        'code' => 'MY-STD',
        'name' => 'Malaysia Standard',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
        'metadata' => ['rest_days' => ['sunday'], 'off_days' => ['saturday']],
    ]);
    EmployeeWorkProfile::query()->create([
        'employee_id' => $employee->id,
        'work_calendar_id' => $workCalendar->id,
        'hired_on' => '2026-01-01',
    ]);
    PeopleCalendarException::query()->create([
        'work_calendar_id' => $workCalendar->id,
        'occurs_on' => '2026-05-14',
        'name' => 'Wesak Day',
        'kind' => 'public_holiday',
    ]);

    $dayType = app(AttendanceCalendarResolver::class)->dayType($employee, '2026-05-14');

    expect($dayType)->toBe(AttendanceDay::DAY_TYPE_HOLIDAY);
});

it('derives weekly rest and off day types from the work calendar metadata', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $workCalendar = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id,
        'type' => PeopleReferenceEntry::TYPE_WORK_CALENDAR,
        'code' => 'MY-STD',
        'name' => 'Malaysia Standard',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
        'metadata' => ['rest_days' => ['sunday'], 'off_days' => ['saturday']],
    ]);
    EmployeeWorkProfile::query()->create([
        'employee_id' => $employee->id,
        'work_calendar_id' => $workCalendar->id,
        'hired_on' => '2026-01-01',
    ]);

    $resolver = app(AttendanceCalendarResolver::class);

    // 2026-05-17 is a Sunday → rest.
    // 2026-05-16 is a Saturday → off.
    // 2026-05-15 is a Friday → normal.
    expect($resolver->dayType($employee, '2026-05-17'))->toBe(AttendanceDay::DAY_TYPE_REST)
        ->and($resolver->dayType($employee, '2026-05-16'))->toBe(AttendanceDay::DAY_TYPE_OFF)
        ->and($resolver->dayType($employee, '2026-05-15'))->toBe(AttendanceDay::DAY_TYPE_NORMAL);
});

it('treats employees without a work calendar as normal day type', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);

    $dayType = app(AttendanceCalendarResolver::class)->dayType($employee, '2026-05-17');

    expect($dayType)->toBe(AttendanceDay::DAY_TYPE_NORMAL);
});

it('routes a fixed weekly roster pattern through the day_types map when a holiday falls on a working weekday', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $workCalendar = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id,
        'type' => PeopleReferenceEntry::TYPE_WORK_CALENDAR,
        'code' => 'MY-STD',
        'name' => 'Malaysia Standard',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
        'metadata' => ['rest_days' => ['sunday'], 'off_days' => ['saturday']],
    ]);
    EmployeeWorkProfile::query()->create([
        'employee_id' => $employee->id,
        'work_calendar_id' => $workCalendar->id,
        'hired_on' => '2026-01-01',
    ]);
    PeopleCalendarException::query()->create([
        'work_calendar_id' => $workCalendar->id,
        'occurs_on' => '2026-05-14',
        'name' => 'Wesak Day',
        'kind' => 'public_holiday',
    ]);

    $standardShift = AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'PROD_DAY',
        'name' => 'Production day',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
    ]);
    $holidayShift = AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'PROD_HOLIDAY_HALF',
        'name' => 'Production half-day on holidays',
        'starts_at' => '08:00:00',
        'ends_at' => '12:00:00',
        'expected_work_minutes' => 240,
        'effective_from' => '2026-01-01',
    ]);
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'STD',
        'name' => 'Standard',
        'effective_from' => '2026-01-01',
    ]);
    $pattern = AttendanceRosterPattern::query()->create([
        'company_id' => $company->id,
        'code' => 'WEEKLY',
        'name' => 'Weekly',
        'pattern_type' => AttendanceRosterPattern::TYPE_FIXED_WEEKLY,
        'pattern_definition' => [
            'weekdays' => [
                'thursday' => ['shift_code' => 'PROD_DAY'],
            ],
            'day_types' => [
                AttendanceDay::DAY_TYPE_HOLIDAY => ['shift_code' => 'PROD_HOLIDAY_HALF'],
                AttendanceDay::DAY_TYPE_REST => ['shift_code' => null],
            ],
        ],
        'status' => AttendanceRosterPattern::STATUS_PUBLISHED,
    ]);
    AttendanceRosterAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_roster_pattern_id' => $pattern->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'effective_from' => '2026-05-01',
        'publish_state' => 'published',
    ]);

    $resolver = app(AttendanceDayResolverService::class);
    $holidayDay = $resolver->resolve($employee, '2026-05-14');   // Thursday + holiday → PROD_HOLIDAY_HALF
    $normalDay = $resolver->resolve($employee, '2026-05-21');    // Thursday, no holiday → PROD_DAY
    $restDay = $resolver->resolve($employee, '2026-05-17');      // Sunday → rest, no shift

    expect($holidayDay->day_type)->toBe(AttendanceDay::DAY_TYPE_HOLIDAY)
        ->and($holidayDay->shiftTemplate?->code)->toBe('PROD_HOLIDAY_HALF')
        ->and($normalDay->day_type)->toBe(AttendanceDay::DAY_TYPE_NORMAL)
        ->and($normalDay->shiftTemplate?->code)->toBe('PROD_DAY')
        ->and($restDay->day_type)->toBe(AttendanceDay::DAY_TYPE_REST)
        ->and($restDay->shiftTemplate)->toBeNull();
});

it('finalizes ready attendance days and blocks locked lifecycle changes', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $day = AttendanceDay::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_date' => '2026-05-13',
        'status' => AttendanceDay::STATUS_READY_FOR_REVIEW,
    ]);

    app(AttendanceLifecycleService::class)->finalize($day);
    app(AttendanceLifecycleService::class)->lock($day);

    expect($day->refresh()->locked_at)->not->toBeNull();

    app(AttendanceLifecycleService::class)->finalize($day);
})->throws(AttendanceLifecycleException::class);

it('approves overtime and queues one neutral payroll input', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'STD',
        'name' => 'Standard Attendance',
        'effective_from' => '2026-01-01',
        'payroll_defaults' => ['overtime_pay_item_code' => 'OT15'],
    ]);
    $day = AttendanceDay::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'attendance_date' => '2026-05-13',
        'status' => AttendanceDay::STATUS_FINALIZED,
    ]);
    $request = AttendanceOvertimeRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_day_id' => $day->id,
        'status' => AttendanceOvertimeRequest::STATUS_SUBMITTED,
        'starts_at' => '2026-05-13 17:00:00',
        'ends_at' => '2026-05-13 19:00:00',
        'requested_minutes' => 120,
        'reason' => 'Production support',
    ]);
    $calendar = PayrollCalendar::query()->create([
        'company_id' => $company->id,
        'code' => 'MONTHLY',
        'name' => 'Monthly',
        'country_iso' => 'MY',
        'currency' => 'MYR',
        'frequency' => 'monthly',
    ]);
    $period = PayrollPeriod::query()->create([
        'payroll_calendar_id' => $calendar->id,
        'code' => '2026-05',
        'name' => 'May 2026',
        'starts_on' => '2026-05-01',
        'ends_on' => '2026-05-31',
        'pay_date' => '2026-05-31',
    ]);
    PayrollRun::query()->create([
        'company_id' => $company->id,
        'payroll_calendar_id' => $calendar->id,
        'payroll_period_id' => $period->id,
        'code' => 'MAY-2026',
        'name' => 'May 2026',
        'status' => PayrollRun::STATUS_DRAFT,
        'currency' => 'MYR',
    ]);

    $service = app(AttendanceOvertimeService::class);
    $service->approve($request, 90);
    $outcome = $service->queuePayrollHandoff($request);
    $again = $service->queuePayrollHandoff($request->refresh());

    expect($outcome)->not->toBeNull()
        ->and($outcome->isMaterialized())->toBeTrue()
        ->and($again?->payrollPendingContributionId)->toBe($outcome->payrollPendingContributionId)
        ->and(PayrollInput::query()->count())->toBe(1)
        ->and(PayrollInput::query()->first()?->pay_item_code)->toBe('OT15')
        ->and(PayrollInput::query()->first()?->quantity)->toBe('1.5000')
        ->and(PayrollInput::query()->first()?->source_type)->toBe(AttendanceOvertimeService::SOURCE_TYPE)
        ->and($request->refresh()->status)->toBe(AttendanceOvertimeRequest::STATUS_QUEUED_FOR_PAYROLL);
});

it('lets linked employees submit overtime from the attendance workbench', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $user->forceFill(['employee_id' => $employee->id])->save();

    $this->actingAs($user);

    Livewire::test(MyAttendance::class)
        ->assertSee('Request OT')
        ->call('openOvertimeModal')
        ->set('overtimeDate', '2026-05-13')
        ->set('overtimeStartsAt', '17:00')
        ->set('overtimeEndsAt', '19:00')
        ->set('overtimeRequestedHours', '2.00')
        ->set('overtimeReason', 'Month-end production support')
        ->call('submitOvertimeRequest')
        ->assertHasNoErrors();

    expect(AttendanceOvertimeRequest::query()
        ->where('employee_id', $employee->id)
        ->where('status', AttendanceOvertimeRequest::STATUS_SUBMITTED)
        ->exists())->toBeTrue();
});
