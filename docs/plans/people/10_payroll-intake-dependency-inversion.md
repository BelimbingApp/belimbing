# people/10_payroll-intake-dependency-inversion

**Status:** Complete — all phases landed. Phase 1 intake skeleton, Phase 2 schema realignment, Phases 3–5 producer rewrites (Attendance, Claim, Leave + Encashment), Phase 6 architectural test + cross-link doc, Phase 7 pending materializer hook + safety-net command. Concurrent-insert test on a real DB driver remains deferred to a future CI integration (SQLite cannot exercise true row locking).
**Last Updated:** 2026-05-13
**Sources:**
- `docs/plans/people/02_payroll-malaysia-top-level-design.md` — Payroll Core/country-pack boundary and the neutral `PayrollInput` contract that all upstream sources feed.
- `docs/plans/people/07_leave-module-design.md` — Leave module's `LeavePayrollHandoffService` and `LeaveEncashmentService` both write `PayrollInput` rows directly.
- `docs/plans/people/08_claim-module-design.md` — Claim module's `ClaimPayrollHandoffService` writes `PayrollInput::TYPE_REIMBURSEMENT` rows; open guardrails record duplicate-source race and stale-policy approval risks tied to this direction.
- `docs/plans/people/09_attendance-module-design.md` — Attendance overtime already writes to Payroll via `AttendanceOvertimeService`, and the module ships its own `attendance_payroll_handoffs` table whose composite uniqueness key prototypes the pending-store pattern this plan generalizes.
- `app/Modules/People/Claim/Services/ClaimPayrollHandoffService.php`, `app/Modules/People/Leave/Services/LeavePayrollHandoffService.php`, `app/Modules/People/Leave/Services/LeaveEncashmentService.php`, `app/Modules/People/Attendance/Services/AttendanceOvertimeService.php` — the five current producer-side writers (Claim 1, Leave 2, Attendance 1 with more to come) that import `PayrollInput`, `PayrollRun`, `PayrollRunParticipant`.
- `app/Modules/People/Attendance/Models/AttendancePayrollHandoff.php` and migration `0320_01_15_000000_create_attendance_core_tables.php:364` — module-local handoff store with composite source key, to be generalized and moved into Payroll.
- `app/Modules/People/Payroll/Models/PayrollRun.php` — actual run status vocabulary (`draft|calculated|reviewed|approved|closed|voided`) and `assertMutable` semantics (blocks only `closed|voided`).
- `app/Modules/People/Payroll/Models/PayrollInput.php` — neutral integration surface (`source_type`, `source_id`, `pay_item_code`, `input_type`, `amount`/`quantity`, `occurred_on`, `metadata`).
**Agents:** amp/claude-opus-4-7, claude/opus-4.7

## Problem Essence

Five producer-side services (Claim×1, Leave×2, Attendance×1, with Attendance period work expected to add more) currently import `PayrollInput`, `PayrollRun`, and `PayrollRunParticipant`, choose target runs, create participant rows, and reinvent duplicate protection. None of them own Payroll's invariants. Concrete symptoms:

- The duplicate `(source_type, source_id)` guard is a get-then-insert in each producer; there is no enforcing constraint because no module owns it.
- `PayrollRunParticipant` rows are created in five places.
- Attendance already invented `attendance_payroll_handoffs` as a module-local pending/landing table with a composite source key — proof that the pattern wants to exist, just in the wrong place.
- Leave's encashment writer is a second writer in the same module, easy to miss in any partial port.
- "Locked period" rules conflict between producers (who use `draft|calculated` as the writable window) and Payroll's actual mutability rule (`assertMutable` blocks only `closed|voided`).

## Desired Outcome

Payroll owns:
1. A typed payload contract (`PayrollContributionPayload`) and a single intake service (`PayrollContributionIntake::ingest`) that producers call directly inside their own transactions.
2. A durable pending store (a Payroll-owned table generalized from `attendance_payroll_handoffs`) so a contribution survives even when no open run currently covers its `occurred_on`. The intake either writes a `PayrollInput` immediately or persists the pending row and later materializes it when a run opens.
3. The composite uniqueness key — `(source_type, source_id, pay_item_code, period_anchor)` — applied at the DB level on both the pending store and `payroll_inputs`. Producers that fan out multiple pay items per source (Attendance period lines) are first-class, not edge cases.
4. The status read API (`PayrollContributionStatus`) that producers call instead of joining `payroll_inputs` themselves.

Producers (Claim, Leave including encashment, Attendance including overtime) stop importing any Payroll model. Domain events are *not* the foundation — they are an optional adapter that can sit on top of the synchronous intake call later if asynchronous handoff is ever needed.

## Top-Level Components

| Component | Responsibility | Primary owner |
|-----------|----------------|---------------|
| `PayrollContributionPayload` DTO | Typed value object describing one atomic contribution: company, employee, currency, occurred_on, pay_item_code, input_type, amount/quantity/rate, accounting snapshot, source ref `(type, id)`, period_anchor (target period date, optional), metadata. Lives in Payroll. | Payroll Core |
| `PayrollContributionIntake` service | Single entry point. Resolves target run if one is open; ensures participant; performs an atomic upsert keyed on the composite source tuple. If no run is open, persists a pending contribution row instead. Returns a structured outcome. | Payroll Core |
| `payroll_pending_contributions` table | Payroll-owned durable store for contributions that arrived before an open run exists, or that need re-materialization (e.g. reversal after run close). Composite-unique on `(source_type, source_id, pay_item_code, period_anchor)`. Generalizes the existing `attendance_payroll_handoffs` table. | Payroll Core |
| Pending materializer | Run-open hook that scans `payroll_pending_contributions` for rows whose `period_anchor` falls in the new run and writes corresponding `PayrollInput` rows. | Payroll Core |
| `PayrollContributionStatus` query | Read API returning `{ state, payroll_input_id, payroll_run_id, payroll_run_status, last_updated_at, last_event_reason }`. State vocabulary aligned to actual Payroll model: `absent`, `pending`, `queued_in_run`, `calculated`, `closed`, `voided`, `reversed`, `rejected_locked`. | Payroll Core |
| Atomic upsert path | DB-level: either `INSERT ... ON CONFLICT DO NOTHING` (PostgreSQL) / `INSERT IGNORE` + reload (MySQL), or a `try { insert } catch (UniqueViolation) { reload }` wrapper. `firstOrCreate` is explicitly insufficient because it is select-then-insert and racy. | Payroll Core |
| Producer call site | Each producer service calls `PayrollContributionIntake::ingest($payload)` inside its existing `DB::transaction`. No event indirection, no Payroll model imports. | Each producer module |
| Optional event adapter (deferred) | If asynchronous intake is later required (bulk Attendance finalize, cross-process flow), introduce a Payroll-owned event class that wraps the payload and a listener that calls intake. Not part of v1. | Deferred |

## Design Decisions

**The foundation is a synchronous Payroll-owned contract, not events.** Plan 10 v1 had producer modules owning event classes that Payroll listened to, which contradicted the "Payroll Core does not import producer classes" rule. Drop events. Producers call a Payroll service directly with a Payroll-owned DTO. The dependency direction is unambiguous: producers depend on a Payroll contract, Payroll depends on nothing producer-specific.

**The atomic unit is one pay-item contribution, not one producer request.** The source granularity must match what produces a single `PayrollInput`. Today that varies:
- Claim: one claim line → one input.
- Leave application: one leave request → one input.
- Leave encashment: one encashment event → one input.
- Attendance overtime: one OT request → one input.
- Attendance period (future): one period fans out to multiple inputs across different pay items.

The composite uniqueness key `(source_type, source_id, pay_item_code, period_anchor)` accommodates all of these. Producers whose source is naturally per-input (claim line, leave request, OT request, encashment event) get fan-out for free if they later need it. Aggregate sources (attendance period) submit multiple payloads sharing the same `source_id` but different `pay_item_code`s.

**Payroll owns a durable pending store.** Today, when no open run covers the occurred date, producers either store "pending handoff" metadata in their own request or quietly skip and rely on the producer to re-fire later. That fragments truth and conflates producer state with Payroll state. Generalize `attendance_payroll_handoffs` into `payroll_pending_contributions` owned by Payroll. The intake writes the pending row, the next opened run materializes it, and producers query `PayrollContributionStatus` instead of re-firing.

**Lock vocabulary follows Payroll, not the writer convention.** `PayrollRun::assertMutable()` is the source of truth: only `closed`/`voided` runs are immutable. The intake writes into runs in `draft|calculated` (the producer's current convention) but explicitly defines that window in Payroll, not in producers. Reviewed/approved/closed runs reject intake with `rejected_locked`, and the intake decides whether to (a) materialize into the next open run, (b) hold pending, or (c) bubble back rejection — per a policy owned by Payroll.

**Uniqueness is enforced at the DB level, not by the application.** `firstOrCreate` is select-then-insert and loses under concurrent approval. The intake uses an atomic upsert path appropriate to the driver. The plan does not pick the SQL dialect specifics in advance, but Phase 2 must demonstrate the path is genuinely atomic with a concurrent-insert test.

**Producer call sites stay synchronous and inside the producer transaction.** Today, `ApproveClaimRequestService` calls handoff inside `DB::transaction`. Move it to `PayrollContributionIntake::ingest($payload)`; identical commit semantics, identical rollback semantics. Async is not part of v1.

**Reversal is a Payroll-owned operation, called by producers via the same intake API.** Adding `PayrollContributionIntake::reverse($sourceRef, $reason)` keeps the API symmetric. Producers do not branch on payroll run state — they call reverse, and intake decides delete-pending, reverse-input, or queue-compensating-input in the next open run.

**Scope inventory is exhaustive and named.** Five writers exist today and all must be retired:
- `ClaimPayrollHandoffService` (Claim)
- `LeavePayrollHandoffService` (Leave)
- `LeaveEncashmentService` (Leave) — the writer Copilot caught
- `AttendanceOvertimeService` (Attendance)
- `AttendancePayrollHandoff` model + table (Attendance) — replaced by `payroll_pending_contributions`

The architectural test in the cleanup phase will fail if any of these still import Payroll models. That is the test's purpose.

## Public Contract

**`PayrollContributionPayload` carries:**
- `source_type` (string, namespaced, e.g. `claim_line`, `leave_request`, `leave_encashment`, `attendance_overtime`, `attendance_period_line`);
- `source_id` (int, stable);
- `pay_item_code` (required — composite key field);
- `period_anchor` (date, the occurred_on or contribution date used to pick a run; composite key field);
- `company_id`, `employee_id`, `currency`, `occurred_on`;
- `input_type` (matches `PayrollInput::TYPE_*`);
- `amount`, `quantity`, `rate` (combination per input_type);
- `accounting_snapshot` (debit/credit codes at the point the producer captured them);
- `label` for display;
- `metadata` (arbitrary producer context preserved into `payroll_inputs.metadata`);
- `idempotency_key` (defaults to `source_type:source_id:pay_item_code:period_anchor`).

**`PayrollContributionIntake::ingest` guarantees:**
- exactly one materialized `PayrollInput` row per composite key (enforced by DB unique index, verified by concurrent-insert test);
- target run resolution: first open `draft|calculated` run whose period covers `period_anchor`, in `company_id` scope;
- automatic `PayrollRunParticipant` creation;
- when no open run is available, a `payroll_pending_contributions` row is written with the same composite key — also unique-enforced — and the outcome is `pending`;
- when the resolved run is in `reviewed|approved` (mutable per `assertMutable` but past the writer window), the intake's locked-window policy applies (default: write pending row, do not mutate the run);
- when the resolved run is `closed|voided`, the outcome is `rejected_locked` and Payroll emits a structured error producers can surface;
- the function is idempotent: re-firing the same payload returns the existing materialized or pending row.

**`PayrollContributionIntake::reverse` guarantees:**
- the existing materialized row is deleted if its run is still in `draft`;
- a compensating reversal input is inserted in the next open run otherwise;
- the pending row is deleted if not yet materialized;
- the reversal action is recorded in `payroll_pending_contributions.metadata` and the status query reflects it.

**`PayrollContributionStatus::for($sourceType, $sourceId, $payItemCode = null, $periodAnchor = null)` returns:**
- `state`: `absent | pending | queued_in_run | calculated | closed | voided | reversed | rejected_locked`;
- references: `payroll_input_id`, `payroll_run_id`, `payroll_run_status`, `period_id`;
- audit: `last_updated_at`, `last_event_reason`.
- When `pay_item_code` or `period_anchor` are omitted, returns the aggregate set so a producer can show "all contributions from this leave request" without re-implementing the composite lookup.

**The contract forbids:**
- producer modules importing `PayrollInput`, `PayrollRun`, `PayrollRunParticipant`, or any Payroll service other than `PayrollContributionIntake` and `PayrollContributionStatus`;
- producer migrations referencing payroll tables for handoff (Attendance's `attendance_payroll_handoffs` is retired);
- Payroll Core importing producer-specific classes (event classes, if introduced later, are Payroll-owned wrappers around the payload).

## Risks and Guardrails

- **Risk: composite key is still wrong for some producer.** Guardrail: Phase 0 audit walks every existing writer and confirms each currently produces ≤1 `PayrollInput` per `(source_type, source_id, pay_item_code, period_anchor)` tuple. If any producer produces multiple rows per tuple, the key includes a `line_seq` field.
- **Risk: atomic upsert path lies.** Guardrail: Phase 2 includes a concurrent-insert test (two parallel transactions ingesting the same payload) that must produce exactly one materialized row. `firstOrCreate` fails this test by design.
- **Risk: pending contributions accumulate silently.** Guardrail: Payroll dashboard surfaces pending count per company; the materializer runs on every run-open and on a scheduled scan; producers see `pending` state via the status query and can surface it in their own UIs.
- **Risk: synchronous intake inside producer transaction increases failure surface.** Guardrail: intake exceptions are typed (`LockedRunException`, `MissingParticipantException`, `DuplicateSourceException`) so producers can decide whether to roll back their approval or commit it and record a `pending`/`rejected_locked` state.
- **Risk: reversal semantics differ across producers.** Guardrail: Payroll defines the three reversal outcomes (delete-pending, delete-in-draft, compensating-input) and producers do not branch. Per-producer reversal policy lives as configuration on the intake, not as producer code.
- **Risk: `AttendancePayrollHandoff` table holds in-flight data when the migration ships.** Guardrail: Phase 5 includes a one-shot data migration from `attendance_payroll_handoffs` to `payroll_pending_contributions` before dropping the old table.
- **Risk: refactor stalls halfway.** Guardrail: phases 3–5 (Claim, Leave-including-encashment, Attendance-including-overtime) land in a single release. The architectural test in Phase 6 fails if any producer still imports a Payroll model, locking the inversion in.
- **Risk: events are reintroduced opportunistically and recreate the original confusion.** Guardrail: events stay deferred. Any future event adapter is a Payroll-owned wrapper that calls the same intake; producers never own event classes that Payroll subscribes to.

## Phases

The plan adopts a **greenfield rewrite** path. No producer work is in flight, no production data exists, and `migrate:fresh --dev --seed` is the development workflow. That removes the need for a multi-step "port then retire" sequence: producer writers are rewritten in place, and `attendance_payroll_handoffs` is deleted from the Attendance migration rather than retired as a separate step.

The migration prefix reorganization (producers before consumers, with semantic tiers within People — see `docs/architecture/database.md`) lands early so every subsequent phase operates in the correctly-ordered world.

### Phase 1 — Payroll intake skeleton  ✅ DONE

- [x] `PayrollContributionPayload` DTO in `App\Modules\People\Payroll\Contracts\Intake\`. {amp/claude-opus-4-7}
- [x] `PayrollContributionState` constants aligned to `PayrollRun` status vocabulary. {amp/claude-opus-4-7}
- [x] `PayrollContributionOutcome` structured return value. {amp/claude-opus-4-7}
- [x] `PayrollPendingContribution` model and migration with composite unique index `(source_type, source_id, pay_item_code, period_anchor)`. {amp/claude-opus-4-7}
- [x] `PayrollContributionIntake::ingest()` with idempotent upsert path; locked-run handling. {amp/claude-opus-4-7}
- [x] `PayrollContributionIntake::reverse()` covering pending-not-yet-materialized, draft, calculated, and closed/voided run cases. {amp/claude-opus-4-7}
- [x] `PayrollContributionStatus::for()` / `::allFor()` read API; state derived live from current run status. {amp/claude-opus-4-7}
- [x] Test harness: 7 tests covering happy path, idempotency, no-open-run pending, locked-run rejection, reverse, status read-back, multi-pay-item per source. {amp/claude-opus-4-7}
- [x] Intake migration filename moved from `0320_01_06_000006_*` to `0320_03_01_000006_*` and table renamed to `people_payroll_pending_contributions` as part of Phase 2. {amp/claude-opus-4-7}
- [ ] **Defer to Phase 7:** concurrent-insert test (two parallel transactions). Requires a non-SQLite driver in CI to exercise real row locking.
- [ ] **Defer to Phase 7:** pending materializer hook on run open (`PayrollRun::open` lifecycle). Not needed until producers are actually emitting `pending` outcomes — currently no producer calls intake.

### Phase 2 — Schema realignment  ✅ DONE

Single atomic step covering two spec-drift fixes that both open every People migration file. Done once, before any producer rewrite.

**2a — Migration prefix reorganization** per the new tier scheme in `database.md`:

- Leave `0320_01_09_*` → `0320_02_01_*`
- Claim `0320_01_12_*` → `0320_02_03_*`
- Attendance `0320_01_15_*` → `0320_02_05_*`
- Payroll `0320_01_06_*` → `0320_03_01_*` (including the new intake migration created in Phase 1)
- Recruitment/Onboarding/Performance/Training/Disciplinary move from `0320_01_18-30_*` → `0320_02_07-15_*` if those module skeletons exist; otherwise leave the slots reserved.

**2b — Table name realignment** to match `database.md` §2 (Application domain pattern `{domain}_{module}_{entity}`). Every People table gets a `people_` prefix:

| Module | Current | Target |
|--------|---------|--------|
| Payroll | `payroll_calendars`, `payroll_periods`, `payroll_runs`, `payroll_run_participants`, `payroll_inputs`, `payroll_result_lines`, `payroll_run_audit_events` | `people_payroll_calendars`, `people_payroll_periods`, `people_payroll_runs`, `people_payroll_run_participants`, `people_payroll_inputs`, `people_payroll_result_lines`, `people_payroll_run_audit_events` |
| Payroll | `payroll_pay_items`, `payroll_pay_item_classifications`, `payroll_statutory_rule_sets`, `payroll_statutory_rule_rows`, `payroll_employer_statutory_profiles`, `payroll_employee_statutory_profiles`, `payroll_pdf_artifacts` | `people_payroll_pay_items`, `people_payroll_pay_item_classifications`, `people_payroll_statutory_rule_sets`, `people_payroll_statutory_rule_rows`, `people_payroll_employer_statutory_profiles`, `people_payroll_employee_statutory_profiles`, `people_payroll_pdf_artifacts` |
| Payroll | `payroll_pending_contributions` (created Phase 1) | `people_payroll_pending_contributions` |
| Claim | `claim_categories`, `claim_types`, `claim_policies`, `claim_policy_bands`, `claim_contexts`, `claim_assignments`, `claim_assignment_lines`, `claim_requests`, `claim_lines`, `claim_entitlement_usage_entries`, `claim_request_audit_events` | `people_claim_*` (same suffixes) |
| Leave | `leave_types`, `leave_entitlement_policies`, `leave_request_policies`, `leave_assignments`, `leave_balance_ledger_entries`, `leave_requests`, `leave_request_*`, etc. | `people_leave_*` |
| Attendance | `attendance_shift_templates`, `attendance_policy_groups`, `attendance_roster_patterns`, `attendance_roster_assignments`, `attendance_days`, `attendance_geofences`, `attendance_geofence_groups`, `attendance_clock_events`, `attendance_overtime_requests`, `attendance_absence_batches`, `attendance_absence_batch_entries` | `people_attendance_*` |
| Attendance | `attendance_payroll_handoffs` | **Deleted in Phase 3, not renamed.** |

**Migration filename convention for multi-table migrations:** when a single migration file creates several related tables for one module (the existing pattern), the entity slot in the filename is `core`. Example renames:

- `0320_01_06_000000_create_payroll_core_tables.php` → `0320_03_01_000000_create_people_payroll_core_tables.php`
- `0320_01_12_000000_create_claim_core_tables.php` → `0320_02_03_000000_create_people_claim_core_tables.php`
- The new pending-contributions migration (single-table) becomes `0320_03_01_000006_create_people_payroll_pending_contributions_table.php`.

**Sites to update**

- [x] Every migration's `Schema::create('x')`, `registerTable('x')`, and `constrained('x')` calls. {amp/claude-opus-4-7}
- [x] Every model's `protected $table = 'x'` property. {amp/claude-opus-4-7}
- [x] `assertDatabaseHas('x', …)` calls in feature/unit tests. {amp/claude-opus-4-7}
- [x] `DB::table('x')` and `DB::statement` / `DB::select` raw references in seeders, services, and reports. (No People-table refs found in raw queries.) {amp/claude-opus-4-7}
- [ ] Documentation samples in plans 07/08/09 if they cite table names literally. (Deferred — tackled when each producer is rewritten.)

**Verification**

- [x] Run `migrate:fresh --dev --seed`; verify all tables created in the new order and seeders load cleanly. Migrations apply in producer-first order without error. {amp/claude-opus-4-7}
- [x] Run the full test suite. People: 81 pass, 2 pre-existing unrelated failures (`RenderPdfJob.php` merge conflict markers). Commerce: 51 pass. Core: 105 pass, 3 pre-existing unrelated OpenAI mock failures. Registry assertion now passes after framework-level Windows path fix in `ExtractsModuleProvenance`. {amp/claude-opus-4-7}
- [x] Single atomic commit (`f0af7e87`). Branch left clean for Phase 3. {amp/claude-opus-4-7}

### Phase 3 — Rewrite Attendance writer; delete `attendance_payroll_handoffs`  ✅ DONE

- [x] Edit the Attendance core migration (now `0320_02_05_000000_*`) to remove `people_attendance_payroll_handoffs` from `up()` and its entry from `down()`. {amp/claude-opus-4-7}
- [x] Delete `app/Modules/People/Attendance/Models/AttendancePayrollHandoff.php`. {amp/claude-opus-4-7}
- [x] Rewrite `AttendanceOvertimeService::queuePayrollHandoff` to construct a `PayrollContributionPayload` and call `PayrollContributionIntake::ingest`. Source type constant `AttendanceOvertimeService::SOURCE_TYPE = 'attendance_overtime_request'`. Return type now `?PayrollContributionOutcome` (null only when payable_minutes ≤ 0). {amp/claude-opus-4-7}
- [x] Remove imports of `PayrollInput`, `PayrollRun`, `PayrollRunParticipant` from Attendance. {amp/claude-opus-4-7}
- [x] Update the `AttendanceCoreTest` overtime test ("approves overtime and queues one neutral payroll input") to assert against the new outcome shape and `PayrollInput.source_type = 'attendance_overtime_request'`. {amp/claude-opus-4-7}
- [x] Update the Attendance Livewire workbench (`queueOvertimePayroll`) to translate the new outcome states (`materialized`, `pending`, `rejected`) into user-facing flash messages. {amp/claude-opus-4-7}
- [x] Update plan 09 to record the new write path and remove references to the retired table. {amp/claude-opus-4-7}

### Phase 4 — Rewrite Claim writer  ✅ DONE

- [x] Rewrite `ClaimPayrollHandoffService::queueApprovedRequest` body to loop over approved payroll-eligible claim lines, build payloads, and call `PayrollContributionIntake::ingest`. `ClaimPayrollHandoffService::SOURCE_TYPE = 'claim_line'`; source_id is the claim line id; pay_item_code from line snapshot. Summary shape preserved (now adds `rejected` count). {amp/claude-opus-4-7}
- [x] Public `reverseRequest(ClaimRequest)` method added: iterates lines and calls `PayrollContributionIntake::reverse` for each. Not wired into a producer-facing UI flow yet — current Claim lifecycle does not expose an admin "cancel-after-approval" action; once it lands, the wiring is a one-liner. Withdraw/reject can't run after approval today, so they don't need reverse calls. {amp/claude-opus-4-7}
- [x] Verified Claim Operations export builder doesn't query `payroll_inputs` directly — it reads `metadata.payroll_handoff` summary which the new code still populates. No view changes needed. {amp/claude-opus-4-7}
- [x] Removed `PayrollInput`/`PayrollRun`/`PayrollRunParticipant` imports from Claim. {amp/claude-opus-4-7}
- [x] Update plan 08's payroll-handoff component note and duplicate-handoff risk to mark the inversion landed. (Stale-policy approval risk is orthogonal to this plan; left open in plan 08 for a future Claim-side fix.) {amp/claude-opus-4-7}

### Phase 5 — Rewrite Leave writers (handoff + encashment)  ✅ DONE

- [x] Rewrote `LeavePayrollHandoffService::onLeaveApplied` to call intake. `SOURCE_TYPE = 'leave_request'`. Return type now `?PayrollContributionOutcome`. Behaviour change: when no open run covers `starts_on`, intake records a pending row (was: silently no-op). {amp/claude-opus-4-7}
- [x] Rewrote `LeaveEncashmentService::encash` to call intake. `SOURCE_TYPE = 'leave_encashment'`. Behaviour change: no longer throws when no open run exists — the ledger entry commits and the contribution goes pending. Updated the encashment test to create the run for the current month so the existing `PayrollInput` assertion still finds the materialised row. {amp/claude-opus-4-7}
- [x] Audited `LeaveBalanceLedgerService` — no Payroll model imports or direct writes. Clean. {amp/claude-opus-4-7}
- [x] Removed `PayrollInput`/`PayrollRun`/`PayrollRunParticipant` imports from Leave. {amp/claude-opus-4-7}
- [x] Update plan 07's payroll-handoff component note and double-counting risk to mark the inversion landed. {amp/claude-opus-4-7}

### Phase 6 — Architectural lock-in  ✅ DONE

- [x] Added `tests/Feature/Modules/People/Payroll/PayrollIntakeBoundaryTest.php` that walks Leave/Claim/Attendance source files and asserts no `use App\Modules\People\Payroll\Models\*` imports. Currently green. Will fail loudly if a future agent reaches back across the boundary. {amp/claude-opus-4-7}
- [x] Authored `docs/architecture/payroll-intake.md` defining the public contract surfaces, must/must-not rules for producers, state vocabulary, idempotency guarantee, and pointer to plan 10. {amp/claude-opus-4-7}
- [ ] Update plan 02 (Payroll Malaysia top-level design) to record that producer ingestion is Payroll-owned. Deferred — plan 02 is more about country-pack boundaries than producer ingestion; not strictly required for follow-on agents. Open as a low-priority polish.

### Phase 7 — Hardening (post-rewrite)  ✅ DONE (with one deferred)

- [x] Pending materializer: `PayrollContributionIntake::materializePendingForRun(PayrollRun)` scans pending rows whose `period_anchor` falls in the run's period and re-ingests them. Wired to `PayrollRun::created` model event so it runs automatically the moment a covering run is created. Test coverage added in `PayrollContributionIntakeTest`. {amp/claude-opus-4-7}
- [x] `blb:payroll:materialize-pending` Artisan command registered in the Payroll service provider. Sweeps open runs (optionally filtered by `--company` or `--run`) and reports per-run summaries. Safe to schedule daily as a backstop. {amp/claude-opus-4-7}
- [ ] Concurrent-insert test in a driver that supports real locking (verify exactly one `people_payroll_pending_contributions` row under two parallel ingests of the same payload). SQLite-in-memory cannot exercise true row locking; this lands when CI gets a MySQL/Postgres lane. The atomic upsert path (`catch (UniqueViolation) → reload`) is in place and the DB-level unique index will enforce singleness even under contention; only the test is missing.
- [ ] Optional: Payroll-owned event class wrapping the payload as a foundation for any future async/queued intake. Do not ship without a concrete async use case.

## Open Research Before Implementation

- Should the locked-window policy (what intake does when targeting `reviewed|approved`) be a single global rule, or configurable per `input_type`? Real Finance practice is to allow reimbursements into approved runs but not deductions; check with SBG before freezing.
- Does the pending materializer need to run on a schedule (overnight scan) in addition to the run-open hook, to catch cases where a contribution arrives between scans and a run was already open? Probably yes; cheap to add.
- Does the reversal API need a "force" mode for HR/Finance correction flows (override locked-run rejection with explicit authorization)? Likely yes for Claim; the gate is authz, not intake logic.
- When Attendance period handoff lands, will it dispatch one payload per (period, employee, pay_item) or one per (period, employee)? The composite key forces the former; confirm this matches plan 09's intent.
- Should `PayrollContributionStatus` cache its lookups? Producer Operations views may query it for hundreds of rows on render.
- Is there value in a future event adapter (Phase 6 optional bullet), or is direct synchronous intake sufficient permanently? Revisit only if a real async use case emerges.
