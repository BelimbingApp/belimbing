Title: tests-audit/last-7-days.md

Status: Complete
Last Updated: 2026-05-04
Sources: tests modified in the last 7 days (git mtime); overlap with `docs/plans/tests-audit/last-3-days.md` (Complete) removed from this list
Agents: cursor/gpt-5.2

Problem Essence

We must triage the test files changed in the last 7 days and assign a disposition (keep | tighten | merge | delete) per file. The goal is to (a) keep tests that protect concrete behavior, (b) tighten weak or under-asserting tests, (c) identify merge candidates, and (d) delete clear smoke/markup-only duplication.

Desired Outcome

A short, actionable audit of the **remaining** 7‑day slice (files not already covered in `tests-audit/last-3-days.md`), each with disposition and a one-line reason; any tighten follow-up discovered during the audit was implemented (see Evidence).

Per-file dispositions (7‑day slice minus files already audited in `tests-audit/last-3-days.md`)

Audit summary: **34 keep**, **0 tighten**, **0 merge**, **0 delete** (after `SalesInsightsServiceTest` recent-sales limit case was tightened in-repo). Full file read of `SalesInsightsServiceTest.php`; spot checks and heuristics on the rest; `./vendor/bin/pest` on all 34 paths green after the edit. Production mutation proof (skill step 7) still optional / not run.

- tests/Feature/AI/AiAdminMenuAccessTest.php — keep
  - Reason: Asserts menu presence/absence and flattens the menu tree; stops regressions in menu gating.

- tests/Feature/AI/PricingOverridesTest.php — keep
  - Reason: Exercises Livewire flows and asserts persisted DB values; protects the override lifecycle.

- tests/Feature/Base/Media/MediaAssetStoreTest.php — keep
  - Reason: Strong coverage, filesystem isolation via Storage::fake, file-level assertions present.

- tests/Feature/Base/Media/MediaAssetStreamTest.php — keep
  - Reason: Precise stream semantics and signed URL expiry behaviors are asserted.

- tests/Feature/Company/CompanyTest.php — keep
  - Reason: Broad domain behaviors with concrete assertions on scopes, ancestry, status transitions.

- tests/Feature/Database/MigrateCommandTest.php — keep
  - Reason: Small but concrete: asserts reconciliation logging for orphaned registry entries.

- tests/Feature/Database/WipeCommandTest.php — keep
  - Reason: Concrete guard behavior for destructive command; effective smoke check.

- tests/Feature/Modules/Commerce/Catalog/CatalogModelTest.php — keep
  - Reason: Validates model relationships and workbench operations with DB checks.

- tests/Feature/Modules/Commerce/Inventory/ItemWorkbenchTest.php — keep
  - Reason: Thorough Livewire flows, file upload assertions, and scope checks.

- tests/Feature/Modules/Commerce/Marketplace/EbayMarketplaceTest.php — keep
  - Reason: Integration-style tests that materialize listings and ensure linking by SKU.

- tests/Feature/Modules/Commerce/Sales/SalesCsvExportTest.php — keep
  - Reason: Verifies CSV headers, streamed content, and error on inverted date ranges.

- tests/Feature/Modules/Commerce/Sales/SalesInsightsServiceTest.php — keep
  - Reason: Aggregations, scopes, and limit/order paths assert concrete IDs, dates, or row shape; **honors the limit when listing recent sales** now asserts the three newest `saleId` values and `soldAt` dates after the audit follow-up.

- tests/Feature/Modules/Core/AI/ChatAttachmentsTest.php — keep
  - Reason: Filesystem isolation and content-disposition/type assertions – concrete behavior.

- tests/Feature/Modules/Core/AI/ChatStopStaleTurnTest.php — keep
  - Reason: Control-plane lifecycle assertions and persisted run metadata checks; strong.

- tests/Feature/Modules/Core/AI/Console/SweepStaleTurnsCommandTest.php — keep
  - Reason: Command behavior driving state transitions; includes event checks.

- tests/Feature/Modules/Core/AI/ControlPlaneInspectorTest.php — keep
  - Reason: Heavy UI assertions but meaningful: wire-log windowing, reassembly, and raw-stream behavior.

- tests/Feature/Modules/Core/AI/Http/TurnEventStreamControllerTest.php — keep
  - Reason: Verifies JSON envelope and seq behaviors for event replay; concrete.

- tests/Feature/Modules/Core/AI/OpenAiCodexOAuthCallbackTest.php — keep
  - Reason: Verifies OAuth flow resilience and session/state handling; concrete.

- tests/Feature/Modules/Core/AI/OpenAiCodexSetupTest.php — keep
  - Reason: Extensive diagnostic, curated-model reconciliation, and verification flows; good regression value.

- tests/Feature/Settings/AdminSettingsUiTest.php — keep
  - Reason: Saves and scopes settings; UI + persistence verified. Consider tightening if future UI-only assertions proliferate.

- tests/Unit/Base/AI/Services/LlmClientToolCallingTest.php — keep
  - Reason: Large, protocol-heavy unit tests that assert request shaping and response parsing; high signal.

- tests/Unit/Base/AI/Services/Protocols/OpenAiCodexResponsesProtocolClientTest.php — keep
  - Reason: One focused assertion but protects a protocol contract.

- tests/Unit/Base/Foundation/Providers/ProviderRegistryTest.php — keep
  - Reason: Reflection-level normalization checks and dedup ordering; concise and useful.

- tests/Unit/Base/Foundation/ValueObjects/MoneyTest.php — keep
  - Reason: Clear parsing/formatting contract; unit-level.

- tests/Unit/Modules/Core/AI/Enums/ControlPlaneEnumsTest.php — keep
  - Reason: Enums mapping and label/color invariants; deterministic.

- tests/Unit/Modules/Core/AI/Services/AgenticToolLoopStreamReaderTest.php — keep
  - Reason: Stream reassembly and usage normalization; focused and necessary.

- tests/Unit/Modules/Core/AI/Services/ControlPlane/LifecycleControlServiceTest.php — keep
  - Reason: Completeness on preview/execute/sweep operations; concrete.

- tests/Unit/Modules/Core/AI/Services/ControlPlane/RunRecorderTest.php — keep
  - Reason: Aggregation/upsert semantics and token summation; valuable.

- tests/Unit/Modules/Core/AI/Services/ControlPlane/WireLogReadableFormatterTest.php — keep
  - Reason: Heavy reassembly logic with many edge conditions — keep.

- tests/Unit/Modules/Core/AI/Services/LaraTaskRegistryTest.php — keep
  - Reason: Small, focused contract test for task registry keys.

- tests/Unit/Modules/Core/AI/Services/Pricing/PricingSourceRegistryTest.php — keep
  - Reason: Pricing fallback and override ordering tests; deterministic.

- tests/Unit/Modules/Core/AI/Services/Pricing/RefreshPricingSnapshotTest.php — keep
  - Reason: Snapshot import idempotency + command coverage; concrete.

- tests/Unit/Modules/Core/AI/Services/Pricing/TokenCostCalculatorTest.php — keep
  - Reason: Clear numeric contract for token costing.

- tests/Unit/Modules/Core/AI/Values/CallUsageTest.php — keep
  - Reason: Usage-parsing behaviors; preserves raw payload for forensics.

Follow-ups (high priority)

- None.

Stop points / Questions for the user

- None.

Evidence

- File list, timestamps, and a directory scan were used to assemble this audit (slice: tests modified in last 7 days). Entries that duplicate `docs/plans/tests-audit/last-3-days.md` were removed 2026-05-04 so the 7‑day list is the complement of the completed 3‑day slice.
- Audit pass: `tests/AGENTS.md` + `.agents/skills/blb-test-suite-audit/references/RUBRIC.md`; full read of `SalesInsightsServiceTest.php`; sampled `AiAdminMenuAccessTest`, `AdminSettingsUiTest`, `WipeCommandTest`, `OpenAiCodexResponsesProtocolClientTest`, `ControlPlaneInspectorTest` (wire-log fixtures + bounded preview); `grep` for `assertSee` load on `ControlPlaneInspectorTest` (markup-heavy but backed by disk fixtures and bounded-window copy).
- `./vendor/bin/pest` on all 34 paths in this doc: **230 passed** (1149 assertions) after the `SalesInsightsServiceTest` tightening. Optional production mutation proof (skill step 7) not run.
