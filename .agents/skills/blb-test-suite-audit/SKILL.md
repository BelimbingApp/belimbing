---
name: blb-test-suite-audit
description: >
  Audits an existing slice of the BLB test suite for value, isolation, and
  regression-proof quality, forcing each file into a keep, tighten, merge, or
  delete disposition with proof. Use when the user asks to audit, sweep, trim,
  prune, review, or clean up tests, or when deciding whether to keep or delete
  weak or flaky tests. Do not use when writing new tests for a feature —
  follow tests/AGENTS.md for that.
---

# BLB Test Suite Audit

For write-time guidance (assertion strength, common pitfalls, isolation),
follow `tests/AGENTS.md`; this skill does not restate it.

## Load First

- `tests/AGENTS.md` — the write-time rules this audit enforces.
- `references/RUBRIC.md` — the disposition rubric.

## Dispositions

Force each reviewed file into one of:

- `keep` — already protects a concrete behavior, policy, persistence rule,
  workflow transition, or fragile framework customization.
- `tighten` — protects the right area but under-asserts its contract, relies on
  happy-path-only coverage, or hides scope/result-shape bugs behind count-only
  assertions. Tighten using the patterns in `tests/AGENTS.md § Assertion Strength`.
- `merge` — multiple tests protect the same contract through different
  scaffolding and should collapse into fewer deeper cases.
- `delete` — smoke-only, markup-only, framework-restatement, or duplicate tests
  not worth their CI and maintenance cost.

## Audit Workflow

1. Pick a coherent slice. If the user names one, use it. Otherwise, default
   to tests modified in the last 3 days (`find tests -name '*.php' -mtime -3`),
   excluding support/infrastructure files (`Pest.php`, `TestCase.php`,
   `TestingBaselineSeeder.php`, `Support/`). State the file list before
   proceeding. If that window yields more than ~50 files, narrow to 3 days
   within the most active sub-area. Prefer coherent module slices over
   jumping file-to-file across the repo.
2. Read the tests and the production code they claim to protect.
3. For each file, ask: what specific bad code change would this test stop? If
   the answer is vague, lean toward `tighten` or `delete`.
4. Decide `keep`, `tighten`, `merge`, or `delete`.
5. If tightening, change the test to assert the real contract, not just a proxy.
6. Validate with focused tests.
7. Prove regression value with a narrow temporary production mutation when the
   test is being presented as meaningful regression coverage. Do not strengthen
   the test first and present that as proof.
8. Restore production code after the proof.
9. Capture the slice's audit in a plan doc under `docs/plans/` per
   `docs/plans/AGENTS.md` (status, per-file dispositions, follow-ups). New
   slices get their own plan; do not extend a closed one.

## Heuristics, Not Verdicts

When scanning for audit candidates, these labels are ranking signals only.
Always read the file before deciding — cheap-candidate labels have produced
false positives.

- `redirect-only` — dominated by redirect assertions without broader behavior
  checks.
- `smoke-or-markup` — multiple `assertSee()` checks with no stronger
  interaction signals.
- `mock-heavy` — large Mockery / `shouldReceive()` volume. Often still valid;
  AI and authz boundaries are legitimately boundary-heavy and behavior-oriented.
- `happy-path-http` — fakes HTTP but shows no obvious error-path assertions.
- `filesystem-sensitive` — creates and cleans up temporary storage paths. Not
  bad by itself; check against the runtime-storage rule in `tests/AGENTS.md`.

## Stop Conditions

- Stop and ask the user if the next change would require modifying Laravel
  internals or BLB's framework-level divergence.
- Stop and ask if the production mutation needed for proof would be risky or
  hard to restore cleanly.
- Otherwise, carry the slice through review, edits, proof, and plan updates in
  one pass.
