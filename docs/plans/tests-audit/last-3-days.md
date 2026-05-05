Title: tests-audit/last-3-days.md

Status: Complete
Last Updated: 2026-05-04
Sources: tests modified in the last 3 days (git mtime)
Agents: blb-test-suite-audit/assistant, provider=cursor, model=gpt-5.2

Problem Essence

Triage tests changed in the last 3 days and assign a disposition (keep | tighten | merge | delete) per file. The goal is to keep high-value tests, tighten weak assertions, and call out merge/delete candidates.

Desired Outcome

A short, actionable audit of the 3‑day slice listing each file's disposition, a one-line reason, and follow-ups for any file recommended for tightening or merging.

Files in scope (modified in last 3 days)

- tests/Feature/AI/TaskModelResolverTest.php — keep
  - Reason: Exercises resolver fallback logic and execution-control overlay; asserts concrete resolved provider/model results.

- tests/Feature/AI/TaskModelsTest.php — keep (was tighten; addressed)
  - Reason: Two catalog tests now assert `assertViewHas('laraActivated', …)` and task registry keys; family-switch test asserts persisted `readTaskConfig()` after each provider change alongside UI strings.

- tests/Feature/Database/BackupsIndexTest.php — keep
  - Reason: Good lifecycle coverage for backups UI, artifact/manifest assertions and encryption options; uses Storage::fake for isolation.
  - Note: Obsolete `passphrase`-mode cases were replaced with core-accurate tests (unregistered mode → danger flash; `app-key` Livewire `runBackup` writes `.bak.enc` + manifest) after passphrase left core in favor of registry-only extension modes.

- tests/Feature/Foundation/FrameworkPrimitivesProvisionerTest.php — keep
  - Reason: End-to-end provisioning with multiple state assertions; high regression value for bootstrap flows.

- tests/Feature/Modules/Commerce/Marketplace/EbayLocationsServiceTest.php — keep
  - Reason: Service layer HTTP fakes and DTO mapping; checks for missing address blocks and HTTP failure propagation.

- tests/Feature/Modules/Commerce/Marketplace/EbayPoliciesCommandTest.php — keep
  - Reason: CLI contract tests with HTTP fakes and output expectations.

- tests/Feature/Modules/Commerce/Marketplace/EbayPoliciesServiceTest.php — keep
  - Reason: Service-level parsing and marketplace fallback behavior asserted.

- tests/Feature/Modules/Core/AI/AssistantResultViewTest.php — keep
  - Reason: Precise Blade rendering order assertions for stop-note vs metadata; small and focused.

- tests/Feature/Modules/Core/AI/MessageMetaViewTest.php — keep
  - Reason: Component rendering and relative ordering checks are concrete.

- tests/Unit/Base/Database/Backup/AppKeyEncryptionTest.php — keep
  - Reason: Low-level crypto contract tests with edge cases and fingerprint checks; high signal.

- tests/Unit/Base/Database/Backup/BackupCommandTest.php — keep
  - Reason: End-to-end backup command checks including manifest/encryption envelope verification.

- tests/Unit/Base/Database/Backup/RekeyCommandTest.php — keep
  - Reason: Complex rekeying paths, idempotency, and recovery behavior are covered.

- tests/Unit/Base/Database/Backup/RetentionPolicyTest.php — keep
  - Reason: Deterministic pure-function tests for retention selection.

- tests/Unit/Base/System/KeyGenerateCommandTest.php — keep
  - Reason: Guard behavior around APP_KEY command; concrete and safe.

- tests/Unit/Modules/Core/AI/DTO/ControlPlaneDTOsTest.php — keep
  - Reason: DTO construction and serialization across many cases; protects public contract.

- tests/Unit/Modules/Core/AI/Jobs/RunLaraTaskProfileJobTest.php — keep
  - Reason: Job orchestration with auth and context clearing checks; specific.

- tests/Unit/Modules/Core/AI/Services/AgentRuntimeTest.php — keep
  - Reason: Error and config-path checks; clear unit-level contracts.

- tests/Unit/Modules/Core/AI/Services/AgenticRuntimeTest.php — keep
  - Reason: Large, high-signal suite covering tool loops, streaming, retries, and usage accounting.

- tests/Unit/Modules/Core/AI/Services/TaskModelRecommendationServiceTest.php — keep
  - Reason: Parses several response shapes; protects recommendation parsing.

Follow-ups (priority)

- None for this slice.

Stop points / Question

- None.

Evidence

- File list originally from `find tests -mtime -3` (see Sources).
- Skill pass: read `tests/AGENTS.md`, `.agents/skills/blb-test-suite-audit/references/RUBRIC.md`, and each listed test file; spot-checked production-adjacent patterns (`assertSee` density, `ConfigResolver`, `Storage::fake`, HTTP fakes).
- Initial slice run: 129 passed, 2 failed (`BackupsIndexTest` legacy passphrase expectations vs core registry).
- Shipped: `TaskModelsTest` and `BackupsIndexTest` updates; `./vendor/bin/pest tests/Feature/Database/BackupsIndexTest.php tests/Feature/AI/TaskModelsTest.php` green.

