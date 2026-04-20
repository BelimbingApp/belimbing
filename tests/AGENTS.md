# Tests Agent Guide

## Test Baseline Seeding

- The test suite uses `Tests\TestingBaselineSeeder`.
- The source of truth for which modules are seeded in tests is `tests/Support/testing-seed-modules.php`.
- Add a module name (for example, `'Authz'`, `'Company'`) to include its production seeders in test baseline.
- Remove a module name to exclude its production seeders from test baseline (for example, heavy or network-bound seeders).

## Shared Test Helpers

- Feature tests are bound in `tests/Pest.php` to `Tests\TestCase` and use `Illuminate\Foundation\Testing\RefreshDatabase`.
- Use the global helper `setupAuthzRoles()` when a test depends on configured system roles and capabilities.
- Use the global helper `createAdminUser()` when a feature test needs an authenticated admin with the `core_admin` role already assigned.

## Environment Notes

- Automated tests run with in-memory SQLite when configured via `phpunit.xml`:
  - `DB_CONNECTION=sqlite`
  - `DB_DATABASE=:memory:`
- In that setup, test DB refreshes do not modify the local development database.
- Tests must not delete or mutate real runtime directories under the default local app storage.
- In particular, do not write tests that delete agent workspace or session directories such as `storage/app/ai/workspace/`.
- If filesystem isolation is required, point the code under test to a test-specific temporary path and clean up only that isolated path.

## Write High-Value Tests

- Do not add tests just to increase test count.
- Tests consume time and compute; as Belimbing grows, every low-value test makes the suite slower and noisier.
- Prefer tests that protect business rules, authorization boundaries, data integrity, workflow state changes, and framework customizations — especially when the underlying contract is easy to break during refactors (routes, authz, persistence, event streams, file/path resolution, DTO/view-model shapes).
- Development-only tests are acceptable while shaping or debugging a feature, but remove them once the feature is stable if they no longer guard meaningful long-term behavior.
- Avoid smoke-only and low-signal tests such as:
  - simple `200 OK` page-render checks
  - guest-to-login redirect checks that only restate framework middleware
  - brittle string or DOM-presence assertions that do not verify behavior
  - tests that only repeat trivial implementation details already obvious from the code
  - brittle UI markup tests unless they protect a particularly fragile contract
- Keep tests that would catch a meaningful regression. Remove or avoid tests that only prove the framework can render a page.

## Reducing Sonar and Static Analysis Noise

- Sonar issues in this repository are often caused by duplication, repeated test scaffolding, and weak test doubles rather than by missing assertions.
- Extract repeated literals to file-level `const` declarations when a test file reuses the same strings, URLs, prompts, or labels.
- File-level constants in Pest tests live in the global namespace. Give them unique names across the test suite, not just within one file.
- When three or more tests repeat the same setup or assertions, extract a shared helper instead of copying the pattern again:
  - fixture builders and response factories belong in `tests/Support/`
  - broadly useful setup helpers belong in `tests/Pest.php`
- Prefer Pest `dataset()` and shared assertion helpers over repeated near-identical test cases.
- Prefer a small number of deep tests over many shallow variations that duplicate the same setup.
- Keep test doubles aligned with Laravel contracts and real application types. Avoid ad hoc doubles that satisfy only the immediate test but diverge from production expectations.
- If a refactor introduces a shared helper only for one tiny test, do not force the abstraction. Extract only when it clearly reduces duplication without hiding intent.
- Browser- and AI-heavy tests are especially prone to Sonar duplication noise. Extract repeated URLs, selectors, titles, prompts, status labels, and fixture payload keys to unique file-level constants once they recur three or more times.
- When production code throws a dedicated domain exception (see root AGENTS.md §Sonar Prevention Guard), test assertions should expect that same type. Keep generic `RuntimeException` only when the production contract is explicitly generic.
- Repeated mocked payload shapes should move into helper builders like `runnerSuccess()` / `runnerError()` rather than being copied inline across many examples.
