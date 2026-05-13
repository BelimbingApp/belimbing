<?php

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use App\Modules\People\Attendance\Exceptions\AttendanceClockEventIngestionException;
use App\Modules\People\Attendance\Models\AttendanceClockEvent;
use App\Modules\People\Attendance\Models\AttendanceDay;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use App\Modules\People\Attendance\Services\AttendanceDayProjectionService;
use App\Modules\People\Attendance\Services\AttendancePolicyGroupResolver;
use App\Modules\People\Attendance\Services\ClockEventIngestionService;

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
