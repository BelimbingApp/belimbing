# people/17_claim-pay-item-mapping

**Status:** Complete (2026-05-16). {claud/opus-4.7}
**Last Updated:** 2026-05-16
**Sources:**
- `docs/plans/people/12_attendance-event-decoupling.md` Phase 2 — canonical pattern.
- `docs/plans/people/14_claim-event-decoupling.md` — established the event seam.
- `docs/plans/people/16_leave-pay-item-mapping.md` — sibling for Leave.

## Problem

After Plan 14, `ClaimType.payroll_pay_item_code` and the index on it are payroll concepts on a Claim-side table. Move them to a Payroll-owned mapping.

## Subtlety

`SubmitClaimRequestService` snapshots `ClaimType.payroll_pay_item_code` onto `ClaimLine.payroll_pay_item_code` at submission. The line snapshot is operational state per Plan 14's reasoning and stays. The submission path needs a Payroll-side lookup to source the snapshot value.

## Approach

- Create `people_payroll_claim_type_pay_items` mirroring the Leave + Attendance allowance mappings.
- Move the source-of-truth from `ClaimType.payroll_pay_item_code` to the mapping; preserve `ClaimLine.payroll_pay_item_code` as the audit snapshot.
- `SubmitClaimRequestService` reads the latest effective mapping via `DB::table` with `Schema::hasTable` guard (Claim → Payroll-table read; safe under the existing pattern).
- Drop `payroll_pay_item_code` column + index from `people_claim_types`.
- Move the maintenance UI to Payroll.

## Implementation

- Model `PayrollClaimTypePayItem`.
- Migration `0320_03_01_000011_create_people_payroll_claim_type_pay_items_table.php` creates mapping + copies from `ClaimType.payroll_pay_item_code`.
- Migration `0320_03_01_000012_drop_payroll_pay_item_code_from_claim_types.php` drops the column (and the existing index).
- `SubmitClaimRequestService` reads from the mapping via DB::table.
- `ClaimType.fillable` no longer includes `payroll_pay_item_code`.
- Claim Livewire `Index` strips the pay-item form field.
- Blade view drops the field.
- `DevClaimSeeder` migrated to seed the mapping when Payroll is installed.
- New `ClaimTypePayItemMapping` Livewire screen in Payroll + route + menu.
- Test helper updated to insert the mapping when the override is present.
