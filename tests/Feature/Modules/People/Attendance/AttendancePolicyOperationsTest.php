<?php

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Attendance\Livewire\AllowanceRules;
use App\Modules\People\Attendance\Livewire\Approvals;
use App\Modules\People\Attendance\Livewire\PolicyGroups;
use App\Modules\People\Attendance\Livewire\PolicyGroupValidator;
use App\Modules\People\Attendance\Livewire\Rosters;
use App\Modules\People\Attendance\Livewire\ShiftTemplates;
use App\Modules\People\Attendance\Models\AttendanceAllowanceRule;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendancePunchWindow;
use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use App\Modules\People\Attendance\Services\AttendancePolicySimulationService;
use App\Modules\People\Attendance\Services\AttendancePolicyValidationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

it('returns stable validation findings for unsafe attendance policy setup', function (): void {
    $company = Company::factory()->minimal()->create();
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'BROKEN',
        'name' => 'Broken policy',
        'effective_from' => '2026-01-01',
        'work_hour_rules' => ['daily_rounding' => ['method' => 'sideways', 'minutes' => 15]],
        'lateness_rules' => ['grace' => ['in' => -5]],
        'overtime_export_rules' => ['normal' => [['lte_hours' => 2]]],
    ]);
    AttendanceAllowanceRule::query()->create([
        'company_id' => $company->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'code' => 'MEAL',
        'name' => 'Meal allowance',
        'allowance_type' => AttendanceAllowanceRule::TYPE_DAILY,
        'resolution_method' => AttendanceAllowanceRule::RESOLUTION_SUM,
        'condition_rows' => [['description' => 'Missing amount and predicate']],
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]);

    $result = app(AttendancePolicyValidationService::class)->validate($policyGroup);

    expect($result['status'])->toBe('error')
        ->and(collect($result['findings'])->pluck('code')->all())->toContain(
            'rounding_method_invalid',
            'lateness_grace_invalid',
            'overtime_export_pay_item_missing',
            'allowance_pay_item_missing',
            'allowance_condition_amount_invalid',
            'allowance_condition_predicate_missing',
        );
});

it('emits validation findings as JSON from the attendance policy validate command', function (): void {
    $company = Company::factory()->minimal()->create();
    AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'STD',
        'name' => 'Standard',
        'effective_from' => '2026-01-01',
        'lateness_rules' => ['daily_rounding' => ['method' => 'ceiling', 'minutes' => 15]],
    ]);

    $exitCode = Artisan::call('blb:attendance:policy:validate', [
        'policy' => 'STD',
        '--company' => $company->id,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($payload['status'])->toBe('ok')
        ->and($payload['policy_group']['code'])->toBe('STD')
        ->and($payload['findings'])->toBe([]);
});

it('simulates policy outcomes and allowance candidates without creating attendance facts', function (): void {
    $company = Company::factory()->minimal()->create();
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'NIGHT',
        'name' => 'Night policy',
        'effective_from' => '2026-01-01',
        'lateness_rules' => ['grace' => ['in' => 10]],
    ]);
    AttendanceAllowanceRule::query()->create([
        'company_id' => $company->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'code' => 'NIGHT_ALLOWANCE',
        'name' => 'Night allowance',
        'allowance_type' => AttendanceAllowanceRule::TYPE_DAILY,
        'payroll_pay_item_code' => 'night_shift',
        'resolution_method' => AttendanceAllowanceRule::RESOLUTION_SUM,
        'condition_rows' => [
            ['description' => 'Clock out after 20:00', 'amount' => 1, 'predicate' => ['clock_out_after' => '20:00', 'min_worked_minutes' => 240]],
        ],
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]);
    $shift = AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'DAY',
        'name' => 'Day Shift',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
    ]);

    $result = app(AttendancePolicySimulationService::class)->simulate($policyGroup, $shift, '2026-05-14', '08:12', '20:30');

    expect($result['status'])->toBe('warning')
        ->and($result['metrics']['late_minutes'])->toBe(2)
        ->and($result['metrics']['worked_minutes'])->toBe(738)
        ->and($result['metrics']['overtime_candidate_minutes'])->toBe(258)
        ->and($result['allowance_candidates'][0]['code'])->toBe('NIGHT_ALLOWANCE');
});

it('only surfaces shift-scoped allowance rules when the matching shift is simulated', function (): void {
    $company = Company::factory()->minimal()->create();
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'ROT',
        'name' => 'Rotation policy',
        'effective_from' => '2026-01-01',
        'lateness_rules' => ['grace' => ['in' => 0]],
    ]);
    $dayShift = AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'DAY',
        'name' => 'Day shift',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
    ]);
    $nightShift = AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'NIGHT',
        'name' => 'Night shift',
        'starts_at' => '20:00:00',
        'ends_at' => '05:00:00',
        'crosses_midnight' => true,
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
    ]);
    AttendanceAllowanceRule::query()->create([
        'company_id' => $company->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'attendance_shift_template_id' => $nightShift->id,
        'code' => 'NIGHT_DIFFERENTIAL',
        'name' => 'Night differential',
        'allowance_type' => AttendanceAllowanceRule::TYPE_DAILY,
        'payroll_pay_item_code' => 'night_differential',
        'resolution_method' => AttendanceAllowanceRule::RESOLUTION_SUM,
        'condition_rows' => [
            ['description' => 'Always', 'amount' => 5, 'predicate' => []],
        ],
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]);

    $simulator = app(AttendancePolicySimulationService::class);
    $dayResult = $simulator->simulate($policyGroup, $dayShift, '2026-05-14', '08:00', '17:00');
    $nightResult = $simulator->simulate($policyGroup, $nightShift, '2026-05-14', '20:00', '05:00');

    expect($dayResult['allowance_candidates'])->toBe([])
        ->and($nightResult['allowance_candidates'][0]['code'])->toBe('NIGHT_DIFFERENTIAL');
});

it('emits simulation results as JSON from the attendance policy simulate command', function (): void {
    $company = Company::factory()->minimal()->create();
    AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'STD',
        'name' => 'Standard',
        'effective_from' => '2026-01-01',
        'lateness_rules' => ['grace' => ['in' => 0]],
    ]);
    AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'DAY',
        'name' => 'Day Shift',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
    ]);

    $exitCode = Artisan::call('blb:attendance:policy:simulate', [
        'policy' => 'STD',
        '--company' => $company->id,
        '--shift' => 'DAY',
        '--date' => '2026-05-14',
        '--clock-in' => '08:12',
        '--clock-out' => '17:30',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($payload['policy_group']['code'])->toBe('STD')
        ->and($payload['shift_template']['code'])->toBe('DAY')
        ->and($payload['metrics']['late_minutes'])->toBe(12)
        ->and($payload['metrics']['overtime_candidate_minutes'])->toBe(78);
});

it('routes managers from the policy groups list into the validator and runs validation+simulation', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'STD',
        'name' => 'Standard',
        'effective_from' => '2026-01-01',
        'lateness_rules' => ['grace' => ['in' => 0]],
    ]);
    $shift = AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'DAY',
        'name' => 'Day Shift',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
    ]);

    $this->actingAs($user);

    Livewire::test(PolicyGroups::class)
        ->assertSee('Policy Groups')
        ->assertDontSee('Validation findings')
        ->call('simulatePolicyGroup', $policyGroup->id)
        ->assertRedirect(route('people.attendance.policy-groups.validator', ['policyGroup' => $policyGroup->id]));

    Livewire::test(PolicyGroupValidator::class, ['policyGroup' => $policyGroup->id])
        ->assertSee('Validation findings')
        ->assertSet('policyPreviewPolicyId', (string) $policyGroup->id)
        ->call('validatePolicyPreview')
        ->assertSee('No validation findings for this policy group.')
        ->set('policyPreviewShiftId', (string) $shift->id)
        ->set('policyPreviewDate', '2026-05-14')
        ->set('policyPreviewClockIn', '08:12')
        ->set('policyPreviewClockOut', '17:30')
        ->call('simulatePolicyPreview')
        ->assertSee('OT candidate')
        ->assertSee('Worked time exceeds expected work minutes by 78 minute(s); this is only an overtime candidate until approved.')
        ->assertHasNoErrors();
});

it('lets managers build, save, and edit policies inline on the studio page', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);

    $this->actingAs($user);

    Livewire::test(PolicyGroups::class)
        ->assertSee('Policy Groups')
        ->assertSet('mode', 'list')
        ->assertDontSee('Templates')
        ->call('startNewPolicy')
        ->assertSet('mode', 'form')
        ->assertSee('Templates')
        ->assertDontSee('Identification')
        ->call('usePolicyTemplate', 'office-grace')
        ->assertSee('Identification')
        ->assertSet('policyGraceIn', '10')
        ->set('policyCode', 'std_8_5')
        ->set('policyName', 'Standard 8 to 5')
        ->set('policyEffectiveFrom', '2026-01-01')
        ->set('policyGraceIn', '5')
        ->set('policyNormalOvertimePayItem', 'overtime')
        ->set('policyLatenessPayItem', 'lateness_deduction')
        ->call('savePolicyGroup')
        ->assertHasNoErrors()
        ->assertSet('mode', 'list')
        ->assertSee('Policy group saved.')
        ->assertSee('STD_8_5');

    $policy = AttendancePolicyGroup::query()
        ->where('company_id', $company->id)
        ->where('code', 'STD_8_5')
        ->firstOrFail();

    expect($policy->lateness_rules['grace']['in'])->toBe(5)
        ->and($policy->work_hour_rules['daily_rounding'])->toBe(['method' => 'nearest', 'minutes' => 15])
        ->and($policy->overtime_export_rules['normal'][0]['pay_item_code'])->toBe('overtime')
        ->and($policy->payroll_defaults['currency'])->toBe('MYR');

    Livewire::test(PolicyGroups::class)
        ->call('editPolicyGroup', $policy->id)
        ->assertSet('mode', 'form')
        ->assertSet('editingPolicyGroupId', $policy->id)
        ->assertSet('policyGraceIn', '5')
        ->set('policyGraceIn', '10')
        ->call('savePolicyGroup')
        ->assertHasNoErrors()
        ->assertSet('mode', 'list');

    expect($policy->refresh()->version)->toBe(2)
        ->and($policy->lateness_rules['grace']['in'])->toBe(10);
});

it('restores policy edit mode from the URL', function (): void {
    $user = createAdminUser();
    $policy = AttendancePolicyGroup::query()->create([
        'company_id' => $user->company_id,
        'code' => 'URL_POLICY',
        'name' => 'URL policy',
        'effective_from' => '2026-01-01',
        'lateness_rules' => ['grace' => ['in' => 7]],
    ]);

    Livewire::actingAs($user)
        ->withQueryParams(['policy' => $policy->id])
        ->test(PolicyGroups::class)
        ->assertSet('mode', 'form')
        ->assertSet('editingPolicyGroupId', $policy->id)
        ->assertSet('policyCode', 'URL_POLICY')
        ->assertSet('policyGraceIn', '7')
        ->assertSee('Identification');
});

it('restores policy create mode and selected template from the URL', function (): void {
    $user = createAdminUser();

    Livewire::actingAs($user)
        ->withQueryParams(['mode' => 'form', 'template' => 'office-grace'])
        ->test(PolicyGroups::class)
        ->assertSet('mode', 'form')
        ->assertSet('editingPolicyGroupId', null)
        ->assertSet('selectedPolicyTemplateKey', 'office-grace')
        ->assertSet('showPolicyBuilderForm', true)
        ->assertSet('policyCode', 'OFFICE_GRACE')
        ->assertSet('policyGraceIn', '10')
        ->assertSee('Identification');
});

it('lists field errors prominently when saving a policy with invalid input', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    Livewire::test(PolicyGroups::class)
        ->call('startNewPolicy')
        ->call('usePolicyTemplate', 'office-grace')
        ->set('policyCode', '')
        ->set('policyName', '')
        ->call('savePolicyGroup')
        ->assertHasErrors(['policyCode', 'policyName'])
        ->assertSee('Fix these before saving:');
});

it('returns to the list when cancelling a policy edit', function (): void {
    $user = createAdminUser();
    $policy = AttendancePolicyGroup::query()->create([
        'company_id' => $user->company_id,
        'code' => 'CANCEL_ME',
        'name' => 'Cancel me',
        'effective_from' => '2026-01-01',
    ]);

    $this->actingAs($user);

    Livewire::test(PolicyGroups::class)
        ->call('editPolicyGroup', $policy->id)
        ->assertSet('mode', 'form')
        ->set('policyGraceIn', '99')
        ->call('cancelPolicyEdit')
        ->assertSet('mode', 'list')
        ->assertSet('editingPolicyGroupId', null);

    expect($policy->refresh()->lateness_rules['grace']['in'] ?? 0)->not->toBe(99);
});

it('lets managers upload and download attendance policy templates as JSON', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    $template = [
        'schema' => 'belimbing.attendance.policy-template.v1',
        'code' => 'json_policy',
        'name' => 'JSON policy',
        'work_rounding_method' => 'nearest',
        'work_rounding_minutes' => 15,
        'lateness_rounding_method' => 'ceiling',
        'lateness_rounding_minutes' => 5,
        'grace_in' => 7,
        'early_ot_minimum' => 45,
        'late_ot_minimum' => 45,
        'normal_ot_pay_item' => 'overtime',
        'lateness_pay_item' => 'lateness_deduction',
    ];

    Livewire::test(PolicyGroups::class)
        ->call('startNewPolicy')
        ->assertSee('Templates')
        ->assertSee('Upload Template')
        ->assertDontSee('Identification')
        ->set('policyTemplateUpload', UploadedFile::fake()->createWithContent('policy-template.json', json_encode($template)))
        ->call('importPolicyTemplate')
        ->assertHasNoErrors()
        ->assertSee('Policy template loaded.')
        ->assertSee('Identification')
        ->assertSet('mode', 'form')
        ->assertSet('showPolicyBuilderForm', true)
        ->assertSet('policyCode', 'JSON_POLICY')
        ->assertSet('policyGraceIn', '7');
});

it('downloads policy template JSON directly from the list without entering the builder', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'EXPORT_ME',
        'name' => 'Export me',
        'effective_from' => '2026-01-01',
        'payroll_defaults' => ['currency' => 'MYR'],
    ]);

    $this->actingAs($user);

    Livewire::test(PolicyGroups::class)
        ->call('exportPolicyGroupTemplate', $policyGroup->id)
        ->assertSee('Policy template JSON ready to download from EXPORT_ME.')
        ->assertSet('policyTemplateExportJson', fn (string $json): bool => str_contains($json, 'belimbing.attendance.policy-template.v1') && str_contains($json, 'EXPORT_ME'));
});

it('lets managers create attendance allowance rules', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'STD',
        'name' => 'Standard',
        'effective_from' => '2026-01-01',
    ]);

    $this->actingAs($user);

    $component = Livewire::test(AllowanceRules::class)
        ->assertSee('Create allowance rule')
        ->set('allowancePolicyGroupId', (string) $policyGroup->id)
        ->set('allowanceCode', 'night_allowance')
        ->set('allowanceName', 'Night allowance')
        ->set('allowancePayItemCode', 'night_allowance')
        ->set('allowanceAmount', '25.00')
        ->set('allowanceConditionPreset', 'clock_out_after')
        ->set('allowanceClockOutAfter', '22:00')
        ->set('allowanceEffectiveFrom', '2026-01-01')
        ->call('saveAllowanceRule')
        ->assertHasNoErrors()
        ->assertSee('Allowance rule saved.')
        ->assertSee('NIGHT_ALLOWANCE');

    $rule = AttendanceAllowanceRule::query()
        ->where('company_id', $company->id)
        ->where('code', 'NIGHT_ALLOWANCE')
        ->firstOrFail();

    expect($rule->attendance_policy_group_id)->toBe($policyGroup->id)
        ->and($rule->payroll_pay_item_code)->toBe('night_allowance')
        ->and($rule->condition_rows[0]['amount'])->toBe(25)
        ->and($rule->condition_rows[0]['predicate'])->toBe(['clock_out_after' => '22:00']);

    $component
        ->call('editAllowanceRule', $rule->id)
        ->assertSee('Edit allowance rule')
        ->set('allowanceAmount', '30.00')
        ->call('saveAllowanceRule')
        ->assertHasNoErrors();

    expect($rule->refresh()->condition_rows[0]['amount'])->toBe(30);

    $component
        ->call('deleteAllowanceRule', $rule->id)
        ->assertSee('Allowance rule deleted.');

    expect(AttendanceAllowanceRule::query()->whereKey($rule->id)->exists())->toBeFalse();
});

it('lets managers create roster assignments from the guided roster builder', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'STD',
        'name' => 'Standard',
        'effective_from' => '2026-01-01',
    ]);
    $shift = AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'DAY',
        'name' => 'Day Shift',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
    ]);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->assertSee('Roster Builder')
        ->assertSee('Set up policies')
        ->set('rosterEmployeeId', (string) $employee->id)
        ->set('rosterShiftTemplateId', (string) $shift->id)
        ->set('rosterPolicyGroupId', (string) $policyGroup->id)
        ->set('rosterEffectiveFrom', '2026-06-01')
        ->set('rosterEffectiveTo', '2026-06-30')
        ->set('rosterPublishState', 'published')
        ->call('saveRosterAssignment')
        ->assertHasNoErrors()
        ->assertSee('Roster assignment saved.')
        ->assertSee($employee->full_name);

    $assignment = AttendanceRosterAssignment::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->firstOrFail();

    expect($assignment->attendance_shift_template_id)->toBe($shift->id)
        ->and($assignment->attendance_policy_group_id)->toBe($policyGroup->id)
        ->and($assignment->publish_state)->toBe('published');
});

it('lets managers build shift templates inline from guided templates and import JSON', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    Livewire::test(ShiftTemplates::class)
        ->assertSet('mode', 'list')
        ->assertDontSee('Best for')
        ->call('startNewShift')
        ->assertSet('mode', 'form')
        ->assertSee('Best for')
        ->assertDontSee('Shift code')
        ->call('useShiftTemplate', 'night-shift')
        ->assertSee('Shift code')
        ->assertSet('shiftCode', 'NIGHT_SHIFT')
        ->set('shiftCode', 'NIGHT_MAIN')
        ->set('shiftName', 'Night Main')
        ->call('saveShiftTemplate')
        ->assertHasNoErrors()
        ->assertSet('mode', 'list')
        ->assertSee('Shift template saved.');

    $shift = AttendanceShiftTemplate::query()
        ->where('company_id', $user->company_id)
        ->where('code', 'NIGHT_MAIN')
        ->firstOrFail();

    expect($shift->crosses_midnight)->toBeTrue()
        ->and($shift->expected_work_minutes)->toBe(660)
        ->and($shift->break_windows[0]['starts_at'])->toBe('00:00')
        ->and($shift->punchWindows()->where('event_type', AttendancePunchWindow::TYPE_IN)->exists())->toBeTrue()
        ->and($shift->punchWindows()->where('event_type', AttendancePunchWindow::TYPE_OUT)->exists())->toBeTrue();

    Livewire::test(ShiftTemplates::class)
        ->call('startNewShift')
        ->set('shiftTemplateUpload', UploadedFile::fake()->createWithContent('shift-template.json', json_encode([
            'schema' => 'belimbing.attendance.shift-template.v1',
            'code' => 'IMPORT_DAY',
            'name' => 'Imported Day',
            'starts_at' => '09:00',
            'ends_at' => '18:00',
            'expected_work_minutes' => 480,
            'break_windows' => [['starts_at' => '13:00', 'ends_at' => '14:00']],
            'punch_windows' => [
                'in' => ['before_minutes' => 30, 'after_minutes' => 10],
                'out' => ['before_minutes' => 10, 'after_minutes' => 90],
            ],
            'payroll_attribution' => 'shift_start_date',
        ])))
        ->call('importShiftTemplate')
        ->assertHasNoErrors()
        ->assertSet('mode', 'form')
        ->assertSet('shiftCode', 'IMPORT_DAY')
        ->assertSet('shiftBreakStartsAt', '13:00')
        ->assertSet('shiftInWindowBeforeMinutes', '30');
});

it('restores shift edit mode from the URL', function (): void {
    $user = createAdminUser();
    $shift = AttendanceShiftTemplate::query()->create([
        'company_id' => $user->company_id,
        'code' => 'URL_SHIFT',
        'name' => 'URL shift',
        'starts_at' => '06:00:00',
        'ends_at' => '14:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]);

    Livewire::actingAs($user)
        ->withQueryParams(['shift' => $shift->id])
        ->test(ShiftTemplates::class)
        ->assertSet('mode', 'form')
        ->assertSet('editingShiftTemplateId', $shift->id)
        ->assertSet('shiftCode', 'URL_SHIFT')
        ->assertSet('shiftStartsAt', '06:00')
        ->assertSee('Shift code');
});

it('restores shift create mode and selected template from the URL', function (): void {
    $user = createAdminUser();

    Livewire::actingAs($user)
        ->withQueryParams(['mode' => 'form', 'template' => 'night-shift'])
        ->test(ShiftTemplates::class)
        ->assertSet('mode', 'form')
        ->assertSet('editingShiftTemplateId', null)
        ->assertSet('selectedShiftTemplateKey', 'night-shift')
        ->assertSet('showShiftBuilderForm', true)
        ->assertSet('shiftCode', 'NIGHT_SHIFT')
        ->assertSet('shiftStartsAt', '20:00')
        ->assertSee('Shift code');
});

it('toggles shift template status from the list', function (): void {
    $user = createAdminUser();
    $shift = AttendanceShiftTemplate::query()->create([
        'company_id' => $user->company_id,
        'code' => 'TOGGLE_ME',
        'name' => 'Toggle me',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]);

    $this->actingAs($user);

    Livewire::test(ShiftTemplates::class)
        ->call('toggleShiftStatus', $shift->id)
        ->assertHasNoErrors()
        ->assertSee('Shift status updated.');

    expect($shift->refresh()->status)->toBe('inactive');
});

it('uses focused titles for each attendance setup page', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    Livewire::test(PolicyGroups::class)
        ->assertSee('Policy Groups')
        ->assertDontSee('Attendance Days')
        ->assertDontSee('Search employee...')
        ->assertDontSee('Settings areas')
        ->assertDontSee('Attendance Settings');

    // List mode hides templates; entering form mode reveals them.
    Livewire::test(ShiftTemplates::class)
        ->assertSet('mode', 'list')
        ->assertSee('Shifts')
        ->assertDontSee('Best for')
        ->call('startNewShift')
        ->assertSee('Best for');
});

it('keeps operational timecard controls off the approvals surface', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    Livewire::test(Approvals::class)
        ->assertSee('Overtime Queue')
        ->assertDontSee('Attendance Days')
        ->assertDontSee('Search employee...');
});
