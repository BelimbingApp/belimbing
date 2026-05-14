<?php

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Attendance\Livewire\Index;
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

it('lets managers interact with the policy studio from attendance settings', function (): void {
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

    Livewire::test(Index::class, ['surface' => 'settings', 'section' => 'policies'])
        ->assertSee('Policy Groups')
        ->assertDontSee('Roster Setup')
        ->assertDontSee('Settings areas')
        ->call('simulatePolicyGroup', $policyGroup->id)
        ->assertSee('Validation findings')
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

it('lets managers build edit and validate attendance policy groups from settings', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);

    $this->actingAs($user);

    $component = Livewire::test(Index::class, ['surface' => 'settings', 'section' => 'policies'])
        ->assertSee('Policy Groups')
        ->assertDontSee('Identification')
        ->call('usePolicyTemplate', 'office-grace')
        ->assertSee('Identification')
        ->assertSee('Readiness status')
        ->assertSee('Ready to publish')
        ->assertSet('policyGraceIn', '10')
        ->call('usePolicyTemplate', 'office-grace')
        ->assertDontSee('Identification')
        ->assertSet('showAllPolicyTemplates', true)
        ->call('usePolicyTemplate', 'office-grace')
        ->set('policyCode', 'std_8_5')
        ->set('policyName', 'Standard 8 to 5')
        ->set('policyEffectiveFrom', '2026-01-01')
        ->set('policyGraceIn', '5')
        ->set('policyNormalOvertimePayItem', 'overtime')
        ->set('policyLatenessPayItem', 'lateness_deduction')
        ->call('savePolicyGroup')
        ->assertHasNoErrors()
        ->assertSee('Policy group saved and validated.')
        ->assertSee('STD_8_5');

    $policy = AttendancePolicyGroup::query()
        ->where('company_id', $company->id)
        ->where('code', 'STD_8_5')
        ->firstOrFail();

    expect($policy->lateness_rules['grace']['in'])->toBe(5)
        ->and($policy->work_hour_rules['daily_rounding'])->toBe(['method' => 'nearest', 'minutes' => 15])
        ->and($policy->overtime_export_rules['normal'][0]['pay_item_code'])->toBe('overtime')
        ->and($policy->payroll_defaults['currency'])->toBe('MYR');

    $component
        ->call('editPolicyGroup', $policy->id)
        ->assertSee('Identification')
        ->set('policyGraceIn', '10')
        ->call('savePolicyGroup')
        ->assertHasNoErrors();

    expect($policy->refresh()->version)->toBe(2)
        ->and($policy->lateness_rules['grace']['in'])->toBe(10);
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

    Livewire::test(Index::class, ['surface' => 'settings', 'section' => 'policies', 'mode' => 'builder'])
        ->assertSee('Templates')
        ->assertSee('Upload Template')
        ->assertDontSee('Identification')
        ->set('policyTemplateUpload', UploadedFile::fake()->createWithContent('policy-template.json', json_encode($template)))
        ->call('importPolicyTemplate')
        ->assertHasNoErrors()
        ->assertSee('Policy template uploaded into the builder.')
        ->assertSee('Identification')
        ->assertSet('policyStudioMode', 'builder')
        ->assertSet('showPolicyBuilderForm', true)
        ->assertSet('policyCode', 'JSON_POLICY')
        ->assertSet('policyGraceIn', '7')
        ->call('exportBuilderPolicyTemplate')
        ->assertSee('Policy template JSON ready to download.')
        ->assertSet('policyTemplateExportJson', fn (string $json): bool => str_contains($json, 'belimbing.attendance.policy-template.v1') && str_contains($json, 'JSON_POLICY'));
});

it('lets managers create attendance allowance rules from settings', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'STD',
        'name' => 'Standard',
        'effective_from' => '2026-01-01',
    ]);

    $this->actingAs($user);

    $component = Livewire::test(Index::class, ['surface' => 'settings', 'section' => 'allowances'])
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

it('lets managers create roster assignments from a guided roster builder', function (): void {
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

    Livewire::test(Index::class, ['surface' => 'settings', 'section' => 'rosters'])
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

it('lets managers build shift templates from guided templates', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    Livewire::test(Index::class, ['surface' => 'settings', 'section' => 'shifts'])
        ->assertSee('Templates')
        ->assertDontSee('Shift code')
        ->call('useShiftTemplate', 'night-shift')
        ->assertSee('Shift code')
        ->assertSet('shiftCode', 'NIGHT_SHIFT')
        ->call('exportBuilderShiftTemplate')
        ->assertSet('shiftTemplateExportJson', fn (string $json): bool => str_contains($json, 'belimbing.attendance.shift-template.v1') && str_contains($json, 'NIGHT_SHIFT'))
        ->set('shiftCode', 'NIGHT_MAIN')
        ->set('shiftName', 'Night Main')
        ->call('saveShiftTemplate')
        ->assertHasNoErrors()
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

    Livewire::test(Index::class, ['surface' => 'settings', 'section' => 'shifts'])
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
        ->assertSet('shiftCode', 'IMPORT_DAY')
        ->assertSet('shiftBreakStartsAt', '13:00')
        ->assertSet('shiftInWindowBeforeMinutes', '30');
});

it('uses section-specific titles for attendance settings pages', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    Livewire::test(Index::class, ['surface' => 'settings', 'section' => 'policies'])
        ->assertSee('Policy Groups')
        ->assertDontSee('Attendance Days')
        ->assertDontSee('Search employee...')
        ->assertDontSee('Settings areas')
        ->assertDontSee('Attendance Settings');

    Livewire::test(Index::class, ['surface' => 'settings', 'section' => 'shifts'])
        ->assertSee('Templates')
        ->assertSee('Maintain reusable shift times')
        ->assertDontSee('Shift Library')
        ->assertDontSee('Reset builder')
        ->assertDontSee('heroicon-m-chevron-down')
        ->assertDontSee('Attendance Days')
        ->assertDontSee('Search employee...')
        ->assertDontSee('Settings areas')
        ->assertDontSee('Attendance Settings');

    Livewire::test(Index::class, ['surface' => 'settings', 'section' => 'shift-library'])
        ->assertSee('Shift Library')
        ->assertDontSee('Templates')
        ->assertDontSee('Attendance Days')
        ->assertDontSee('Search employee...')
        ->assertDontSee('Settings areas')
        ->assertDontSee('Attendance Settings');
});

it('keeps operational timecard controls off the approvals surface', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    Livewire::test(Index::class, ['surface' => 'approvals'])
        ->assertSee('Overtime Queue')
        ->assertDontSee('Attendance Days')
        ->assertDontSee('Search employee...');
});
