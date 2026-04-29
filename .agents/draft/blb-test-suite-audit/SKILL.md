---
name: blb-test-suite-audit
description: >
  Draft skill for auditing BLB tests for value, isolation, and regression-proof
  quality. Use when reviewing, trimming, tightening, or validating BLB tests,
  especially when deciding keep versus tighten versus merge versus delete.
  This draft should remain outside the active skills directory until the audit
  workflow stabilizes.
---

# BLB Test Suite Audit

Draft workflow for auditing BLB tests without treating the skill as finished.
Keep this folder under `.agents/draft/` until the process is stable enough to
move into `.agents/skills/`.

## Load First

- `tests/AGENTS.md`
- `docs/plans/test-suite-audit.md`
- `docs/plans/test-suite-audit-rubric.md`
- `docs/plans/test-suite-audit-inventory.md` when choosing the next slice

## Core Rules

- The codebase is first-class; tests are support infrastructure.
- Force each reviewed file into one disposition: `keep`, `tighten`, `merge`, or
  `delete`.
- Ask: what specific bad code change would this test stop? If the answer is
  vague, the test is probably weak.
- Treat inventory heuristics as ranking only, never as automatic verdicts.
- Prefer module slices over jumping file-to-file across the repo.
- Do not claim regression-test value by strengthening the test first. Prove it
  by reproducing the pre-fix bug or by applying a narrow temporary mutation to
  production code.
- Do not let tests mutate real runtime storage or escape the isolated test DB.

## Common Audit Outcomes

- `keep`: the test already protects a concrete behavior, policy, persistence
  rule, workflow transition, or fragile framework customization.
- `tighten`: the test protects the right area but under-asserts its contract,
  relies on happy-path-only coverage, or hides scope/result-shape bugs behind
  count-only assertions.
- `merge`: multiple tests protect the same contract through different
  scaffolding and should collapse into fewer deeper cases.
- `delete`: smoke-only, markup-only, framework-restatement, or duplicate tests
  that are not worth their CI and maintenance cost.

## BLB-Specific Gotchas

- Runtime-storage violations are real here: watch for writes under
  `storage/app/ai/workspace/`, `storage/app/ai/wire-logs/`,
  `storage/app/browser-artifacts/`, and similar default runtime paths.
- For filesystem helpers and support utilities, prefer test roots under
  `storage/framework/testing/` instead of ad hoc `storage/temp/...` paths.
- Shared form components may reset dependent fields when a parent selector
  changes. Follow the real interaction order in tests before claiming a
  persistence bug.
- Middleware denial tests should assert the exact abort status and that
  downstream handlers do not run. "Throws some HTTP exception" is usually too
  weak to protect the boundary.
- When scope resolution has a most-specific branch and a fallback branch, add
  tests for both. Company-only coverage will miss employee-scope regressions.
- If an existing test reaches a private helper by reflection, look for a small
  public-contract assertion too when practical so the file protects behavior,
  not only implementation shape.
- Delete stock starter tests and unused global Pest helpers when they are still
  hanging around. They add CI cost and noise without protecting BLB behavior.
- Recheck blanket `markTestSkipped()` files with vague CI comments before
  accepting them as necessary. Some turn out to run cleanly and are just dead
  weight while skipped.
- When a contract supports `allow`, `deny`, and `degrade`, cover the degrade
  path explicitly. Allow-or-deny-only tests miss real policy bugs.
- Unit tests that call Laravel facades should explicitly boot `TestCase`
  instead of relying on earlier tests in the same process to have initialized
  the container.
- Count-only scope assertions are often weak. Prefer asserting the exact
  returned records and excluded records.
- For filesystem-isolation fixes, respect the production allow/deny policy of
  the thing under test. `storage/framework/testing/` is preferred in general,
  but tool path guards may require a different isolated repo-local tmp root.
- Mock-heavy files are not automatically weak; many AI and authz boundaries are
  legitimately boundary-heavy and still behavior-oriented.
- Cheap-candidate heuristics such as `redirect-only` and `smoke-or-markup` have
  already produced false positives. Read the file before deciding.

## Audit Workflow

1. Pick a coherent slice from the inventory or the current module plan.
2. Read the tests and the production code they claim to protect.
3. Decide `keep`, `tighten`, `merge`, or `delete` for each file.
4. If tightening, change the test to assert the real contract, not just a proxy.
5. Validate with focused tests.
6. Prove regression value with a narrow temporary production mutation when the
   test is being presented as meaningful regression coverage.
7. Restore production code after the proof.
8. Update the visible plan docs so the audit status stays truthful.

## Good Tightening Patterns

- Assert persisted fields, not just redirect success.
- For password create/update flows, assert `Hash::check(...)` against the stored
  hash instead of treating component success as proof.
- Assert session or event side effects, not just component success.
- For OAuth and callback flows, assert durable pending-state cleanup and the
  flashed user state, not just the redirect and stored tokens.
- For middleware and boundary guards, assert the concrete status code and the
  context handed to the boundary service when that context is part of the
  contract.
- For scope-aware settings or preference writes, assert the value lands in the
  most specific intended scope and does not leak into broader fallback scopes.
- Assert exact included and excluded records for scopes and filters.
- For toggle/delete flows, assert which record survives or is removed, not just
  the remaining count.
- Assert branch-specific dataset values instead of hard-coded defaults that can
  hide routing mistakes.
- Redirect filesystem writes to isolated testing paths instead of default
  runtime directories.

## Stop Conditions

- Stop and ask the user if the next change would require changing Laravel
  internals or BLB's framework-level divergence.
- Stop and ask if the production mutation needed for proof would be risky or
  hard to restore cleanly.
- Otherwise, prefer carrying the slice through review, edits, proof, and plan
  updates in one pass.
