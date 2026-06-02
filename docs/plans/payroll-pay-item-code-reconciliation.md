# payroll-pay-item-code-reconciliation

**Status:** Complete
**Last Updated:** 2026-06-02
**Sources:** `docs/plans/framework-modernization.md` (Phase 1 — where `preventSilentlyDiscardingAttributes` is deferred to this); Payroll drop migrations `0320_03_01_000008_drop_payroll_pay_item_code_from_attendance_allowance_rules.php` and `0320_03_01_000012_drop_payroll_pay_item_code_from_claim_types.php` (Payroll Plan 12 Phase 2 / Plan 17); mapping tables `0320_03_01_000007` / `0320_03_01_000011`; `ClaimAccountingExportBuilder`; surfaced by Eloquent strict mode.
**Agents:** claude/opus-4.8 (identification only); GPT-5.5/gpt-5.5 (implementation)

## Problem Essence

The Payroll module **intentionally removed** the `payroll_pay_item_code` column from `people_attendance_allowance_rules` and `people_claim_types` (migrations `_000008` / `_000012`), having first copied the mapping into dedicated tables `people_payroll_attendance_rule_pay_items` and `people_payroll_claim_type_pay_items` (migrations `_000007` / `_000011`, models `PayrollAttendanceRulePayItem` / `PayrollClaimTypePayItem`). But several Attendance/Claim callers still **mass-assign `payroll_pay_item_code` to `AttendanceAllowanceRule` / `ClaimType`**, where it is now silently discarded — a real data-loss bug in payroll/accounting paths, and the blocker for enabling Eloquent's `preventSilentlyDiscardingAttributes` guardrail.

## Desired Outcome

No code assigns or reads `payroll_pay_item_code` on `AttendanceAllowanceRule` / `ClaimType`; the pay-item mapping is sourced exclusively from the dedicated mapping tables/models; the accounting and operations exports produce correct pay-item codes; and `preventSilentlyDiscardingAttributes` is enabled (it is currently deferred in `AppServiceProvider::configureModels()` solely because of this).

## Design Decisions

- **The mapping tables are authoritative.** `payroll_pay_item_code` now belongs to `people_payroll_attendance_rule_pay_items` / `people_payroll_claim_type_pay_items` (keyed by the rule / claim-type id). Attendance and Claim domain models must not carry the field.
- **Separate stale from correct usage.** Of the ~36 files referencing `payroll_pay_item_code`, most are legitimate Payroll mapping usage (the mapping models, the `*PayItemMapping` Livewire components/views, the mapping migrations) or `ClaimLine` snapshot usage. The stale in-scope sites were `AttendancePolicyOperationsTest` assigning the dropped allowance-rule column, `ClaimAccountingExportTest` assigning the dropped claim-type column, and `DevClaimSeeder` using the dropped column name in claim-type seed definitions. `ClaimAccountingExportBuilder`, `ClaimOperationsExportBuilder`, and claim payroll handoff read `ClaimLine.payroll_pay_item_code`, which remains the submitted-line snapshot and is legitimate.
- **Preserve export output.** The accounting/operations exports exist to emit pay-item codes. Claim submission resolves Payroll's `PayrollClaimTypePayItem` mapping by claim-type id and snapshots the code onto `ClaimLine`, and the accounting export continues to read that snapshot so historical exports do not drift when mappings change.
- **Cross-module care.** This reconciles Attendance/Claim callers against the Payroll module's design; do not re-add the dropped column (that fights Plan 12/17 and only converts the silent drop into a SQL error).

## Public Contract

`payroll_pay_item_code` is owned by the Payroll pay-item mapping tables. Attendance and Claim models, factories, and views must neither set nor read it directly.

## Phases

### Phase 1 — Inventory the stale references

- [x] From the `payroll_pay_item_code` usages, list exactly which sites assign/read it on `AttendanceAllowanceRule` / `ClaimType` (or render `$rule->payroll_pay_item_code` / `$claimType->payroll_pay_item_code` in a view), separating them from the legitimate mapping-table usage. {GPT-5.5/gpt-5.5}

### Phase 2 — Redirect the callers

- [x] Update each stale caller to resolve the pay-item code via `PayrollAttendanceRulePayItem` / `PayrollClaimTypePayItem` (by rule / claim-type id), preserving current export output. {GPT-5.5/gpt-5.5}
- [x] Update the affected tests/factories to seed the mapping rows instead of assigning the dropped column. {GPT-5.5/gpt-5.5}

### Phase 3 — Enable the guardrail

- [x] Enable `preventSilentlyDiscardingAttributes` in `AppServiceProvider::configureModels()` (alongside the already-enabled `preventLazyLoading` + `preventAccessingMissingAttributes`). {GPT-5.5/gpt-5.5}
- [x] Run the full suite with the project ini (`PHPRC` → sodium): confirm green and zero `MassAssignmentException`. {GPT-5.5/gpt-5.5}
