<?php

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Payroll\Contracts\CalculatesPayrollRun;
use App\Modules\People\Payroll\Contracts\ClassifiesPayrollPayItems;
use App\Modules\People\Payroll\Contracts\PayrollCountryPack;
use App\Modules\People\Payroll\Contracts\ProvidesPayrollExports;
use App\Modules\People\Payroll\Contracts\ProvidesPayrollProfileSchemas;
use App\Modules\People\Payroll\Data\CountryPackManifest;
use App\Modules\People\Payroll\Data\PayrollCalculationContext;
use App\Modules\People\Payroll\Data\PayrollCalculationResult;
use App\Modules\People\Payroll\Data\PayrollProposedResultLine;
use App\Modules\People\Payroll\Data\ProfileSchema;
use App\Modules\People\Payroll\Exceptions\ClosedPayrollRunException;
use App\Modules\People\Payroll\Models\PayrollCalendar;
use App\Modules\People\Payroll\Models\PayrollEmployeeStatutoryProfile;
use App\Modules\People\Payroll\Models\PayrollEmployerStatutoryProfile;
use App\Modules\People\Payroll\Models\PayrollInput;
use App\Modules\People\Payroll\Models\PayrollPayItem;
use App\Modules\People\Payroll\Models\PayrollPayItemClassification;
use App\Modules\People\Payroll\Models\PayrollPeriod;
use App\Modules\People\Payroll\Models\PayrollResultLine;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Models\PayrollRunParticipant;
use App\Modules\People\Payroll\Models\PayrollStatutoryRuleRow;
use App\Modules\People\Payroll\Models\PayrollStatutoryRuleSet;
use App\Modules\People\Payroll\Services\PayItemClassifier;
use App\Modules\People\Payroll\Services\PayrollCountryPackRegistry;
use App\Modules\People\Payroll\Services\PayrollPayslipBuilder;
use App\Modules\People\Payroll\Services\PayrollRunCalculator;
use App\Modules\People\Payroll\Services\StatutoryProfileResolver;
use App\Modules\People\Payroll\Services\StatutoryRuleSetResolver;
use Illuminate\Support\Carbon;

function createPayrollCoreTestCountryPack(string $countryIso = 'SG'): PayrollCountryPack
{
    return new class($countryIso) implements CalculatesPayrollRun, ClassifiesPayrollPayItems, PayrollCountryPack, ProvidesPayrollExports, ProvidesPayrollProfileSchemas
    {
        public function __construct(private readonly string $countryIso) {}

        public function manifest(): CountryPackManifest
        {
            return new CountryPackManifest(
                countryIso: $this->countryIso,
                packIdentifier: 'test/payroll-'.strtolower($this->countryIso),
                packVersion: 'test.1',
                supportedCoreContracts: [PayrollCountryPackRegistry::CORE_CONTRACT_VERSION],
                statutoryDataVersions: ['test.1'],
            );
        }

        public function profileSchemas(): ProvidesPayrollProfileSchemas
        {
            return $this;
        }

        public function employerSchema(): ProfileSchema
        {
            return new ProfileSchema($this->countryIso, 'employer', 'test', 'test.1', []);
        }

        public function employeeSchema(): ProfileSchema
        {
            return new ProfileSchema($this->countryIso, 'employee', 'test', 'test.1', []);
        }

        public function payItemClassifier(): ClassifiesPayrollPayItems
        {
            return $this;
        }

        public function classificationsFor(PayrollPayItem $payItem, Carbon|string $onDate): array
        {
            return [];
        }

        public function calculator(): CalculatesPayrollRun
        {
            return $this;
        }

        public function calculate(PayrollCalculationContext $context): PayrollCalculationResult
        {
            return new PayrollCalculationResult(
                resultLines: [
                    new PayrollProposedResultLine(
                        lineType: PayrollResultLine::TYPE_EMPLOYEE_CONTRIBUTION,
                        code: 'test_employee_contribution',
                        label: 'Test Employee Contribution',
                        amount: '110.0000',
                        currency: $context->run->currency,
                        sourceRule: 'test-contribution-rule',
                        sourceVersion: 'test.1',
                        explanation: ['pay_date' => $context->payDate()],
                    ),
                    new PayrollProposedResultLine(
                        lineType: PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION,
                        code: 'test_employer_contribution',
                        label: 'Test Employer Contribution',
                        amount: '130.0000',
                        currency: $context->run->currency,
                        sourceRule: 'test-contribution-rule',
                        sourceVersion: 'test.1',
                        explanation: ['country_iso' => $context->countryIso()],
                    ),
                ],
                warnings: [['level' => 'info', 'message' => 'Test warning.']],
                metadata: ['pack_identifier' => 'test/payroll-'.strtolower($this->countryIso)],
            );
        }

        public function exports(): ProvidesPayrollExports
        {
            return $this;
        }

        public function definitions(): array
        {
            return [];
        }
    };
}

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

test('payroll core persists registered country pack result lines before net pay', function (): void {
    app(PayrollCountryPackRegistry::class)->register(createPayrollCoreTestCountryPack('SG'));
    [$run, $participant, $employee] = createPayrollCoreRun('SG-2026-01-MAIN');
    $run->calendar->forceFill(['country_iso' => 'SG'])->save();

    PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'pay_item_code' => 'basic_salary',
        'label' => 'Basic Salary',
        'input_type' => PayrollInput::TYPE_EARNING,
        'amount' => '1000.0000',
        'currency' => 'MYR',
    ]);

    app(PayrollRunCalculator::class)->calculate($run->refresh());

    expect($participant->refresh())
        ->gross_pay->toBe('1000.0000')
        ->total_deductions->toBe('110.0000')
        ->net_pay->toBe('890.0000');

    $resultLines = PayrollResultLine::query()
        ->where('payroll_run_participant_id', $participant->id)
        ->orderBy('id')
        ->get();

    expect($resultLines->pluck('code')->all())->toBe([
        'basic_salary',
        'test_employee_contribution',
        'test_employer_contribution',
        'net_pay',
    ])
        ->and($resultLines->firstWhere('code', 'test_employee_contribution')->line_type)
        ->toBe(PayrollResultLine::TYPE_EMPLOYEE_CONTRIBUTION)
        ->and($resultLines->firstWhere('code', 'test_employer_contribution')->line_type)
        ->toBe(PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION)
        ->and($resultLines->firstWhere('code', 'net_pay')->explanation)
        ->toMatchArray([
            'total_deductions' => '110.0000',
            'country_pack' => ['pack_identifier' => 'test/payroll-sg'],
            'country_pack_warnings' => [['level' => 'info', 'message' => 'Test warning.']],
        ])
        ->and($run->refresh()->auditEvents()->latest('id')->first()->payload['country_pack'])
        ->toMatchArray([
            'country_iso' => 'SG',
            'pack_identifier' => 'test/payroll-sg',
            'pack_version' => 'test.1',
        ]);
});

test('malaysia pack calculates epf socso eis and hrd levy from classified statutory wages', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun('MY-2026-01-EPF');
    $company = Company::query()->findOrFail(Company::LICENSEE_ID);

    PayrollEmployerStatutoryProfile::query()->create([
        'company_id' => $company->id,
        'country_iso' => 'MY',
        'source_pack' => 'belimbing/payroll-my',
        'source_version' => '2026.dev',
        'effective_from' => '2026-01-01',
        'profile_data' => ['hrd_levy_applicable' => true],
        'validation_messages' => [],
    ]);

    $basicSalary = PayrollPayItem::query()->create([
        'company_id' => $company->id,
        'code' => 'basic_salary',
        'name' => 'Basic Salary',
        'input_type' => PayrollInput::TYPE_EARNING,
        'status' => 'active',
    ]);
    PayrollPayItemClassification::query()->create([
        'payroll_pay_item_id' => $basicSalary->id,
        'country_iso' => 'MY',
        'classification_key' => 'statutory_wage_base',
        'classification_value' => 'ordinary_wage',
        'effective_from' => '2026-01-01',
        'source_pack' => 'belimbing/payroll-my',
        'source_version' => '2026.dev',
    ]);

    $travelClaim = PayrollPayItem::query()->create([
        'company_id' => $company->id,
        'code' => 'travel_claim',
        'name' => 'Travel Claim',
        'input_type' => PayrollInput::TYPE_REIMBURSEMENT,
        'status' => 'active',
    ]);
    PayrollPayItemClassification::query()->create([
        'payroll_pay_item_id' => $travelClaim->id,
        'country_iso' => 'MY',
        'classification_key' => 'statutory_wage_base',
        'classification_value' => 'excluded',
        'effective_from' => '2026-01-01',
        'source_pack' => 'belimbing/payroll-my',
        'source_version' => '2026.dev',
    ]);

    $ruleSet = PayrollStatutoryRuleSet::query()->create([
        'country_iso' => 'MY',
        'rule_key' => 'epf_contribution_schedule',
        'name' => 'EPF dev test schedule',
        'source_pack' => 'belimbing/payroll-my',
        'source_version' => '2026.dev',
        'effective_from' => '2026-01-01',
        'rounding_policy' => ['mode' => 'ceiling', 'precision' => '0.01'],
    ]);
    PayrollStatutoryRuleRow::query()->create([
        'payroll_statutory_rule_set_id' => $ruleSet->id,
        'sort_order' => 10,
        'row_key' => 'standard',
        'min_wage' => '0.0000',
        'max_wage' => null,
        'employee_rate' => '0.11000000',
        'employer_rate' => '0.13000000',
    ]);
    $socsoRuleSet = PayrollStatutoryRuleSet::query()->create([
        'country_iso' => 'MY',
        'rule_key' => 'socso_contribution_schedule',
        'name' => 'SOCSO dev test schedule',
        'source_pack' => 'belimbing/payroll-my',
        'source_version' => '2026.dev',
        'effective_from' => '2026-01-01',
        'rounding_policy' => ['mode' => 'ceiling', 'precision' => '0.01'],
    ]);
    PayrollStatutoryRuleRow::query()->create([
        'payroll_statutory_rule_set_id' => $socsoRuleSet->id,
        'sort_order' => 10,
        'row_key' => 'standard',
        'min_wage' => '0.0000',
        'max_wage' => null,
        'employee_rate' => '0.00500000',
        'employer_rate' => '0.01750000',
    ]);
    $eisRuleSet = PayrollStatutoryRuleSet::query()->create([
        'country_iso' => 'MY',
        'rule_key' => 'eis_contribution_schedule',
        'name' => 'EIS dev test schedule',
        'source_pack' => 'belimbing/payroll-my',
        'source_version' => '2026.dev',
        'effective_from' => '2026-01-01',
        'rounding_policy' => ['mode' => 'ceiling', 'precision' => '0.01'],
    ]);
    PayrollStatutoryRuleRow::query()->create([
        'payroll_statutory_rule_set_id' => $eisRuleSet->id,
        'sort_order' => 10,
        'row_key' => 'standard',
        'min_wage' => '0.0000',
        'max_wage' => null,
        'employee_rate' => '0.00200000',
        'employer_rate' => '0.00200000',
    ]);
    $hrdLevyRuleSet = PayrollStatutoryRuleSet::query()->create([
        'country_iso' => 'MY',
        'rule_key' => 'hrd_levy_schedule',
        'name' => 'HRD levy dev test schedule',
        'source_pack' => 'belimbing/payroll-my',
        'source_version' => '2026.dev',
        'effective_from' => '2026-01-01',
        'rounding_policy' => ['mode' => 'ceiling', 'precision' => '0.01'],
    ]);
    PayrollStatutoryRuleRow::query()->create([
        'payroll_statutory_rule_set_id' => $hrdLevyRuleSet->id,
        'sort_order' => 10,
        'row_key' => 'standard',
        'min_wage' => '0.0000',
        'max_wage' => null,
        'levy_rate' => '0.01000000',
    ]);

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

    app(PayrollRunCalculator::class)->calculate($run->refresh());

    $employeeEpf = PayrollResultLine::query()
        ->where('payroll_run_participant_id', $participant->id)
        ->where('code', 'my_epf_employee')
        ->firstOrFail();
    $employerEpf = PayrollResultLine::query()
        ->where('payroll_run_participant_id', $participant->id)
        ->where('code', 'my_epf_employer')
        ->firstOrFail();
    $employeeSocso = PayrollResultLine::query()
        ->where('payroll_run_participant_id', $participant->id)
        ->where('code', 'my_socso_employee')
        ->firstOrFail();
    $employerSocso = PayrollResultLine::query()
        ->where('payroll_run_participant_id', $participant->id)
        ->where('code', 'my_socso_employer')
        ->firstOrFail();
    $employeeEis = PayrollResultLine::query()
        ->where('payroll_run_participant_id', $participant->id)
        ->where('code', 'my_eis_employee')
        ->firstOrFail();
    $employerEis = PayrollResultLine::query()
        ->where('payroll_run_participant_id', $participant->id)
        ->where('code', 'my_eis_employer')
        ->firstOrFail();
    $hrdLevy = PayrollResultLine::query()
        ->where('payroll_run_participant_id', $participant->id)
        ->where('code', 'my_hrd_levy')
        ->firstOrFail();

    expect($employeeEpf)
        ->line_type->toBe(PayrollResultLine::TYPE_EMPLOYEE_CONTRIBUTION)
        ->amount->toBe('330.0000')
        ->and($employerEpf)
        ->line_type->toBe(PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION)
        ->amount->toBe('390.0000')
        ->and($employeeSocso)->amount->toBe('15.0000')
        ->and($employerSocso)->amount->toBe('52.5000')
        ->and($employeeEis)->amount->toBe('6.0000')
        ->and($employerEis)->amount->toBe('6.0000')
        ->and($hrdLevy)
        ->line_type->toBe(PayrollResultLine::TYPE_EMPLOYER_LEVY)
        ->amount->toBe('30.0000')
        ->and($employeeEpf->explanation)->toMatchArray([
            'wage_base' => '3000.0000',
            'rule_row_key' => 'standard',
            'share' => 'employee',
        ])
        ->and($participant->refresh())
        ->gross_pay->toBe('3000.0000')
        ->total_deductions->toBe('351.0000')
        ->total_reimbursements->toBe('80.0000')
        ->net_pay->toBe('2729.0000');
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
        'payroll_pay_items',
        'payroll_pay_item_classifications',
        'payroll_employer_statutory_profiles',
        'payroll_employee_statutory_profiles',
        'payroll_statutory_rule_sets',
        'payroll_statutory_rule_rows',
    ] as $tableName) {
        $this->assertDatabaseHas('base_database_tables', [
            'table_name' => $tableName,
            'module_name' => 'Payroll',
        ]);
    }
});

test('pay item classifications resolve by country and effective date without country columns in core inputs', function (): void {
    $company = Company::query()->findOrFail(Company::LICENSEE_ID);
    $payItem = PayrollPayItem::query()->create([
        'company_id' => $company->id,
        'code' => 'basic_salary',
        'name' => 'Basic Salary',
        'input_type' => PayrollInput::TYPE_EARNING,
        'status' => 'active',
    ]);

    PayrollPayItemClassification::query()->create([
        'payroll_pay_item_id' => $payItem->id,
        'country_iso' => null,
        'classification_key' => 'payroll_input_family',
        'classification_value' => 'regular_earning',
        'effective_from' => '2026-01-01',
        'source_pack' => 'payroll-core',
        'source_version' => 'v0',
    ]);
    PayrollPayItemClassification::query()->create([
        'payroll_pay_item_id' => $payItem->id,
        'country_iso' => 'MY',
        'classification_key' => 'statutory_wage_base',
        'classification_value' => 'ordinary_wage',
        'effective_from' => '2026-01-01',
        'source_pack' => 'belimbing/payroll-my',
        'source_version' => '2026.1',
        'metadata' => ['reason' => 'Malaysia pack owns statutory treatment.'],
    ]);
    PayrollPayItemClassification::query()->create([
        'payroll_pay_item_id' => $payItem->id,
        'country_iso' => 'MY',
        'classification_key' => 'statutory_wage_base',
        'classification_value' => 'ordinary_wage_v2',
        'effective_from' => '2026-07-01',
        'source_pack' => 'belimbing/payroll-my',
        'source_version' => '2026.2',
    ]);

    $january = app(PayItemClassifier::class)->classificationsFor($payItem, 'my', '2026-01-31');
    $july = app(PayItemClassifier::class)->classificationsFor($payItem, 'MY', '2026-07-31');
    $singapore = app(PayItemClassifier::class)->classificationsFor($payItem, 'SG', '2026-07-31');

    expect($january)
        ->toHaveKey('payroll_input_family')
        ->toHaveKey('statutory_wage_base')
        ->and($january['payroll_input_family'])->toMatchArray([
            'value' => 'regular_earning',
            'country_iso' => null,
            'source_pack' => 'payroll-core',
        ])
        ->and($january['statutory_wage_base'])->toMatchArray([
            'value' => 'ordinary_wage',
            'country_iso' => 'MY',
            'source_pack' => 'belimbing/payroll-my',
            'source_version' => '2026.1',
            'metadata' => ['reason' => 'Malaysia pack owns statutory treatment.'],
        ])
        ->and($july['statutory_wage_base'])->toMatchArray([
            'value' => 'ordinary_wage_v2',
            'source_version' => '2026.2',
        ])
        ->and($singapore)->toHaveKey('payroll_input_family')
        ->and($singapore)->not()->toHaveKey('statutory_wage_base');
});

test('statutory profile resolver selects effective employer and employee profiles without Malaysia-specific columns', function (): void {
    $company = Company::query()->findOrFail(Company::LICENSEE_ID);
    $employee = Employee::factory()->create(['company_id' => $company->id]);

    PayrollEmployerStatutoryProfile::query()->create([
        'company_id' => $company->id,
        'country_iso' => 'MY',
        'source_pack' => 'belimbing/payroll-my',
        'source_version' => '2026.1',
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-06-30',
        'profile_data' => [
            'epf_employer_number' => 'EPF-OLD',
            'socso_employer_number' => 'SOCSO-001',
            'hrd_levy_applicable' => false,
        ],
        'validation_messages' => [],
    ]);
    PayrollEmployerStatutoryProfile::query()->create([
        'company_id' => $company->id,
        'country_iso' => 'MY',
        'source_pack' => 'belimbing/payroll-my',
        'source_version' => '2026.2',
        'effective_from' => '2026-07-01',
        'profile_data' => [
            'epf_employer_number' => 'EPF-NEW',
            'socso_employer_number' => 'SOCSO-001',
            'hrd_levy_applicable' => true,
        ],
        'validation_messages' => [['level' => 'warning', 'message' => 'HRD levy requires monthly headcount confirmation.']],
    ]);
    PayrollEmployeeStatutoryProfile::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'country_iso' => 'MY',
        'source_pack' => 'belimbing/payroll-my',
        'source_version' => '2026.1',
        'effective_from' => '2026-01-01',
        'profile_data' => [
            'citizenship_status' => 'citizen',
            'tax_residency' => 'resident',
            'epf_number' => 'KWSP-123',
            'zakat_salary_deduction_authorized' => false,
        ],
    ]);

    $resolver = app(StatutoryProfileResolver::class);
    $januaryEmployerProfile = $resolver->employerProfile($company, 'my', '2026-01-31');
    $julyEmployerProfile = $resolver->employerProfile($company->id, 'MY', '2026-07-31');
    $employeeProfile = $resolver->employeeProfile($employee, 'MY', '2026-01-31');

    expect($januaryEmployerProfile)
        ->not()->toBeNull()
        ->source_version->toBe('2026.1')
        ->and($januaryEmployerProfile->profile_data)->toMatchArray([
            'epf_employer_number' => 'EPF-OLD',
            'hrd_levy_applicable' => false,
        ])
        ->and($julyEmployerProfile)
        ->not()->toBeNull()
        ->source_version->toBe('2026.2')
        ->and($julyEmployerProfile->profile_data)->toMatchArray([
            'epf_employer_number' => 'EPF-NEW',
            'hrd_levy_applicable' => true,
        ])
        ->and($julyEmployerProfile->validation_messages)->toBe([
            ['level' => 'warning', 'message' => 'HRD levy requires monthly headcount confirmation.'],
        ])
        ->and($employeeProfile)
        ->not()->toBeNull()
        ->source_pack->toBe('belimbing/payroll-my')
        ->and($employeeProfile->profile_data)->toMatchArray([
            'citizenship_status' => 'citizen',
            'tax_residency' => 'resident',
            'epf_number' => 'KWSP-123',
        ])
        ->and($resolver->employeeProfile($employee, 'SG', '2026-01-31'))->toBeNull();
});

test('statutory rule sets resolve effective contribution tables with ordered rows', function (): void {
    PayrollStatutoryRuleSet::query()->create([
        'country_iso' => 'MY',
        'rule_key' => 'epf_contribution_schedule',
        'name' => 'EPF contribution schedule 2026 H1',
        'source_pack' => 'belimbing/payroll-my',
        'source_version' => '2026.1',
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-06-30',
        'rounding_policy' => ['mode' => 'ceiling', 'precision' => '0.01'],
    ]);
    $secondHalfRuleSet = PayrollStatutoryRuleSet::query()->create([
        'country_iso' => 'MY',
        'rule_key' => 'epf_contribution_schedule',
        'name' => 'EPF contribution schedule 2026 H2',
        'source_pack' => 'belimbing/payroll-my',
        'source_version' => '2026.2',
        'effective_from' => '2026-07-01',
        'rounding_policy' => ['mode' => 'ceiling', 'precision' => '0.01'],
        'metadata' => ['official_reference' => 'country-pack-maintained'],
    ]);
    PayrollStatutoryRuleRow::query()->create([
        'payroll_statutory_rule_set_id' => $secondHalfRuleSet->id,
        'sort_order' => 20,
        'row_key' => 'band-2',
        'min_wage' => '5000.0100',
        'max_wage' => null,
        'employee_rate' => '0.11000000',
        'employer_rate' => '0.12000000',
        'row_data' => ['category' => 'standard'],
    ]);
    PayrollStatutoryRuleRow::query()->create([
        'payroll_statutory_rule_set_id' => $secondHalfRuleSet->id,
        'sort_order' => 10,
        'row_key' => 'band-1',
        'min_wage' => '0.0000',
        'max_wage' => '5000.0000',
        'employee_rate' => '0.11000000',
        'employer_rate' => '0.13000000',
        'row_data' => ['category' => 'standard'],
    ]);

    $ruleSet = app(StatutoryRuleSetResolver::class)->resolve('my', 'epf_contribution_schedule', '2026-07-31');

    expect($ruleSet)
        ->not()->toBeNull()
        ->source_pack->toBe('belimbing/payroll-my')
        ->source_version->toBe('2026.2')
        ->and($ruleSet->rounding_policy)->toBe(['mode' => 'ceiling', 'precision' => '0.01'])
        ->and($ruleSet->metadata)->toBe(['official_reference' => 'country-pack-maintained'])
        ->and($ruleSet->rows)->toHaveCount(2)
        ->and($ruleSet->rows->pluck('row_key')->all())->toBe(['band-1', 'band-2'])
        ->and($ruleSet->rows->first()->max_wage)->toBe('5000.0000')
        ->and($ruleSet->rows->first()->employee_rate)->toBe('0.11000000');

    expect(app(StatutoryRuleSetResolver::class)->resolve('MY', 'missing_rule', '2026-07-31'))->toBeNull();
});
