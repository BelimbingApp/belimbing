# Tests Agent Guide

## Defaults

- Test baseline seeding uses `Tests\TestingBaselineSeeder`; choose included modules via `tests/Support/testing-seed-modules.php`.
- Use `setupAuthzRoles()` for authz-dependent tests and `createAdminUser()` for authenticated admin feature tests.
- Unit tests that call Laravel facades must explicitly boot `TestCase`; do not rely on earlier tests in the same process to have initialized the container.

## Isolation

- Do not let tests mutate real runtime storage such as `storage/app/ai/workspace/`, `storage/app/ai/wire-logs/`, or `storage/app/browser-artifacts/`. Point the code under test at a test-specific path and clean up only that path.
- Prefer test roots under `storage/framework/testing/` over ad hoc `storage/temp/...`. Some tool path guards require a different isolated repo-local tmp root — respect the production allow/deny policy of the thing under test.

## Value

- The codebase is first-class; tests are support infrastructure.
- Add or keep tests only when they stop a specific bad code change. If that change is vague, the test is probably not worth its cost.
- Be skeptical of happy-path-only, smoke-only, or markup-only tests, especially when they restate framework behavior.
- If a test often needs repair while production behavior stays stable, treat it as a test-design smell and rewrite or delete it.
- When a test fails after your change, treat it as a question first: is it catching a real regression? Only adjust the test once you understand why it is red.
- Delete weak tests on sight. You do not need to wait for them to break twice.
- Prove regression-test value by reproducing the pre-fix bug or by applying a narrow temporary mutation to production code. Do not strengthen the test first and present that as proof.

## Assertion Strength

Assert the real contract, not a proxy:

- **Persistence flows:** assert the persisted fields, not just redirect success. For password create/update, assert `Hash::check(...)` against the stored hash.
- **OAuth / callback flows:** assert durable pending-state cleanup and the flashed user state, not only the redirect and stored tokens.
- **Scopes and filters:** assert the exact included and excluded records — count-only assertions hide scope/result-shape bugs. For toggle/delete flows, assert which record survives or is removed.
- **Scope-aware writes:** assert the value lands in the most specific intended scope and does not leak into broader fallback scopes. When scope resolution has a most-specific branch and a fallback branch, cover both.
- **Policy contracts:** when a contract supports `allow`, `deny`, and `degrade`, cover the degrade path explicitly.
- **Middleware / boundary guards:** assert the concrete abort status and that downstream handlers do not run. When the boundary service receives context, assert that context too.
- **Download / attachment routes:** assert the resolved file/disposition boundary and a concrete missing-file path. `200 OK` alone does not protect lookup logic.
- **Replay / resume endpoints:** assert the exact boundary (`after_seq`, cursor, page token), not only that some early event types are missing.
- **Blade components:** prefer ordering and disclosure boundaries (authz-gated diagnostics, banner/meta order) over asserting Alpine `x-show` attributes.
- **Avoid ambient `assertSee()`** that only checks headings, generic status words, and one record string. Use distinctive fixtures with explicit included/excluded assertions.
- **Reflection-based tests:** when reaching a private helper by reflection, also include a small public-contract assertion when practical.

## Common Pitfalls

- **Form-component reset behavior:** shared form components may reset dependent fields when a parent selector changes. Follow the real interaction order before claiming a persistence bug.
- **Mock-heavy ≠ weak:** AI and authz boundaries are legitimately boundary-heavy and can still be behavior-oriented.
- **Blanket `markTestSkipped()` files** with vague CI comments: recheck before accepting them. Some run cleanly and are dead weight while skipped.

## Keep Tests Lean

- Extract repeated setup, fixtures, and payload builders into `tests/Support/` or `tests/Pest.php` once repetition obscures intent.
- File-level Pest `const` names are global; keep them unique across the suite.
