# Test Suite Audit

**Agent:** Codex
**Status:** Phases 4-5 In Progress
**Last Updated:** 2026-04-22
**Sources:** `AGENTS.md`, `docs/AGENTS.md`, `docs/plans/AGENTS.md`, `tests/AGENTS.md`, `docs/plans/test-suite-audit-rubric.md`, `docs/plans/test-suite-audit-inventory.md`, `docs/plans/ai-test-suite-audit.md`, `scripts/test-suite-audit-inventory.php`, `scripts/check-changed-tests.php`, `scripts/run-critical-mutation-checks.php`, `.github/workflows/lint.yml`, `.github/workflows/test-audit-report.yml`, `.github/pull_request_template.md`, user discussion on 2026-04-21

## Problem Essence

The repository likely carries a large test suite with mixed value: some tests protect meaningful behavior, while others mostly restate framework behavior, overfit implementation details, or rely on scaffolding that would not catch realistic regressions. This creates CI cost, review drag, Sonar noise, and false confidence.

## Desired Outcome

BLB keeps a smaller, higher-signal test suite that protects behavior and fragile contracts, deletes obvious YAGNI coverage, tightens weak-but-important tests, and applies the same standard to newly added tests. The audit process should be repeatable, visible, and cheap enough to sustain in normal development rather than as a one-off purge.

## Top-Level Components

- **Audit Rubric** — a small set of rules that forces each test into a keep, tighten, merge, or delete decision.
- **Inventory Pass** — a generated view of the current suite grouped by module, cost, and likely weakness patterns.
- **Module Audit Passes** — bounded cleanup rounds that remove low-value tests and strengthen the tests worth keeping.
- **Proof Standard** — regression-test claims must be backed by pre-fix reproduction or narrow production mutation, not by strengthening the test first.
- **CI Guardrails** — lightweight checks that review newly added or changed tests without turning every PR into a full suite-governance exercise.

## Design Decisions

### 1. Audit by module, not by individual file across the whole repo

The suite is too large for a flat file-by-file pass without losing context. The audit should move through coherent areas such as AI, database, authz, and UI-heavy feature tests. Each pass should end with an explicit outcome for that area: deleted low-value tests, tightened high-value tests, merged duplicates, and a short note on remaining risk.

### 2. Use a forced-decision rubric

Each reviewed test must land in one of four buckets:

- **Keep** — it protects a specific bad code change and already does so with acceptable signal.
- **Tighten** — the test protects the right area but has blind spots, overly optimistic doubles, or happy-path-only coverage.
- **Merge** — multiple tests cover the same contract through different scaffolding and should collapse into fewer, deeper tests.
- **Delete** — the test is smoke-only, mostly framework restatement, markup-only without a fragile contract, or otherwise not worth its CI and maintenance cost.

The key litmus: "What specific bad code change would this test stop?" If that answer is vague, the test should not survive unchanged.

### 3. Favor behavior over scaffolding

When a test remains in the suite, its primary job is to protect production behavior, domain rules, persistence, workflow transitions, authz boundaries, and fragile framework customizations. Tests that mostly validate their own mocks, fixture builders, or implementation structure should be tightened or removed. Where practical, use real collaborators inside the unit boundary and mock only true external or configuration boundaries.

### 4. Prove regression-test value with production-side failure

When a test is claimed to protect a regression, its value must be demonstrated by reproducing the bug on pre-fix code or by applying a narrow temporary mutation to production code. Strengthening the test first and then showing the stronger test fails is not valid proof. This rule should guide both audit work and reviews of new regression tests.

### 5. Keep CI policy lightweight and targeted

The first CI goal is not to automatically score every existing test. It is to stop the suite from getting worse while the audit is underway. Initial guardrails should focus on new or changed tests and on a few obvious anti-patterns: touching real runtime storage, escaping test DB isolation, and adding low-value duplicated scaffolding. Broader quality metrics such as mutation sampling, slow-test reports, and flaky-test reports should run on scheduled jobs, not block every PR.

### 6. Record progress in the master plan first, split only when an area gets large

This master plan remains the status surface for the overall program. Audit outcomes stay inline here while a module pass is still compact. When one area grows beyond a short checklist and summary, create a module-prefixed companion build sheet under `docs/plans/` and link it from this plan's `Sources` or phase notes. This keeps the program visible without creating companion files too early.

### 7. Use heuristic inventory for prioritization, not automated judgment

The inventory generator should surface concentration areas and likely weak-test shapes without pretending it can decide test value mechanically. Static signals such as Mockery volume, redirect-only assertions, markup-heavy assertions, DB refresh traits, and filesystem setup are useful for ranking candidates. They are not delete instructions. Runtime should be treated as best-effort until profiling becomes reliable in CI or scheduled jobs.

## Public Contract

The audit program should produce visible artifacts in-repo:

- a repeatable audit rubric for humans and agents
- an inventory report or script output that can be regenerated
- per-module cleanup progress tracked in this plan or linked companion files if the work grows
- a defined review standard for new tests, especially regression tests
- a CI policy that applies to newly added or changed tests before it expands further

The program should not require blanket rewrites, mass deletions without rationale, or a monolithic gate that blocks ordinary development.

## Phases

### Phase 1 — Establish Audit Rules

Goal: make the decision standard explicit before touching large parts of the suite.

- [x] Write a small audit rubric document that defines keep, tighten, merge, and delete decisions with examples from BLB
- [x] Define a short checklist for reviewing new tests, including the regression-proof rule from `tests/AGENTS.md`
- [x] Decide how audit outcomes are recorded: inline in this plan, in companion docs, or both

### Phase 2 — Generate Inventory

Goal: make the suite visible enough to prioritize by value and cost instead of by guesswork.

- [x] Build an inventory script or report that lists test files by module and suite
- [x] Include basic cost signals such as runtime, DB use, filesystem use, and mock-heavy patterns where detectable
- [x] Flag likely weak categories for manual review: smoke tests, markup-only assertions, duplicated datasets, and happy-path-only tests around error-prone code
- [x] Produce a first ranked list of audit candidates rather than attempting to review the full suite at once

### Phase 3 — Run Pilot Audit

Goal: prove the process on one high-churn, high-cost area before scaling it repo-wide.

- [x] Pick the first audit target area; current recommendation is AI tests because they mix protocol handling, UI-heavy behavior, and mock-heavy units
- [x] Review that area test by test using the forced-decision rubric
- [x] Delete obvious YAGNI coverage
- [x] Tighten weak-but-important tests by closing blind spots and adding error-path coverage where the real failures happen
- [x] Record examples of tests that were removed, merged, or improved so later passes follow the same standard

Phase 3 outcome:

- pilot area: `Modules/Core/AI`
- reviewed files recorded in [ai-test-suite-audit.md](/home/kiat/repo/laravel/blb/docs/plans/ai-test-suite-audit.md:1)
- observed all four practical outcomes needed for the rubric, including a real `delete + merge`
- fixed repeated test-isolation failures around runtime storage and one false-coverage ordering test
- left the remaining AI suite for Phase 4 expansion rather than pretending the whole module is fully audited

### Phase 4 — Expand by Module

Goal: apply the proven process to the rest of the suite without losing visibility.

- [ ] Audit remaining modules in priority order based on runtime, churn, and weakness signals
- [ ] Split this plan into companion per-area build sheets if the checklist becomes hard to use
- [ ] Keep the plan current with what was deleted, tightened, merged, or deferred
- [ ] Track residual risks where coverage is intentionally reduced but accepted
- [ ] Update the draft skill at `.agents/draft/blb-test-suite-audit/` as new audit patterns prove stable enough to keep

Current Phase 4 focus:

- treat the AI companion audit as complete at this checkpoint and continue through the next non-AI candidates surfaced by the inventory
- keep using the cheap-candidate slices to separate heuristic false positives from real tighten/delete opportunities before moving deeper into slower modules

Latest Phase 4 result:

- the expansion pass is still finding real defects, not just documentation churn
- one tightened streaming test exposed a production bug in `AgenticFinalResponseStreamer`, which is now fixed and covered
- the AI-specific companion sheet is now marked complete because the recorded AI slices are done and active audit work has moved into other modules
- a cheap-candidate auth/settings slice showed the redirect-only heuristic needs human review, not automatic downgrades
- `AuthenticationTest.php`, `EmailVerificationTest.php`, and `ProfileUpdateTest.php` reviewed as `keep`
- `PasswordResetTest.php` tightened to assert the reset actually changes the stored password, rotates the remember token, and dispatches the reset event
- `PasswordConfirmationTest.php` tightened to assert the confirmation timestamp is written to session
- both auth test tightenings were validated with narrow temporary production mutations before restoring the real code
- another cheap-candidate slice showed `RoleUiTest.php`, `ImpersonationTest.php`, and `LocalizationUiTest.php` are behavior-heavy keeps rather than smoke tests
- `CompanyUiTest.php` tightened so company creation now asserts persisted status, email, and decoded metadata instead of only code generation plus one JSON field
- the company-create tightening was validated by temporarily breaking metadata persistence in `Create.php` and confirming the focused test failed before restoring production code
- `QualityWorkflowUiTest.php` reviewed as `keep`; despite the heuristic rank, it is a mixed workflow/service regression file with concrete business-state assertions
- `RoleUiTest.php` remains a broad behavior-oriented keep overall, but its custom-role creation case was tightened to assert description persistence and redirect-to-created-role behavior
- the role-creation tightening was validated by temporarily dropping description persistence in `Roles\\Create` and confirming the focused test failed before restoring production code
- the remaining company slice reviewed `CompanyTest.php` and `CompanyTimezoneTest.php` as `keep`; both files protect real model and settings behavior with concrete persistence and hierarchy contracts
- `CompanyRelationshipTest.php` and `ExternalAccessTest.php` were tightened so their scope tests now assert the specific returned records, not just counts
- those company scope tightenings were validated by temporarily inverting `CompanyRelationship::scopeExternal()` and `ExternalAccess::scopeValid()`, then confirming the focused tests failed before restoring production code
- the remaining authz slice reviewed `AuthorizationServiceTest.php` and `AuthzRoleCapabilitySeederTest.php` as `keep`; both protect high-value policy and configuration-failure contracts rather than UI smoke
- the user feature slice reviewed `UserUiTest.php` and `PagePinningTest.php` as behavior-oriented keeps overall, but tightened their weak spots rather than leaving them as proxy assertions
- `UserUiTest.php` now proves created and updated passwords are actually persisted correctly instead of only checking redirect or no-error outcomes
- `PagePinningTest.php` now proves URL-based unpinning removes the intended pin and leaves the correct remaining record, not just a count of one
- those user-slice tightenings were validated by temporarily breaking password persistence in `Users\\Create` and mis-targeting pin deletion in `PinController`, then confirming the focused tests failed before restoring production code
- the remaining auth feature slice reviewed `RegistrationTest.php` as a keep with one tightening: it now proves the registered user is actually created with the expected password hash and emits `Registered`
- the remaining system feature slice reviewed `TransportTestUiTest.php` as `keep`; it already protects authz, SSE transport shape, and Reverb dispatch behavior with concrete assertions
- the registration tightening was validated by temporarily breaking password persistence in `Auth\\Register` and confirming the focused test failed before restoring production code
- the database feature slice reviewed `DatabaseTablesShowTest.php`, `MigrateCommandTest.php`, `QueryTest.php`, and `TableRegistryReconciliationTest.php` as `keep`; they protect real schema, provisioning, query-safety, sharing, and reconciliation contracts
- the smaller leftover feature sweep reviewed `AuditableTraitTest.php`, `BlbExceptionRenderingTest.php`, `FrameworkPrimitivesProvisionerTest.php`, and `WorkflowEngineTest.php` as `keep`
- `AddressUiTest.php` was tightened so address creation now asserts persisted label, line, locality, postcode, country normalization, and verification status
- the address tightening first exposed a form-state gotcha: changing `countryIso` resets dependent geo fields, so the test was adjusted to follow the real interaction order before assessing persistence
- the final address tightening was validated by temporarily breaking locality persistence in `Addresses\\Create` and confirming the focused test failed before restoring production code

### Phase 5 — Add CI Guardrails

Goal: stop new low-value tests from entering the suite while keeping normal development flow workable.

- [x] Add lightweight checks for changed tests only, focused on obvious anti-patterns and test-isolation failures
- [x] Add a review standard for new regression tests so authors must state what bad code change the test is meant to stop
- [x] Add scheduled reporting for slow tests, flaky tests, and selected mutation-style checks on critical modules
- [ ] Revisit whether any guardrail should graduate from scheduled reporting to PR blocking after the audit baseline is healthier

Current Phase 5 focus:

- keep the existing CI guardrails narrow until more of the legacy suite has been audited
- revisit whether any scheduled signals are trustworthy enough to promote into PR-blocking checks once the baseline is cleaner

Latest Phase 5 result:

- added a PR-only changed-test guardrail in `lint.yml`
- added `scripts/check-changed-tests.php` to fail on known runtime-storage violations and annotate suspicious literal `storage/app/` paths in changed tests
- added a PR template section that requires authors to explain what a new or changed regression test is meant to stop and how its value was proven
- added a weekly `test-audit-report` workflow that uploads a slow-test report and a curated mutation-check report for critical AI regressions
- flaky reporting is still deferred because the current toolchain does not provide a trustworthy automatic flaky-test signal to consume
