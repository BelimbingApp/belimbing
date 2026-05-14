<?php

use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Services\ClaimAccountingExportBuilder;

require_once __DIR__.'/ClaimPolicyEvaluationTest.php';

it('exports one row per claim line with accounting metadata', function () {
    $f = makeClaimFixture();
    $f['type']->update([
        'payroll_pay_item_code' => 'REIMB_MED',
        'debit_account_code' => '5400',
        'credit_account_code' => '2100',
        'taxability_hint' => 'exempt_medical',
    ]);

    $request = makeClaimWith($f, ClaimRequest::STATUS_APPROVED, requested: 200.00, approved: 150.00);
    // Copy the accounting fields onto the line (simulating submission's snapshot)
    $line = $request->lines()->first();
    $line->update([
        'payroll_pay_item_code' => 'REIMB_MED',
        'debit_account_code' => '5400',
        'credit_account_code' => '2100',
        'attachment_count' => 1,
    ]);
    $request->load(['employee.department', 'lines.type']);

    $export = app(ClaimAccountingExportBuilder::class)->csv([$request]);

    expect($export['filename'])->toBe('claim-accounting.csv');
    $lines = array_filter(explode("\n", $export['content']));
    expect($lines)->toHaveCount(2); // header + 1 line

    $header = str_getcsv($lines[0]);
    $row = str_getcsv($lines[1]);
    $byKey = array_combine($header, $row);

    expect($byKey['reference_number'])->toBe($request->reference_number);
    expect($byKey['claim_type_code'])->toBe($f['type']->code);
    expect($byKey['payroll_pay_item_code'])->toBe('REIMB_MED');
    expect($byKey['debit_account_code'])->toBe('5400');
    expect($byKey['credit_account_code'])->toBe('2100');
    expect($byKey['taxability_hint'])->toBe('exempt_medical');
    expect($byKey['receipt_state'])->toBe('has_receipt');
    expect($byKey['settlement_state'])->toBe('approved');
    expect($byKey['approved_amount'])->toBe('150.00');
});

it('emits one row per line for multi-line requests', function () {
    $f = makeClaimFixture();
    $request = makeClaimWith($f, ClaimRequest::STATUS_REIMBURSED, requested: 50.00, approved: 50.00);

    // Add a second line by hand
    $request->lines()->create([
        'claim_type_id' => $f['type']->id,
        'claim_policy_id' => $f['policy']->id,
        'claim_assignment_line_id' => $f['line']->id,
        'incurred_on' => '2026-06-11',
        'unit' => 'amount',
        'quantity' => 1,
        'requested_amount' => 80.00,
        'approved_amount' => 80.00,
        'reimbursed_amount' => 80.00,
        'currency' => 'MYR',
    ]);
    $request->load(['employee.department', 'lines.type']);

    $export = app(ClaimAccountingExportBuilder::class)->csv([$request]);
    $lines = array_filter(explode("\n", $export['content']));
    expect($lines)->toHaveCount(3); // header + 2 lines
});
