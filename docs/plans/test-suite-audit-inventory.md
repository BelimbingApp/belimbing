# Test Suite Audit Inventory

**Agent:** Codex
**Status:** Phase 2 inventory snapshot
**Last Updated:** 2026-04-21
**Sources:** `scripts/test-suite-audit-inventory.php`, `tests/`, attempted `php artisan test --profile` on 2026-04-21

## Problem Essence

A useful audit needs a first-pass map of the suite: where the examples are concentrated, which files lean on mocks or isolated filesystem setup, and which files match common weak-test shapes such as redirect-only checks or smoke-style markup assertions.

## Desired Outcome

This report gives BLB a ranked starting point for manual review. The signals below are heuristics, not verdicts; they identify likely audit candidates and concentration areas so human review can decide keep, tighten, merge, or delete.

## Summary

- PHP files scanned: 172
- `it()` / `test()` examples detected: 1542
- Files with Mockery signals: 33
- Files with `Http::fake()`: 8
- Files with DB refresh traits: 37
- Files with filesystem-isolation signals: 23
- Redirect-only candidates: 9
- Smoke-or-markup candidates: 5
- Mock-heavy candidates: 18
- Happy-path HTTP candidates: 1

Runtime note: `php artisan test --profile` did not return a clean final profile in this environment, so runtime is not ranked per file here. Treat runtime as a scheduled or CI-backed follow-up signal, not a blocker for the first manual audit pass.

## Suite Breakdown

| Suite | Files |
| --- | ---: |
| Unit | 113 |
| Feature | 49 |
| Support | 7 |
| Bootstrap | 3 |

## Top Areas By Example Count

| Area | Files | Examples |
| --- | ---: | ---: |
| Modules/Core/AI | 104 | 1097 |
| Authz | 4 | 65 |
| Company | 4 | 41 |
| Base/Support | 4 | 34 |
| AI | 6 | 31 |
| User | 2 | 26 |
| Base/DateTime | 2 | 25 |
| Database | 4 | 24 |
| Base/Settings | 1 | 24 |
| Auth | 7 | 23 |
| Workflow | 1 | 23 |
| Base/AI | 5 | 23 |

## Ranked Audit Candidates

| Score | File | Area | Examples | Signals |
| ---: | --- | --- | ---: | --- |
| 14 | [AgenticRuntimeTest.php](/home/kiat/repo/laravel/blb/tests/Unit/Modules/Core/AI/Services/AgenticRuntimeTest.php) | Modules/Core/AI | 29 | mock-heavy, filesystem-sensitive, large-example-count |
| 13 | [ToolCallingTest.php](/home/kiat/repo/laravel/blb/tests/Unit/Modules/Core/AI/Services/ToolCallingTest.php) | Modules/Core/AI | 37 | mock-heavy, filesystem-sensitive, large-example-count |
| 9 | [BrowserToolTest.php](/home/kiat/repo/laravel/blb/tests/Unit/Modules/Core/AI/Tools/BrowserToolTest.php) | Modules/Core/AI | 43 | mock-heavy, large-example-count |
| 9 | [BrowserSessionManagerTest.php](/home/kiat/repo/laravel/blb/tests/Unit/Modules/Core/AI/Services/Browser/BrowserSessionManagerTest.php) | Modules/Core/AI | 20 | mock-heavy, large-example-count |
| 9 | [HealthAndPresenceServiceTest.php](/home/kiat/repo/laravel/blb/tests/Unit/Modules/Core/AI/Services/ControlPlane/HealthAndPresenceServiceTest.php) | Modules/Core/AI | 16 | mock-heavy, db-refresh |
| 9 | [LifecycleControlServiceTest.php](/home/kiat/repo/laravel/blb/tests/Unit/Modules/Core/AI/Services/ControlPlane/LifecycleControlServiceTest.php) | Modules/Core/AI | 16 | mock-heavy, db-refresh |
| 8 | [RoleUiTest.php](/home/kiat/repo/laravel/blb/tests/Feature/Authz/RoleUiTest.php) | Authz | 47 | smoke-or-markup, large-example-count |
| 8 | [MessageToolTest.php](/home/kiat/repo/laravel/blb/tests/Unit/Modules/Core/AI/Tools/MessageToolTest.php) | Modules/Core/AI | 42 | mock-heavy, large-example-count |
| 8 | [ScheduleTaskToolTest.php](/home/kiat/repo/laravel/blb/tests/Unit/Modules/Core/AI/Tools/ScheduleTaskToolTest.php) | Modules/Core/AI | 25 | mock-heavy, large-example-count |
| 8 | [BrowserRuntimeAdapterTest.php](/home/kiat/repo/laravel/blb/tests/Unit/Modules/Core/AI/Services/Browser/BrowserRuntimeAdapterTest.php) | Modules/Core/AI | 17 | mock-heavy |
| 8 | [QualityWorkflowUiTest.php](/home/kiat/repo/laravel/blb/tests/Feature/Quality/QualityWorkflowUiTest.php) | Quality | 11 | redirect-only |
| 8 | [LocalizationUiTest.php](/home/kiat/repo/laravel/blb/tests/Feature/System/LocalizationUiTest.php) | System | 11 | smoke-or-markup, db-refresh |
| 8 | [PasswordResetTest.php](/home/kiat/repo/laravel/blb/tests/Feature/Auth/PasswordResetTest.php) | Auth | 8 | redirect-only |
| 8 | [ProfileUpdateTest.php](/home/kiat/repo/laravel/blb/tests/Feature/Settings/ProfileUpdateTest.php) | Settings | 8 | redirect-only |
| 8 | [ToolReadinessServiceTest.php](/home/kiat/repo/laravel/blb/tests/Unit/Modules/Core/AI/Services/ToolReadinessServiceTest.php) | Modules/Core/AI | 7 | mock-heavy, db-refresh |
| 8 | [ImpersonationTest.php](/home/kiat/repo/laravel/blb/tests/Feature/Authz/ImpersonationTest.php) | Authz | 6 | redirect-only |
| 8 | [CompanyUiTest.php](/home/kiat/repo/laravel/blb/tests/Feature/Company/CompanyUiTest.php) | Company | 6 | redirect-only |
| 8 | [AuthenticationTest.php](/home/kiat/repo/laravel/blb/tests/Feature/Auth/AuthenticationTest.php) | Auth | 5 | redirect-only |
| 8 | [EmailVerificationTest.php](/home/kiat/repo/laravel/blb/tests/Feature/Auth/EmailVerificationTest.php) | Auth | 4 | redirect-only |
| 8 | [PasswordConfirmationTest.php](/home/kiat/repo/laravel/blb/tests/Feature/Auth/PasswordConfirmationTest.php) | Auth | 4 | redirect-only |

## Heuristic Notes

- `redirect-only` flags files dominated by redirect assertions without broader behavior checks.
- `smoke-or-markup` flags files with multiple `assertSee()` checks and no stronger interaction signals.
- `mock-heavy` flags files with large Mockery / `shouldReceive()` volume; many will still be valid, but they deserve a behavior-vs-scaffolding review.
- `happy-path-http` flags files that fake HTTP but show no obvious error-path assertions.
- `filesystem-sensitive` marks tests that create and clean up temporary storage paths; these are not bad by themselves, but they are worth checking against the runtime-storage rule in `tests/AGENTS.md`.

## First Recommendations

- Start the pilot audit in `Modules/Core/AI`; it dominates the example count and contains both mock-heavy units and filesystem-sensitive tests.
- Review redirect-only and smoke-or-markup files early; they are cheap delete or merge candidates.
- Treat the skipped `LlmClientToolCallingTest` file as its own audit topic because it already signals test-suite friction and missing trust in CI.
