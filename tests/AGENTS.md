# Tests Agent Guide

## Defaults

- Test baseline seeding uses `Tests\TestingBaselineSeeder`; choose included modules via `tests/Support/testing-seed-modules.php`.
- Use `setupAuthzRoles()` for authz-dependent tests and `createAdminUser()` for authenticated admin feature tests.

## Isolation

- Do not let tests delete or mutate real runtime storage such as `storage/app/ai/workspace/`.
- If filesystem isolation is needed, point the code under test at a test-specific temporary path and clean up only that path.
- Keep test database refreshes isolated from the local development database.

## Value

- The codebase is first-class; tests are support infrastructure.
- Add or keep tests only when they stop a specific bad code change. If that change is vague, the test is probably not worth its cost.
- Prefer behavior-oriented tests that protect business rules, authz, persistence, workflows, and fragile framework customizations.
- Be skeptical of happy-path-only, smoke-only, or markup-only tests, especially when they mostly restate framework behavior or implementation details.
- If a test often needs repair while production behavior stays stable, treat it as a test-design smell and rewrite or delete it.
- Prove regression-test value by reproducing the pre-fix bug or by applying a narrow temporary mutation to production code. Do not strengthen the test first and present that as proof.

## Keep Tests Lean

- Avoid low-value duplication, repeated scaffolding, and weak test doubles; they create Sonar noise and CI waste.
- Extract repeated setup, fixtures, and payload builders into `tests/Support/` or `tests/Pest.php` once repetition starts to obscure intent.
- Prefer `dataset()` and shared assertion helpers over near-identical test cases.
- File-level Pest `const` names are global; keep them unique across the suite.
- Keep test doubles aligned with Laravel contracts and real application types.
