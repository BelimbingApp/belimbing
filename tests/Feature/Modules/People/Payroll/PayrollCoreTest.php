<?php

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Payroll\Exceptions\ClosedPayrollRunException;
use App\Modules\People\Payroll\Models\PayrollCalendar;
use App\Modules\People\Payroll\Models\PayrollInput;
use App\Modules\People\Payroll\Models\PayrollPeriod;
use App\Modules\People\Payroll\Models\PayrollResultLine;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Models\PayrollRunParticipant;
use App\Modules\People\Payroll\Services\PayrollPayslipBuilder;
use App\Modules\People\Payroll\Services\PayrollRunCalculator;

function createPayrollCoreRun(string $runCode = 'MY-2026-01-MAIN'): array
{
    $company = Company::query()->findOrFail(Company::LICENSEE_ID);
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $calendar = PayrollCalendar::query()->create([
        'company_id' => $company->id,
        'code' => 'CAL-'.$runCode,
        'name' => 'Malaysia Monthly Payroll',
        'country_iso' => 'MY',
        'currency' => 'MYR',
        'frequency' => 'monthly',
        'status' => 'active',
    ]);
    $period = PayrollPeriod::query()->create([
        'payroll_calendar_id' => $calendar->id,
        'code' => '2026-01',
        'name' => 'January 2026',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-01-31',
        'pay_date' => '2026-01-31',
        'status' => 'open',
    ]);
    $run = PayrollRun::query()->create([
        'company_id' => $company->id,
        'payroll_calendar_id' => $calendar->id,
        'payroll_period_id' => $period->id,
        'code' => $runCode,
        'name' => 'January 2026 Main Payroll',
        'status' => PayrollRun::STATUS_DRAFT,
        'currency' => 'MYR',
    ]);
    $participant = PayrollRunParticipant::query()->create([
        'payroll_run_id' => $run->id,
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'status' => 'included',
        'currency' => 'MYR',
    ]);

    return [$run, $participant, $employee];
}

test('neutral payroll core calculates gross deductions reimbursements and net pay', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun();

    PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'pay_item_code' => 'basic_salary',
        'label' => 'Basic Salary',
        'input_type' => PayrollInput::TYPE_EARNING,
        'amount' => '3000.1000',
        'currency' => 'MYR',
    ]);
    PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'pay_item_code' => 'bonus',
        'label' => 'Bonus',
        'input_type' => PayrollInput::TYPE_EARNING,
        'amount' => '499.9000',
        'currency' => 'MYR',
    ]);
    PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'pay_item_code' => 'advance_recovery',
        'label' => 'Advance Recovery',
        'input_type' => PayrollInput::TYPE_DEDUCTION,
        'amount' => '125.2500',
        'currency' => 'MYR',
    ]);
    PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'pay_item_code' => 'travel_claim',
        'label' => 'Travel Claim',
        'input_type' => PayrollInput::TYPE_REIMBURSEMENT,
        'amount' => '80.0000',
        'currency' => 'MYR',
    ]);

    $calculated = app(PayrollRunCalculator::class)->calculate($run);

    expect($calculated->status)->toBe(PayrollRun::STATUS_CALCULATED)
        ->and($calculated->calculated_at)->not()->toBeNull()
        ->and($participant->refresh())
        ->gross_pay->toBe('3500.0000')
        ->total_deductions->toBe('125.2500')
        ->total_reimbursements->toBe('80.0000')
        ->net_pay->toBe('3454.7500');

    expect(PayrollResultLine::query()->where('payroll_run_id', $run->id)->pluck('line_type')->all())
        ->toEqualCanonicalizing([
            PayrollResultLine::TYPE_EARNING,
            PayrollResultLine::TYPE_EARNING,
            PayrollResultLine::TYPE_EMPLOYEE_DEDUCTION,
            PayrollResultLine::TYPE_REIMBURSEMENT,
            PayrollResultLine::TYPE_NET_PAY,
        ]);

    $netPayLine = PayrollResultLine::query()
        ->where('payroll_run_id', $run->id)
        ->where('line_type', PayrollResultLine::TYPE_NET_PAY)
        ->firstOrFail();

    expect($netPayLine)
        ->amount->toBe('3454.7500')
        ->source_rule->toBe('payroll-core-neutral-net-pay')
        ->and($netPayLine->explanation)->toMatchArray([
            'gross_pay' => '3500.0000',
            'total_deductions' => '125.2500',
            'total_reimbursements' => '80.0000',
        ]);

    $this->assertDatabaseHas('payroll_run_audit_events', [
        'payroll_run_id' => $run->id,
        'action' => 'calculated',
    ]);
});

test('payroll run lifecycle records review approval close and void audit events', function (): void {
    [$reviewedRun] = createPayrollCoreRun('MY-2026-01-REVIEWED');
    $reviewedRun->markReviewed();
    $reviewedRun->approve();
    $reviewedRun->close();

    expect($reviewedRun->refresh())
        ->status->toBe(PayrollRun::STATUS_CLOSED)
        ->reviewed_at->not()->toBeNull()
        ->approved_at->not()->toBeNull()
        ->closed_at->not()->toBeNull();

    expect($reviewedRun->auditEvents()->pluck('action')->all())
        ->toEqual(['reviewed', 'approved', 'closed']);

    [$voidedRun] = createPayrollCoreRun('MY-2026-01-VOIDED');
    $voidedRun->void();

    expect($voidedRun->refresh())
        ->status->toBe(PayrollRun::STATUS_VOIDED)
        ->voided_at->not()->toBeNull();

    $this->assertDatabaseHas('payroll_run_audit_events', [
        'payroll_run_id' => $voidedRun->id,
        'action' => 'voided',
    ]);
});

test('basic payslip snapshot is generated from payroll result lines', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun('MY-2026-01-PAYSLIP');

    PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'pay_item_code' => 'basic_salary',
        'label' => 'Basic Salary',
        'input_type' => PayrollInput::TYPE_EARNING,
        'amount' => '2500.0000',
        'currency' => 'MYR',
    ]);
    PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'pay_item_code' => 'advance_recovery',
        'label' => 'Advance Recovery',
        'input_type' => PayrollInput::TYPE_DEDUCTION,
        'amount' => '100.0000',
        'currency' => 'MYR',
    ]);

    app(PayrollRunCalculator::class)->calculate($run);

    $payslip = app(PayrollPayslipBuilder::class)->build($participant->refresh());

    expect($payslip['employee'])->toMatchArray([
        'id' => $employee->id,
        'number' => $employee->employee_number,
        'name' => $employee->displayName(),
    ])
        ->and($payslip['period'])->toMatchArray([
            'code' => '2026-01',
            'name' => 'January 2026',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-01-31',
            'pay_date' => '2026-01-31',
        ])
        ->and($payslip['summary'])->toMatchArray([
            'gross_pay' => '2500.0000',
            'total_deductions' => '100.0000',
            'total_reimbursements' => '0.0000',
            'net_pay' => '2400.0000',
        ])
        ->and(array_column($payslip['lines'], 'type'))->toBe([
            PayrollResultLine::TYPE_EARNING,
            PayrollResultLine::TYPE_EMPLOYEE_DEDUCTION,
            PayrollResultLine::TYPE_NET_PAY,
        ]);
});

test('closed payroll runs cannot be recalculated', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun();
    PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'pay_item_code' => 'basic_salary',
        'label' => 'Basic Salary',
        'input_type' => PayrollInput::TYPE_EARNING,
        'amount' => '3000.0000',
        'currency' => 'MYR',
    ]);

    $run->close();

    app(PayrollRunCalculator::class)->calculate($run);
})->throws(ClosedPayrollRunException::class);

test('closed payroll runs reject payroll detail mutations', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun();
    app(PayrollRunCalculator::class)->calculate($run);
    $run->close();

    PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'pay_item_code' => 'basic_salary',
        'label' => 'Basic Salary',
        'input_type' => PayrollInput::TYPE_EARNING,
        'amount' => '3000.0000',
        'currency' => 'MYR',
    ]);
})->throws(ClosedPayrollRunException::class);

test('payroll core tables are registered for stability management', function (): void {
    foreach ([
        'payroll_calendars',
        'payroll_periods',
        'payroll_runs',
        'payroll_run_participants',
        'payroll_inputs',
        'payroll_result_lines',
        'payroll_run_audit_events',
    ] as $tableName) {
        $this->assertDatabaseHas('base_database_tables', [
            'table_name' => $tableName,
            'module_name' => 'Payroll',
        ]);
    }
});
