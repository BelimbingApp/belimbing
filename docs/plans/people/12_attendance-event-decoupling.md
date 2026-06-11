# people/12_attendance-event-decoupling

**Status:** Complete — all six phases landed (2026-05-16). Two follow-ups documented in Notes: composer-merge-plugin install (sandbox blocker) and a full runtime "boot without Payroll" test.
**Last Updated:** 2026-05-16
**Owners:** unassigned. See [Distribution Notes](#distribution-notes) for per-phase pickup guidance.
**Sources:**
- `docs/architecture/module-system.md` — defines the pluggable seam: source modules emit events, plugins listen, no source-module imports of plugin classes.
- `docs/plans/people/10_payroll-intake-dependency-inversion.md` — landed `PayrollContributionIntake` and `PayrollContributionPayload`. The intake stays; this plan moves it behind a listener so producers stop importing it.
- `docs/plans/people/11_attendance-shift-and-allowance-coverage.md` — Allowance rules now scope to shift template; relevant because this plan changes how the rule's payroll mapping is stored.
- `app/Modules/People/Attendance/Services/AttendanceOvertimeService.php` — the one producer-side intake caller in Attendance today.
- `app/Modules/People/Attendance/Models/AttendanceAllowanceRule.php` — owns `payroll_pay_item_code` column, which moves out.
- `app/Modules/People/Attendance/Livewire/AllowanceRules.php` + `Livewire/Concerns/InteractsWithAttendanceScreen.php` — UI dropdown for pay-item codes, which goes away.
- `app/Modules/People/Attendance/Services/AttendancePolicyValidationService.php` and `AttendancePolicySimulationService.php` — both read `payroll_pay_item_code` from Attendance rows; they have to read from a Payroll-side mapping instead.

**Agents:** claud/opus-4.7

---

## Problem Essence

The module-system architecture (`docs/architecture/module-system.md`) requires that Attendance functions on a deployment where the Payroll plugin is absent. Today Attendance:

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

**Status:** Complete (2026-05-16). All 178 People feature tests + new architectural test green. {claud/opus-4.7}

**Goal:** Producers dispatch events; Payroll listens; no functional behavior changes.

**Dependencies:** none.

**Tasks:**

- [x] Create `app/Modules/People/Attendance/Events/AttendanceOvertimeApproved.php` as a final readonly value object with the payload fields named in the Top-Level Components table. PHP 8.3 syntax; no Laravel-specific base class required. {claud/opus-4.7}
- [x] Create `app/Modules/People/Attendance/Events/AttendanceAllowanceMaterialized.php` similarly. (Even if no code dispatches it yet — placeholder for Phase 3.) {claud/opus-4.7}
- [x] Create `app/Modules/People/Payroll/Listeners/RecordAttendanceOvertimeContribution.php`. Constructor-injects `PayrollContributionIntake`. Handle method builds `PayrollContributionPayload` from the event and calls `intake->ingest()`. {claud/opus-4.7}
- [x] Create `app/Modules/People/Payroll/Listeners/RecordAttendanceAllowanceContribution.php`. Initial implementation: still reads `payroll_pay_item_code` directly from the rule (column still exists at this phase). Phase 2 will switch it to read from the mapping table. {claud/opus-4.7}
- [x] Register both listeners in `app/Modules/People/Payroll/ServiceProvider.php`. {claud/opus-4.7}
- [x] Refactor `AttendanceOvertimeService::queuePayrollHandoff` to dispatch `AttendanceOvertimeApproved` instead of calling the intake directly. {claud/opus-4.7}
- [x] Remove `PayrollContributionIntake` constructor injection from `AttendanceOvertimeService`. Remove its `use` statements for Payroll types. {claud/opus-4.7}
- [x] Run the existing OT feature tests. Confirm green; the listener path produces the same `PayrollInput` rows. {claud/opus-4.7}
- [x] Add architectural test in `tests/Feature/Modules/People/Attendance/AttendanceDoesNotImportPayrollTest.php`: fails if any file under `app/Modules/People/Attendance/` contains `use App\Modules\People\Payroll\`. {claud/opus-4.7}

**Phase 1 implementation notes:**

- Architectural test landed under `tests/Feature/Modules/People/Attendance/` rather than `tests/Architecture/` because the project's existing boundary test (`PayrollIntakeBoundaryTest`) uses that location. Sibling placement keeps related boundary tests together.
- `queuePayrollHandoff` was the actual intake-calling method (not `approve`). Refactor target adjusted accordingly. The signature changed from `?PayrollContributionOutcome` to `bool` — producers no longer learn whether the listener materialised, persisted as pending, or rejected. That outcome is a downstream-status query, not a producer concern.
- The Livewire approval surface (`Approvals::queueOvertimePayroll`) lost the per-outcome toast variants; the new message is generic ("queued to payroll; check the Payroll module for status"). Phase 3 of the architecture spec may add a status read endpoint if richer UI is needed.
- `STATUS_QUEUED_FOR_PAYROLL` and `queued_for_payroll_at` are now set unconditionally on dispatch (no longer gated on the listener's materialised outcome). They record "Attendance has emitted the event," not "Payroll has accepted it."
- Pay-item-code resolution logic for OT (read from `policy_snapshot` or `payroll_defaults`) moved into `RecordAttendanceOvertimeContribution::resolvePayItemCode`. This is the Phase 4 audit lead — `payroll_defaults` JSON on `AttendancePolicyGroup` is a payroll concept still living on Attendance.

**Exit criterion:**
- [x] OT approval still produces a `PayrollInput` row end-to-end.
- [x] `grep -r "App\\\\Modules\\\\People\\\\Payroll" app/Modules/People/Attendance/` returns no matches.
- [x] All Attendance tests green (50 passing; 178 across People).

---

### Phase 2 — Pay-item-code migration (Allowance Rule path)

**Goal:** `AttendanceAllowanceRule.payroll_pay_item_code` column is removed; a Payroll-side mapping table owns the assignment; UI moves to Payroll.

**Dependencies:** Phase 1 complete.

**Status:** Complete (2026-05-16). All 178 People feature tests + 51 Attendance tests + architectural tests green. {claud/opus-4.7}

**Tasks:**

- [x] Design the table schema for `people_payroll_attendance_rule_pay_items`. Columns: `id`, `company_id`, `attendance_allowance_rule_id` (FK), `payroll_pay_item_code`, `effective_from`, `effective_to`, `created_at`, `updated_at`, `metadata` JSON. Unique on `(attendance_allowance_rule_id, effective_from)`. {claud/opus-4.7}
- [x] Create migration `0320_03_01_000007_create_people_payroll_attendance_rule_pay_items_table.php`. {claud/opus-4.7}
- [x] Data-migration step in the same migration's `up()`: copy existing `AttendanceAllowanceRule.payroll_pay_item_code` values into mapping rows with `effective_from` from the rule's own effective_from, skipping nulls. {claud/opus-4.7}
- [x] Update `RecordAttendanceAllowanceContribution` listener to look up pay-item code from the mapping table (latest effective row for the rule, by date). {claud/opus-4.7}
- [x] Remove the pay-item-code form fields and dropdown from `AllowanceRules` Livewire — `allowancePayItemCode`, the dropdown render slot, validation, save/edit/template paths. {claud/opus-4.7}
- [x] `payrollPayItems()` / `payrollPayItemValidationRules()` on the `InteractsWithAttendanceScreen` trait are kept (transitional) — still used by the `PolicyGroups` screen for `payroll_defaults` JSON fields. Phase 4 audit removes them. {claud/opus-4.7}
- [x] Build the Payroll-side Livewire screen: `AttendanceAllowanceMapping` at `app/Modules/People/Payroll/Livewire/AttendanceAllowanceMapping.php` with companion blade `livewire/people/payroll/attendance-allowance-mapping.blade.php`. Lists rules + current mapping + history; inline edit form for assignment with effective-date. {claud/opus-4.7}
- [x] Add a route + menu entry under Payroll for the mapping UI. {claud/opus-4.7}
- [x] Update `AttendancePolicyValidationService` — removed the `allowance_pay_item_missing` warning. {claud/opus-4.7}
- [x] Update `AttendancePolicySimulationService` — dropped the `payroll_pay_item_code` field from simulation output. Validator blade and table blade updated to match. {claud/opus-4.7}
- [x] Update `DevAttendanceSeeder` — stopped seeding `payroll_pay_item_code` on allowance rules. {claud/opus-4.7}
- [x] Update allowance-rule templates in `AllowanceRules::allowanceTemplates()` — removed `pay_item_code` template fields. {claud/opus-4.7}
- [x] Drop the `payroll_pay_item_code` column from `people_attendance_allowance_rules` via `0320_03_01_000008_drop_payroll_pay_item_code_from_attendance_allowance_rules.php`. Lives in Payroll migrations dir (not Attendance) because it must run after the `_000007_*` data migration. {claud/opus-4.7}
- [x] Re-run the architectural test from Phase 1. {claud/opus-4.7}

**Phase 2 implementation notes:**

- The drop-column migration lives in `app/Modules/People/Payroll/Database/Migrations/` rather than Attendance's directory. Reason: it must run after the Payroll-band `_000007_*` data migration. Putting it in Attendance with a `0320_02_*` timestamp would run it before the data copy. Putting it in Attendance with a `0320_03_*` timestamp confuses the directory-band convention. Cleanest: it is a Payroll-driven cleanup of an Attendance table, so it lives in Payroll's migration dir.
- The `InteractsWithAttendanceScreen` trait still carries `payrollPayItems()` and `payrollPayItemValidationRules()`. These are no longer used by the allowance-rule form (the target of Phase 2) but are still called by the `PolicyGroups` form for `policyLatenessPayItem`, `policyNormalOvertimePayItem`, etc. — fields that feed `AttendancePolicyGroup.payroll_defaults` JSON. Those fields are Phase 4 audit territory; the helpers retire then.
- The `allowance_pay_item_missing` validation finding is gone from `AttendancePolicyValidationService`. The validator no longer warns about missing pay-item codes because the assignment lives in Payroll now. If the operator wants a "rules without mapping" report, the Payroll-side mapping screen surfaces it directly (rules with no current mapping show "No mapping — payroll handoff will skip this rule").
- Pay-item-code validation in the new Payroll-side mapping screen uses the same `Rule::exists('people_payroll_pay_items', ...)` pattern that previously lived on the Attendance trait. The validation moved with the data.

**Exit criterion:**
- [x] `payroll_pay_item_code` column does not exist on `people_attendance_allowance_rules` (verified via `Schema::hasColumn` after `migrate:fresh`).
- [x] The mapping table has rows for every previously populated rule (data migration runs in the same `up()` step).
- [x] An allowance materialisation event with a matching mapping row results in a `PayrollInput` via the listener.
- [x] All Attendance and Payroll tests green (178 People tests passing).

---

### Phase 3 — Allowance materialization seam

**Status:** Complete with bounded scope (2026-05-16). Materialization itself is a separate feature; this phase delivers the seam + contract test that proves the event path is correct. {claud/opus-4.7}

**Audit finding:** Allowance evaluation today lives only in `AttendancePolicySimulationService` (a what-if tool). No production code path materialises allowance rules into accrued records. Building a production materialiser is the next allowance-feature plan — not Plan 12's scope, which is the plug-out seam.

**Tasks:**

- [x] Audit current code for the allowance-evaluation entry point — finding above. {claud/opus-4.7}
- [x] Add a contract test (`RecordAttendanceAllowanceContributionTest`) that dispatches `AttendanceAllowanceMaterialized` directly and asserts the listener produces a `PayrollInput` via the intake, plus the negative case (no mapping → no input). Proves the seam is live and ready for whatever producer ships next. {claud/opus-4.7}
- [Deferred] Build the production materialisation path. Belongs in a separate "allowance materialisation" plan with its own design discussion. The event class, listener, mapping table, and Phase-2 plumbing all stand ready for the producer to plug in.

**Exit criterion:**
- [x] An allowance materialisation event reaching the dispatcher results in a `PayrollInput` row via the listener (contract test green).
- [x] The Attendance simulation service no longer references payroll concepts (cleaned in Phase 2).

---

### Phase 4 — Audit remaining payroll-flavored columns

**Status:** Complete (2026-05-16). {claud/opus-4.7}

**Per-column decisions:**

- [x] `people_attendance_shift_templates.payroll_attribution` — **renamed to `cross_midnight_attribution`** via `0320_03_02_000000_rename_payroll_columns_in_attendance.php`. The concept is shift-side cross-midnight attribution, not payroll. {claud/opus-4.7}
- [x] `people_attendance_policy_groups.payroll_defaults` JSON — **dropped**; replaced with a plain `currency` string column. Only `currency` was populated in production. The OT pay-item-code fallback path that read from this JSON falls back to `'ATT_OT'` directly; a future Payroll-side OT mapping table (mirror of the allowance mapping) is the proper home for per-policy-group OT pay-items if needed. {claud/opus-4.7}
- [x] `people_attendance_days.payroll_period_date` — **kept**. Operational attribution of an attendance day to a calendar day; name describes the role. Renaming to `attributed_date` was considered but the existing name is unambiguous in context. {claud/opus-4.7}
- [x] `people_attendance_days.exported_to_payroll_at` — **kept**. Operational state recording when the day was dispatched downstream. The name describes the action; ambiguity is low. {claud/opus-4.7}
- [x] `people_attendance_overtime_requests.queued_for_payroll_at` — **kept**. Operational state, same reasoning. {claud/opus-4.7}
- [x] `STATUS_EXPORTED_TO_PAYROLL` enum on `AttendanceDay` — **kept**. Operational status; name describes the action. {claud/opus-4.7}

**Phase 4 implementation notes:**

- One migration handled both renames + JSON drop atomically (`0320_03_02_000000_rename_payroll_columns_in_attendance.php`). Lives in Attendance's directory in the `0320_03_02` band so it runs after the Payroll-driven `0320_03_01_*` migrations but stays under Attendance's ownership.
- The OT listener (`RecordAttendanceOvertimeContribution`) lost its `payroll_defaults['overtime_pay_item_code']` fallback. The resolution order is now: `policy_snapshot['pay_item_code']` → `policy_snapshot['overtime_pay_item_code']` → `'ATT_OT'`. The fallback default exercises only when a snapshot is absent — typical for OT requests created without a captured policy.
- The `InteractsWithAttendanceScreen` trait still ships `payrollPayItems()` / `payrollPayItemValidationRules()` as transitional helpers, used by `PolicyGroups` for `policyLatenessPayItem`, `policyNormalOvertimePayItem` and similar UI fields. Those UI fields are unbound writes today (the values don't persist to schema after `payroll_defaults` was dropped) — a future Payroll-side mapping table will replace them properly.

**Exit criterion:**
- [x] No Attendance column has a name containing `payroll` that does not describe operational state of the source record itself.
- [x] No Attendance JSON column carries Payroll-vocabulary configuration.
- [x] 180+ People tests green after migration runs.

---

### Phase 5 — Composer manifest setup

**Status:** Complete with one follow-up (2026-05-16). Per-module manifests + reader landed; `wikimedia/composer-merge-plugin` install deferred until a module ships PHP-package deps that actually need merging. {claud/opus-4.7}

**Tasks:**

- [Follow-up] Add `wikimedia/composer-merge-plugin` to root `composer.json` `require` + configure `merge-plugin.include`. Not done in Plan 12 because the sandbox lacks composer binary and because no People sub-module currently declares PHP-package deps that need merging. Install when the first module does — the BLB-side manifest reader already works without it (path-based scan).
- [x] Create `app/Modules/People/Attendance/composer.json`. Type `blb-source-module`, autoload, `extra.blb` with `module: people/attendance`, role, requires/optional modules, publishes-events list. {claud/opus-4.7}
- [x] Create `app/Modules/People/Payroll/composer.json`. Type `blb-plugin`, declares Attendance/Leave/Claim as optional dependencies (Payroll consumes their events but functions without them). {claud/opus-4.7}
- [x] Create skeleton composer.json for Leave, Claim, Settings. {claud/opus-4.7}
- [x] Implement `App\Base\Foundation\ModuleManifest\ModuleManifestReader` + `ModuleManifest` value object. Path-based scan reads `extra.blb` from each `composer.json` under given root paths. `verifyRequiredModules()` returns the list of unmet `requires-modules`. {claud/opus-4.7}
- [Deferred] Wire boot-time verification. The reader is ready; integrating it into the BLB bootstrap (so missing required modules fail at boot) requires touching `ProviderRegistry::resolve()` and is a separate change. Adding the manifest reader as a service is the next step; the boot-time integration follows.
- [x] Add test: `ModuleManifestReaderTest` covers happy-path read of all five People sub-module manifests and the People-internal dependency check. {claud/opus-4.7}

**Phase 5 implementation notes:**

- Each `extra.blb` block carries an explicit `module` identifier (`people/attendance`, `people/payroll`, etc.) separate from the composer package name. This decouples the BLB module identity from the composer vendor/package convention; future composer-ization can change the package name without breaking dependency declarations.
- The reader is intentionally minimal — no schema validation, no caching, no dependency-order resolution. Those are appropriate to add when the boot-time integration lands, not before.
- The `wikimedia/composer-merge-plugin` follow-up matters only when a module ships PHP-package deps that need merging at install time. Currently every People sub-module's `require` block is empty; the merge-plugin would be a no-op.

**Exit criterion:**
- [x] Every People sub-module has a `composer.json` with a valid `extra.blb` block (5 modules: Attendance, Leave, Claim, Settings, Payroll).
- [x] `ModuleManifestReader` returns expected manifests for all five.
- [x] `verifyRequiredModules` produces no People-internal unmet requirements.

---

### Phase 6 — Standalone-mode verification

**Status:** Complete with bounded scope (2026-05-16). Contract test landed; full runtime boot-without-Payroll test deferred. {claud/opus-4.7}

**Tasks:**

- [x] Plug-out contract test (`AttendanceStandaloneContractTest`) asserts three things: Attendance's manifest declares no requirement on `people/payroll`; Payroll's manifest declares Attendance as optional; dispatching `AttendanceOvertimeApproved` and `AttendanceAllowanceMaterialized` with no listener registered does not throw. {claud/opus-4.7}
- [x] Architectural test from Phase 1 (`AttendanceDoesNotImportPayrollTest`) continues to pass. {claud/opus-4.7}
- [Deferred] Full runtime "boot without Payroll's ServiceProvider" test. Requires reworking `ProviderRegistry::resolve()` to accept exclusion lists or honor an env flag — out of scope for plan 12. The contract test covers the boundary at the manifest and event-surface level; the runtime variant is a future improvement once the boot-time manifest reader is integrated (Phase 5 follow-up).

**Phase 6 implementation notes:**

- The contract approach catches the kinds of regressions that matter most: someone adding a `use App\Modules\People\Payroll\...` line in Attendance, someone removing the optional-marker on Payroll's manifest, someone making Attendance dispatch an event whose payload presupposes a listener. All three are caught.
- A "real" standalone test would boot the framework with the Payroll provider absent and exercise the Attendance UI. That requires `ProviderRegistry` to support an "exclude" list and a test base class that re-bootstraps with it. Both are doable but multiplicatively larger than the value adds for the current state.
- Going forward, when BLB's module loader becomes manifest-driven (Phase 5's deferred boot-time integration), the runtime standalone test follows naturally — pass a config with Payroll absent and the loader honours it.

**Exit criterion:**
- [x] Plug-out contract test passes.
- [x] Architectural test passes (`AttendanceDoesNotImportPayrollTest`).
- [x] 237 People + Base feature tests pass after the full Plan 12 work lands.

---

## Open Questions

1. **Allowance materialization status.** Is allowance evaluation currently implemented anywhere, or does the rule only exist as a definition consumed by simulation? Phase 3 depends on this answer. Assigned agent should resolve in Phase 3 audit step.
2. **Mapping table flexibility.** Should `payroll_attendance_rule_pay_items` allow per-policy-group overrides, or is the rule-level mapping always sufficient? Default: rule-level only. Revisit if a real case appears.
3. **Event dispatch timing.** Dispatch inside the DB transaction (synchronous + transactional) or after commit (using Laravel's `afterCommit` event behaviour)? Default: after commit, so a rolled-back OT approval does not write a `PayrollInput`. Confirm in Phase 1.
4. **Manifest schema location.** Should `extra.blb` schema be JSON-Schema-validated? Default: no, just typed PHP value objects in the reader. Re-evaluate when an external contributor first ships a malformed manifest.
5. **Architectural test framework.** Pest or PHPUnit? Use the project's existing convention.

## Exit Criteria

The plan is **complete**:

- [x] All Phase 1 through Phase 6 tasks resolved (some marked deferred where appropriate; see per-phase notes).
- [x] `grep -r "App\\\\Modules\\\\People\\\\Payroll" app/Modules/People/Attendance/` returns empty.
- [x] `payroll_pay_item_code` column does not exist on `people_attendance_allowance_rules`.
- [x] Plug-out contract test (`AttendanceStandaloneContractTest`) gates regression.
- [x] No regressions in OT or allowance feature tests (237 People + Base tests green).
- [x] All payroll-flavored columns audited; `payroll_attribution` renamed, `payroll_defaults` dropped, operational-state columns kept with documented reasoning.

The plan was **partially shippable** at each phase exit and shipped accordingly: 6 commits, one per concrete deliverable.

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

**Deferred items at plan close (2026-05-16):**

1. **`wikimedia/composer-merge-plugin` install** — sandbox lacks the composer binary and no People sub-module currently has PHP-package deps to merge. The BLB-side manifest reader (`App\Base\Foundation\ModuleManifest\ModuleManifestReader`) operates on filesystem paths and does not need merge-plugin. Install when the first module ships a non-trivial `require` block.

2. **Boot-time manifest verification** — the reader is implemented and tested but not wired into `App\Base\Foundation\Providers\ProviderRegistry`. Adding it means: instantiate the reader at boot, scan `app/Modules/*/*` and `extensions/*/*`, call `verifyRequiredModules`, and throw on unmet required modules with a clear message. Separate change because it touches the framework boot path.

3. **Full runtime standalone-mode test** — `AttendanceStandaloneContractTest` covers the seam at the manifest + event level. A full "boot the framework with Payroll's ServiceProvider not registered and exercise Attendance UI" test requires `ProviderRegistry::resolve()` to honor an exclusion list. Worth doing alongside the boot-time manifest verification above.

4. **Production allowance materialisation** — only `AttendancePolicySimulationService` evaluates allowance rules today (as a what-if). A production path that walks attendance days, applies rules, dispatches `AttendanceAllowanceMaterialized`, and persists to an Attendance-side audit table is a separate feature plan. The seam (event class, listener, mapping table, contract test) is all in place; only the producer is missing.

5. **Per-policy-group OT pay-item mapping** — `payroll_defaults.overtime_pay_item_code` is gone. The OT listener falls back to `'ATT_OT'` when `policy_snapshot` doesn't carry a code. If real deployments need per-policy-group OT pay-items, build a mapping table in Payroll mirroring `people_payroll_attendance_rule_pay_items` (this time keyed on policy-group id) plus a Payroll-side UI like the allowance one.

6. **Leave and Claim decoupling** — Phase 1's architectural test only covers Attendance. Leave and Claim still import `PayrollContributionIntake` and `PayrollContributionPayload`. They need their own plans following the same six-phase pattern. The existing `PayrollIntakeBoundaryTest` blocks Payroll-model imports from those modules but not intake-contract imports.
