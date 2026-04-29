# Test Suite Audit Inventory

**Agent:** Codex
**Status:** Inventory and completion snapshot
**Last Updated:** 2026-04-29
**Sources:** `scripts/test-suite-audit-inventory.php`, `tests/`, `docs/plans/test-suite-audit.md`, `docs/plans/ai-test-suite-audit.md`, attempted `php artisan test --profile` on 2026-04-21

## Problem Essence

A useful audit needs a first-pass map of the suite: where the examples are concentrated, which files lean on mocks or isolated filesystem setup, and which files match common weak-test shapes such as redirect-only checks or smoke-style markup assertions.

## Desired Outcome

This report gives BLB both a ranked starting point and a compact view of the remaining endgame. The signals below are heuristics, not verdicts; they identify likely audit candidates and concentration areas so human review can decide keep, tighten, merge, or delete.

## Audit Progress Snapshot

### Completed Or Mature Slices

- `Modules/Core/AI` companion audit: complete at this checkpoint; see [ai-test-suite-audit.md](/home/kiat/repo/laravel/blb/docs/plans/ai-test-suite-audit.md:1)
- Auth and Settings cheap-candidate slice: reviewed with real tightenings in password reset and password confirmation
- Authz and System cheap-candidate slice: `ImpersonationTest.php`, `RoleUiTest.php`, and `LocalizationUiTest.php` reviewed; `RoleUiTest.php` tightened
- Company feature slice: `CompanyUiTest.php`, `CompanyRelationshipTest.php`, and `ExternalAccessTest.php` tightened; `CompanyTest.php` and `CompanyTimezoneTest.php` kept
- Quality cheap-candidate slice: `QualityWorkflowUiTest.php` reviewed as `keep`
- User feature slice: `UserUiTest.php` and `PagePinningTest.php` reviewed; both kept with targeted tightenings
- Remaining auth and system feature slices: `RegistrationTest.php` tightened; `TransportTestUiTest.php` reviewed as `keep`
- Database feature slice: reviewed as `keep`
- Smaller leftover feature sweep: `AddressUiTest.php` tightened; audit, foundation, and workflow feature files reviewed as `keep`
- Small Base unit slice: authz middleware and locale bootstrap tightened; authz registry, actor, database settings, and database exception contracts reviewed as `keep`
- Remaining Base support/date-time/menu slice: `FileTest.php` tightened for storage isolation; date-time, menu, and support helper tests reviewed as `keep`
- Foundation unit slice: `ProviderRegistryTest.php` tightened; BLB exception contract test reviewed as `keep`
- Remaining user/timezone slice: `TimezoneCycleTest.php` tightened for employee-scope persistence; `PasswordUpdateTest.php` and `UserTest.php` reviewed as `keep`
- Final non-AI leftovers: `ExampleTest.php` deleted; `tests/Pest.php` cleaned of unused stock scaffolding while retaining the shared helpers in active use
- `Base/AI` service slice: `LlmClientToolCallingTest.php` re-enabled; discovery, API-type, query, and header/auth tests tightened; codex protocol and mapping tests reviewed as `keep`
- Reopened `Feature/Modules/Core/AI` Codex auth/setup slice: `OpenAiCodexOAuthCallbackTest.php` and `OpenAiCodexSetupTest.php` tightened
- Reopened `Feature/Modules/Core/AI` control-plane and result/message view slice: `ControlPlaneInspectorTest.php` kept; `AssistantResultViewTest.php` and `MessageMetaViewTest.php` tightened
- Reopened `Feature/Modules/Core/AI` chat lifecycle and attachment slice: `ChatConcurrentRunPolicyTest.php`, `ChatConcurrentSessionLifecycleTest.php`, `ChatRunPersisterTest.php`, and `ChatViewTest.php` kept; `ChatAttachmentsTest.php` and `ChatStopStaleTurnTest.php` tightened
- Reopened `Feature/Modules/Core/AI` turn streaming and publisher slice: `TurnEventPublisherTest.php` and `TurnStreamBridgeTest.php` kept; `Http/TurnEventStreamControllerTest.php` tightened on the exact replay boundary contract
- Phase 5 guardrails: changed-test linting, PR review prompt, and scheduled slow-test plus mutation-style reporting are in place

### Current Checkpoint

- The reopened post-baseline `Feature/Modules/Core/AI` cluster is now complete, so the audit is back to complete at the current scope
- Cheap-candidate heuristics were useful for ranking, but produced multiple false positives and were never good enough for automatic downgrade decisions
- Outside AI, the remaining high-confidence audit work was already effectively exhausted; those slices are now closed
- Inside AI, both the `Base/AI` cluster and the remaining `Modules/Core/AI` unit/service and feature endgame slices are now reviewed

### Remaining Slices Checklist

- [x] Non-AI feature slices
- [x] Non-AI Base/helper slices
- [x] Non-AI infrastructure leftovers
- [x] `Base/AI` service slice
- [x] `Modules/Core/AI` control-plane services
- [x] `Modules/Core/AI` orchestration services
- [x] `Modules/Core/AI` memory services
- [x] `Modules/Core/AI` workspace and prompt services
- [x] `Modules/Core/AI` remaining tool clusters
- [x] `Modules/Core/AI` remaining service cluster outside the active user worktree
- [x] `Modules/Core/AI` DTO / enum / jobs / routes cleanup sweep
- [x] Final AI endgame pass: decide whether anything remaining is true `keep`, `tighten`, `merge`, or can be explicitly deferred
- [x] `Feature/Modules/Core/AI` OpenAI Codex auth/setup slice
- [x] `Feature/Modules/Core/AI` control-plane and result/message view slice
- [x] `Feature/Modules/Core/AI` chat lifecycle and attachment slice
- [x] `Feature/Modules/Core/AI` turn streaming and publisher slice

### Next Recommended Slices

- No obvious remaining slices at the current scope
- Reopen the audit only when new churn or a fresh inventory pass surfaces a materially under-audited cluster

### Remaining Buckets After That

- Feature modules not yet audited in this program: none obvious
- Non-AI unit/service clusters that still look promising: none obvious from the current inventory snapshot
- AI endgame note: the unit/service and post-baseline feature endgames are closed at this checkpoint
- CI guardrail promotions are future work, not an unfinished part of this current audit baseline

## Summary

- PHP files scanned: 181
- `it()` / `test()` examples detected: 1589
- Files with Mockery signals: 35
- Files with `Http::fake()`: 11
- Files with DB refresh traits: 38
- Files with filesystem-isolation signals: 23
- Redirect-only candidates: 10
- Smoke-or-markup candidates: 4
- Mock-heavy candidates: 20
- Happy-path HTTP candidates: 3

Runtime note: `php artisan test --profile` did not return a clean final profile in this environment, so runtime is not ranked per file here. Treat runtime as a scheduled or CI-backed follow-up signal, not a blocker for the first manual audit pass.

## Suite Breakdown

| Suite | Files |
| --- | ---: |
| Unit | 120 |
| Feature | 51 |
| Support | 7 |
| Bootstrap | 3 |

## Top Areas By Example Count

| Area | Files | Examples |
| --- | ---: | ---: |
| Modules/Core/AI | 110 | 1139 |
| Authz | 4 | 65 |
| Company | 4 | 41 |
| Base/Support | 4 | 34 |
| AI | 6 | 31 |
| Base/AI | 9 | 27 |
| Base/DateTime | 2 | 26 |
| User | 2 | 26 |
| Database | 4 | 24 |
| Base/Settings | 1 | 24 |
| Auth | 7 | 23 |
| Workflow | 1 | 23 |

## Ranked Audit Candidates

| Score | File | Area | Examples | Signals |
| ---: | --- | --- | ---: | --- |
| 14 | [AgenticRuntimeTest.php](/home/kiat/repo/laravel/blb/tests/Unit/Modules/Core/AI/Services/AgenticRuntimeTest.php) | Modules/Core/AI | 29 | mock-heavy, filesystem-sensitive, large-example-count |
| 9 | [BrowserToolTest.php](/home/kiat/repo/laravel/blb/tests/Unit/Modules/Core/AI/Tools/BrowserToolTest.php) | Modules/Core/AI | 43 | mock-heavy, large-example-count |
| 9 | [ToolCallingTest.php](/home/kiat/repo/laravel/blb/tests/Unit/Modules/Core/AI/Services/ToolCallingTest.php) | Modules/Core/AI | 37 | mock-heavy, large-example-count |
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
| 8 | [ToolReadinessServiceTest.php](/home/kiat/repo/laravel/blb/tests/Unit/Modules/Core/AI/Services/ToolReadinessServiceTest.php) | Modules/Core/AI | 8 | mock-heavy, db-refresh |
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

## Updated Read Of The Inventory

- The original ranking did its job for prioritization, but it is no longer the full story because many of the top-ranked files are already audited.
- Cheap-candidate signals should continue to guide ordering, not decisions.
- Outside AI, the remaining high-confidence audit work is effectively exhausted.
- The final AI endgame pass closed the remaining `Modules/Core/AI` slices with a mix of `keep` dispositions, a real control-plane production bug fix, stale skip removal, and one last tool-isolation tightening.
