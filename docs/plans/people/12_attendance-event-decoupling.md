# people/12_attendance-event-decoupling

**Status:** Planning — no code changes yet.
**Last Updated:** 2026-05-16
**Owners:** unassigned. See [Distribution Notes](#distribution-notes) for per-phase pickup guidance.
**Sources:**
- `docs/architecture/pluggable-modules.md` — defines the pluggable seam: source modules emit events, plugins listen, no source-module imports of plugin classes. Especially §5 (communication contracts), §6 (Payroll as the first plugin), §9 Phase 1 (in-tree work before extraction).
- `docs/plans/people/10_payroll-intake-dependency-inversion.md` — landed `PayrollContributionIntake` and `PayrollContributionPayload`. The intake stays; this plan moves it behind a listener so producers stop importing it.
- `docs/plans/people/11_attendance-shift-and-allowance-coverage.md` — Allowance rules now scope to shift template; relevant because this plan changes how the rule's payroll mapping is stored.
- `app/Modules/People/Attendance/Services/AttendanceOvertimeService.php` — the one producer-side intake caller in Attendance today.
- `app/Modules/People/Attendance/Models/AttendanceAllowanceRule.php` — owns `payroll_pay_item_code` column, which moves out.
- `app/Modules/People/Attendance/Livewire/AllowanceRules.php` + `Livewire/Concerns/InteractsWithAttendanceScreen.php` — UI dropdown for pay-item codes, which goes away.
- `app/Modules/People/Attendance/Services/AttendancePolicyValidationService.php` and `AttendancePolicySimulationService.php` — both read `payroll_pay_item_code` from Attendance rows; they have to read from a Payroll-side mapping instead.

**Agents:** claud/opus-4.7

---

## Problem Essence

The pluggable-modules architecture (`docs/architecture/pluggable-modules.md`) requires that Attendance functions on a deployment where the Payroll plugin is absent. Today Attendance:

1. **Imports Payroll classes.** `AttendanceOvertimeService` injects `PayrollContributionIntake` and references `PayrollContributionPayload` / `PayrollContributionOutcome`. Removing `app/Modules/People/Payroll/` from disk fails autoload.
2. **Stores payroll concepts on Attendance rows.** `AttendanceAllowanceRule.payroll_pay_item_code` is a string column. The pay-item taxonomy is owned by Payroll; this field is a payroll concept living on an attendance record.
3. **Embeds payroll knowledge in validation and simulation services.** `AttendancePolicyValidationService` warns when `payroll_pay_item_code` is missing; `AttendancePolicySimulationService` emits payroll-shaped output. Both presuppose payroll exists.
4. **Has no event surface.** No `Events/` directory; no event class consumed elsewhere. The producer→consumer hop is a synchronous service call.

Attendance is the most complex of the three People producers (Leave, Claim are simpler), so we land it first. Lessons learned here drive the Leave and Claim equivalents in separate plans.

## Desired Outcome

When this plan is complete:

1. Attendance dispatches public events for facts payroll may care about. No Attendance class imports anything under `App\Modules\People\Payroll\`.
2. `payroll_pay_item_code` is removed from `AttendanceAllowanceRule`. A Payroll-side mapping table owns the assignment.
3. The Attendance UI does not surface payroll-vocabulary fields.
4. The existing `PayrollContributionIntake` becomes the **internal write path** of a Payroll-side listener. It is no longer called from Attendance.
5. Each People sub-module has a `composer.json` with an `extra.blb` block declaring its required modules, optional modules, published events, and consumed events. The root project is set up with `wikimedia/composer-merge-plugin`.
6. Attendance boots and functions on a deployment where Payroll is not loaded — UI works, allowance rules can be edited, OT requests can be approved. No fatal errors; no dead listeners cause issues.

## Top-Level Components

| Component | Owner | Responsibility |
|-----------|-------|----------------|
| `Events\AttendanceOvertimeApproved` | Attendance | Producer-domain event when an OT request is approved. Payload: `companyId`, `employeeId`, `overtimeRequestId`, `occurredOn`, `payableMinutes`, `payableAmount`, `rateBasis`. |
| `Events\AttendanceAllowanceMaterialized` | Attendance | Producer-domain event when an allowance rule materializes against an attendance day. Payload: `companyId`, `employeeId`, `attendanceAllowanceRuleId`, `attendanceDayId`, `occurredOn`, `amount`. |
| `Listeners\RecordAttendanceOvertimeContribution` | Payroll | Translates the OT event into a `PayrollContributionPayload` and calls intake. |
| `Listeners\RecordAttendanceAllowanceContribution` | Payroll | Looks up the pay-item mapping for the rule, builds a payload, calls intake. |
| `payroll_attendance_rule_pay_items` table | Payroll | Maps `(company_id, attendance_allowance_rule_id) → payroll_pay_item_code` with effective dating. Replaces the column on `AttendanceAllowanceRule`. |
| Payroll-side mapping UI | Payroll | Operator screen to assign pay-item codes per attendance allowance rule. Replaces the dropdown in the Attendance allowance form. |
| Composer manifests | each sub-module | `composer.json` per `app/Modules/People/{Module}/` with `extra.blb` block. |
| Manifest reader | BLB Core | Boot-time check that `extra.blb.requires-modules` are present; logs missing optional modules. Minimum viable scope here. |
| Standalone-mode test | Attendance | Asserts Attendance boots, renders UI, and processes OT/allowance flows with Payroll's ServiceProvider not loaded. |

## Design Decisions

**D1. Events first, schema changes second, manifest setup third.** Each layer is independently shippable. Stop work after any phase and the system is still consistent.

**D2. Event payloads are producer-domain only.** No `pay_item_code` field in the event. The listener looks up the mapping itself. This is what makes the pay-item concept fully payroll-owned.

**D3. The existing intake service does not change.** `PayrollContributionIntake` and `PayrollContributionPayload` keep their current shape and guarantees. They move from "called by producers" to "called by Payroll listener." Plan 10's investment is preserved.

**D4. Allowance materialization is the event boundary, not allowance authoring.** The `AttendanceAllowanceMaterialized` event fires when a rule **matches an attendance day**, not when the rule is created or edited. If allowance materialization is not yet implemented in code (Phase 3 audits this), Phase 3 may stand up the materialization seam itself.

**D5. The pay-item mapping table is Payroll-side, keyed by attendance rule ID.** Not a generic "attribute on attendance rule." This makes it explicit that the mapping is payroll's concept and lives in payroll's schema. Foreign key to `people_attendance_allowance_rules.id` is acceptable across the module boundary because **Payroll depends on Attendance** is the legal direction.

**D6. UI moves with the data.** When `payroll_pay_item_code` leaves `AttendanceAllowanceRule`, the dropdown in `AllowanceRules.php` Livewire form is removed. The Payroll plugin provides its own UI for assigning pay items to rules. Operators are routed to the Payroll screen from the Attendance rule list (via a link, if Payroll is installed; the link is absent otherwise).

**D7. `payroll_attribution` (shift-template column) stays.** It is a scheduling concept about how shift hours map to calendar days (cross-midnight attribution), not a payroll vocabulary. It is named ambiguously but is shift-side. Rename if doing so is cheap; leave if not.

**D8. `interacts_with_payroll` and similar booleans get renamed.** Rename to a downstream-neutral term (`emits_financial_event` or `produces_payroll_input`). The flag itself stays on the source-module config record — it answers "should we dispatch an event for this thing?" which is a producer-side decision.

**D9. No event versioning yet.** First-version events have no `V2` suffix. Versioning only when a real breaking change forces it.

**D10. Architectural test enforces the boundary.** A test fails if any class under `App\Modules\People\Attendance\` imports from `App\Modules\People\Payroll\`. Test lives in `tests/Architecture/` or equivalent.

## Out of Scope (deferred to other plans)

- Leave and Claim event decoupling — separate plans, after Attendance proves the pattern.
- Payroll plugin extraction to a separate git repo — separate plan, after all three producers decouple.
- Composer-ization (Phase 4 of the architecture spec) — separate plan, well after extraction.
- Soft-fail UX dashboards — handled at plugin-extraction time.
- Country-pack mechanics inside Payroll — separate concern; this plan does not touch `PayrollStatutoryRuleSet` or `PayrollPayItemClassification`.
- Multi-plugin coexistence (running `blb-payroll-my` and `blb-payroll-sg` together) — Phase 3 of the architecture spec.

## Phases

Phases progress strictly in order: each depends on the previous being complete and merged. Multiple agents can pick up tasks within a single phase if they coordinate via this document.

### Phase 1 — Event classes and listener fan-out

**Goal:** Producers dispatch events; Payroll listens; no functional behavior changes.

**Dependencies:** none.

**Tasks:**

- [ ] Create `app/Modules/People/Attendance/Events/AttendanceOvertimeApproved.php` as a final readonly value object with the payload fields named in the Top-Level Components table. PHP 8.3 syntax; no Laravel-specific base class required.
- [ ] Create `app/Modules/People/Attendance/Events/AttendanceAllowanceMaterialized.php` similarly. (Even if no code dispatches it yet — placeholder for Phase 3.)
- [ ] Create `app/Modules/People/Payroll/Listeners/RecordAttendanceOvertimeContribution.php`. Constructor-injects `PayrollContributionIntake`. Handle method builds `PayrollContributionPayload` from the event and calls `intake->ingest()`.
- [ ] Create `app/Modules/People/Payroll/Listeners/RecordAttendanceAllowanceContribution.php`. Initial implementation: still reads `payroll_pay_item_code` directly from the rule (column still exists at this phase). Phase 2 will switch it to read from the mapping table.
- [ ] Register both listeners in `app/Modules/People/Payroll/ServiceProvider.php`.
- [ ] Refactor `AttendanceOvertimeService::approve` to dispatch `AttendanceOvertimeApproved` after the DB transaction commits, instead of calling the intake directly.
- [ ] Remove `PayrollContributionIntake` constructor injection from `AttendanceOvertimeService`. Remove its `use` statements for Payroll types.
- [ ] Run the existing OT feature tests. Confirm green; the listener path produces the same `PayrollInput` rows.
- [ ] Add architectural test in `tests/Architecture/AttendanceDoesNotImportPayrollTest.php`: fails if any file under `app/Modules/People/Attendance/` contains `use App\Modules\People\Payroll\`.

**Exit criterion:**
- OT approval still produces a `PayrollInput` row end-to-end.
- `grep -r "App\\\\Modules\\\\People\\\\Payroll" app/Modules/People/Attendance/` returns no matches.
- All Attendance tests green.

---

### Phase 2 — Pay-item-code migration (Allowance Rule path)

**Goal:** `AttendanceAllowanceRule.payroll_pay_item_code` column is removed; a Payroll-side mapping table owns the assignment; UI moves to Payroll.

**Dependencies:** Phase 1 complete.

**Tasks:**

- [ ] Design the table schema for `people_payroll_attendance_rule_pay_items`. Columns: `id`, `company_id`, `attendance_allowance_rule_id` (FK), `payroll_pay_item_code`, `effective_from`, `effective_to`, `created_at`, `updated_at`, `metadata` JSON. Unique on `(attendance_allowance_rule_id, effective_from)`.
- [ ] Create migration `0320_03_01_*_create_people_payroll_attendance_rule_pay_items_table.php` (use the next available `MM_DD` slot under the Payroll band).
- [ ] Data-migration step in the same migration's `up()`: copy existing `AttendanceAllowanceRule.payroll_pay_item_code` values into mapping rows with `effective_from = NOW()`, skipping nulls.
- [ ] Update `RecordAttendanceAllowanceContribution` listener to look up pay-item code from the mapping table (latest effective row for the rule, scoped by company).
- [ ] Remove the pay-item-code form fields and dropdown from `AllowanceRules` Livewire — `allowancePayItemCode`, `payrollPayItemValidationRules`, the dropdown render slot.
- [ ] Remove the dropdown loader `payrollPayItems()` from `InteractsWithAttendanceScreen` trait (or keep with a deprecation note for one release).
- [ ] Build the Payroll-side Livewire screen: list of Attendance allowance rules with current pay-item assignment, with edit-in-place or per-row edit. New file under `app/Modules/People/Payroll/Livewire/`.
- [ ] Add a route + menu entry under Payroll for the mapping UI.
- [ ] Update `AttendancePolicyValidationService` — remove the `allowance_pay_item_missing` warning, or move its equivalent into a Payroll-side validation surface.
- [ ] Update `AttendancePolicySimulationService` — the `payroll_pay_item_code` field in simulation output is now read from the mapping (or removed if simulation does not need it).
- [ ] Update `DevAttendanceSeeder` — stop seeding `payroll_pay_item_code` on allowance rules; if a corresponding dev seeder for Payroll needs to seed the mapping, add it there.
- [ ] Update allowance-rule templates in `AllowanceRules::allowanceTemplates()` — remove `pay_item_code` template fields, or note them as Payroll-side recommendations.
- [ ] Drop the `payroll_pay_item_code` column from `people_attendance_allowance_rules` (migration with proper down).
- [ ] Re-run the architectural test from Phase 1.

**Exit criterion:**
- `payroll_pay_item_code` column does not exist on `people_attendance_allowance_rules`.
- The mapping table has rows for every previously populated rule.
- Approving an allowance rule still results in the listener writing the correct `PayrollInput` (via the mapping table lookup).
- All Attendance and Payroll tests green.

---

### Phase 3 — Allowance materialization seam

**Goal:** When an allowance rule matches an attendance day, dispatch `AttendanceAllowanceMaterialized`. If no materialization path exists yet, this phase builds it.

**Dependencies:** Phase 2 complete.

**Tasks:**

- [ ] Audit current code for the allowance-evaluation entry point. Likely candidates: `AttendanceDayProjectionService`, the day-resolution pipeline, or a period-close step. Document the finding in this plan (update this phase's notes).
- [ ] **If materialization is implemented:** refactor the call site to dispatch `AttendanceAllowanceMaterialized` after writing the materialized row (or instead of, if the materialization is event-driven from now on).
- [ ] **If materialization is not yet implemented:** create the materialization service (`AttendanceAllowanceMaterializationService`) that walks attendance days for a period, applies allowance rules, persists results to an Attendance-owned `attendance_allowance_materializations` table, and dispatches the event for each row. The event is the public boundary; the table is Attendance's local audit.
- [ ] Confirm the existing `RecordAttendanceAllowanceContribution` listener wires into the new dispatch path correctly. End-to-end: rule matches day → event → listener → intake → `PayrollInput`.
- [ ] Add feature tests for the matching → event → input flow.

**Exit criterion:**
- An attendance day that triggers an allowance rule results in a `PayrollInput` via the event path.
- The Attendance simulation service shows the materialization without depending on Payroll.

---

### Phase 4 — Audit remaining payroll-flavored columns

**Goal:** Decide, per column, whether it stays in Attendance (operational state), moves to Payroll (configuration), or gets renamed (downstream-neutral).

**Dependencies:** none (can run in parallel with Phase 3 if owner coordinates).

**Audit list:**

- [ ] `people_attendance_shift_templates.payroll_attribution` — confirm scheduling concept; rename to `cross_midnight_attribution` (or keep, per [D7](#design-decisions)).
- [ ] `people_attendance_policy_groups.payroll_defaults` JSON — audit its consumers; if it's configuration consumed only by Payroll, move into the mapping table or a Payroll-side policy-group-extension table.
- [ ] `people_attendance_overtime_requests.payroll_period_date` — audit. Likely operational state (the period this OT was attributed to). Likely stays; rename to `attributed_period_date`.
- [ ] `people_attendance_overtime_requests.exported_to_payroll_at` — audit. Operational state recording the dispatch. Either stays (renamed to `dispatched_at` or similar) or moves into Payroll's pending-contribution row.
- [ ] `people_attendance_overtime_requests.queued_for_payroll_at` — same audit.
- [ ] Record the per-column decision in this plan's notes section before any migration ships.
- [ ] Migrations to apply decided renames/moves.

**Exit criterion:**
- No Attendance column has a name containing `payroll` that does not describe operational state of the source record itself.
- Any column that moved into Payroll has a matching schema there.

---

### Phase 5 — Composer manifest setup

**Goal:** Each sub-module has a `composer.json` with `extra.blb` declaring its module relationships. The root project resolves them via merge-plugin.

**Dependencies:** Phase 1 complete (event names known so manifests can list them).

**Tasks:**

- [ ] Add `wikimedia/composer-merge-plugin` to root `composer.json` `require`.
- [ ] Add `merge-plugin.include` config to root `composer.json`'s `extra` block, covering `app/Modules/People/*/composer.json` and `extensions/*/*/composer.json`.
- [ ] Create `app/Modules/People/Attendance/composer.json` with `name`, `type: blb-source-module`, `autoload`, and an `extra.blb` block: `requires-modules: { core/employee, core/company, settings/work-profile }`, `publishes-events: [AttendanceOvertimeApproved, AttendanceAllowanceMaterialized]`, `consumes-events: []`.
- [ ] Create `app/Modules/People/Payroll/composer.json` similarly. `type: blb-plugin`, `requires-modules: { people/attendance, people/leave, people/claim }`, `consumes-events: [<list>]`.
- [ ] Create skeleton composer.json files for Leave, Claim, Settings — even if minimal — so merge-plugin has uniform shape.
- [ ] Implement `app/Base/Foundation/ModuleManifest/` (or similar) reader that parses `extra.blb` from each loaded module's `composer.json` and exposes it via a `ModuleRegistry` singleton.
- [ ] Wire boot-time verification: if a `requires-modules` entry is missing from the registry, throw at boot with a clear message. If `optional-modules` is missing, log at info.
- [ ] Add test: with a known good install, manifest reader returns the expected module graph.

**Exit criterion:**
- `composer install` succeeds with merge-plugin active and no functional regression.
- Manifest reader returns expected `extra.blb` data for each People sub-module.
- Missing required module fails at boot with a readable error.

---

### Phase 6 — Standalone-mode verification

**Goal:** Prove Attendance boots and functions with Payroll's ServiceProvider not loaded.

**Dependencies:** Phases 1, 2, 5 complete (Phase 3 helpful, Phase 4 not blocking).

**Tasks:**

- [ ] In a feature-test setup, configure Laravel to omit `App\Modules\People\Payroll\ServiceProvider` from the registered providers list. (One approach: an env flag the test bootstrap honors.)
- [ ] Boot the app. Confirm no fatal errors.
- [ ] Smoke-test through the UI (via Laravel Dusk or Livewire test): navigate to Attendance, list allowance rules, create one, approve a draft OT request. None should fail.
- [ ] Confirm dispatched events have no listener; no error, no warning beyond debug-level.
- [ ] Confirm the architectural test from Phase 1 still passes.
- [ ] Add this as a CI test scenario: "Attendance standalone."

**Exit criterion:**
- Attendance UI loads and is fully functional without Payroll.
- Dispatched events fall on no listener and the app keeps running.
- A CI scenario gates regression.

---

## Open Questions

1. **Allowance materialization status.** Is allowance evaluation currently implemented anywhere, or does the rule only exist as a definition consumed by simulation? Phase 3 depends on this answer. Assigned agent should resolve in Phase 3 audit step.
2. **Mapping table flexibility.** Should `payroll_attendance_rule_pay_items` allow per-policy-group overrides, or is the rule-level mapping always sufficient? Default: rule-level only. Revisit if a real case appears.
3. **Event dispatch timing.** Dispatch inside the DB transaction (synchronous + transactional) or after commit (using Laravel's `afterCommit` event behaviour)? Default: after commit, so a rolled-back OT approval does not write a `PayrollInput`. Confirm in Phase 1.
4. **Manifest schema location.** Should `extra.blb` schema be JSON-Schema-validated? Default: no, just typed PHP value objects in the reader. Re-evaluate when an external contributor first ships a malformed manifest.
5. **Architectural test framework.** Pest or PHPUnit? Use the project's existing convention.

## Exit Criteria

The plan is **complete** when:

- [ ] All Phase 1 through Phase 6 tasks are checked off.
- [ ] `grep -r "App\\\\Modules\\\\People\\\\Payroll" app/Modules/People/Attendance/` returns empty.
- [ ] `payroll_pay_item_code` column does not exist on `people_attendance_allowance_rules`.
- [ ] CI "Attendance standalone" scenario passes.
- [ ] No regressions in OT or allowance feature tests.
- [ ] All payroll-flavored columns audited and renamed/moved per [Phase 4](#phase-4--audit-remaining-payroll-flavored-columns).

The plan is **partially shippable** at each phase exit: Phase 1 ships event decoupling without schema change; Phase 2 ships pay-item-code migration; etc. There is no big-bang merge.

## Distribution Notes

For agents picking up work from this plan:

- **One agent per phase** is the simplest split. Phases progress in order; pick an unowned phase and announce ownership by editing the `Owners` header at the top of this document.
- **If parallelizing within a phase**, coordinate via task-level checkboxes. Each unchecked task is a unit of work small enough for a single PR.
- **Each phase's exit criterion must pass before the next phase starts.** Do not start Phase 2 if Phase 1's architectural test is red.
- **Add findings to this document** when audits reveal something not anticipated. Use clearly-labeled "**Audit finding:**" callouts under the relevant phase rather than editing the prose.
- **Schema migrations land in their canonical Payroll slot.** The mapping table is `0320_03_*` (Payroll band), not `0320_02_*` (Attendance band).
- **Test files follow the existing convention** in `tests/Feature/Modules/People/Attendance/` and `tests/Feature/Modules/People/Payroll/`.
- **Do not commit a phase without running the full Attendance and Payroll test suites** in addition to any new tests added.
- **Do not start work on Leave or Claim decoupling** under this plan; those are separate plans (TBD).

## Notes (append as work progresses)

_(empty — agents add findings, decisions, and surprises here as phases advance.)_
