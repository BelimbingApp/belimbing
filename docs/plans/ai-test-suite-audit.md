# AI Test Suite Audit

**Agent:** Codex
**Status:** Complete
**Last Updated:** 2026-04-21
**Sources:** `docs/plans/test-suite-audit.md`, `docs/plans/test-suite-audit-rubric.md`, `docs/plans/test-suite-audit-inventory.md`, `tests/Feature/AI/ProviderConnectionsTest.php`, `tests/Feature/AI/ProvidersUiTest.php`, `tests/Feature/AI/LaraSetupTest.php`, `tests/Feature/AI/TaskModelsTest.php`, `tests/Feature/AI/AiAdminMenuAccessTest.php`, `tests/Unit/Modules/Core/AI/Services/AgenticRuntimeTest.php`, `tests/Unit/Modules/Core/AI/Services/ToolCallingTest.php`, `tests/Unit/Modules/Core/AI/Tools/BrowserToolTest.php`, `tests/Unit/Modules/Core/AI/Services/Browser/BrowserSessionManagerTest.php`, `tests/Unit/Modules/Core/AI/Services/Browser/BrowserRuntimeAdapterTest.php`, `tests/Unit/Modules/Core/AI/Services/Browser/BrowserSessionRepositoryTest.php`, `tests/Unit/Modules/Core/AI/Services/Browser/BrowserPoolManagerTest.php`, `tests/Unit/Modules/Core/AI/Services/Browser/BrowserArtifactStoreTest.php`, `tests/Unit/Modules/Core/AI/Services/Browser/BrowserSsrfGuardTest.php`, `tests/Unit/Modules/Core/AI/Console/Commands/BrowserStatusCommandTest.php`, `tests/Unit/Modules/Core/AI/Console/Commands/BrowserSweepCommandTest.php`, `tests/Unit/Modules/Core/AI/Services/SessionAccessGuardTest.php`, `tests/Unit/Modules/Core/AI/Services/ControlPlane/HealthAndPresenceServiceTest.php`, `tests/Unit/Modules/Core/AI/Services/ControlPlane/LifecycleControlServiceTest.php`, `tests/Unit/Modules/Core/AI/Tools/MessageToolTest.php`, `tests/Unit/Modules/Core/AI/Tools/ScheduleTaskToolTest.php`, `tests/Unit/Modules/Core/AI/Services/ToolReadinessServiceTest.php`, `tests/Unit/Modules/Core/AI/Services/AgenticToolLoopStreamReaderTest.php`, `tests/Unit/Modules/Core/AI/Services/AgenticFinalResponseStreamerTest.php`, `tests/Unit/Modules/Core/AI/Services/DispatchTranscriptBridgeTest.php`, `app/Modules/Core/AI/Services/Browser/BrowserArtifactStore.php`, `app/Modules/Core/AI/Services/AgenticFinalResponseStreamer.php`

## Problem Essence

The AI area dominates the current suite by both file count and example count. The first pilot pass needs a small audited slice that proves the rubric can delete obvious YAGNI checks without collapsing useful behavior coverage.

## Desired Outcome

The AI audit leaves behind fewer weak tests, clearer reasons for what stays, and a repeatable pattern for the next batch of files. Each reviewed file gets an explicit disposition and short rationale.

## Pilot Outcome

Later `Modules/Core/AI` endgame slices were completed in the master audit plan on 2026-04-22. This companion remains the focused AI pilot and early-expansion record; see [test-suite-audit.md](/home/kiat/repo/laravel/blb/docs/plans/test-suite-audit.md:1) for the final closeout status.

The pilot is complete enough to graduate from proof-of-process to module expansion. This pass reviewed 19 AI files and produced all four rubric outcomes in practice:

- 14 `keep`
- 4 `tighten`
- 1 `delete + merge`

Real changes from the pilot:

- removed one low-signal feature smoke assertion and merged duplicate redirect coverage
- fixed three test-isolation breaches touching default runtime storage
- tightened one repository test that claimed ordering coverage without asserting order
- recorded dispositions for browser, control-plane, setup, access, and provider-related tests

The remaining AI files should now be handled as Phase 4 module expansion, not as unresolved pilot setup.

## Phase 4 Progress

Current expansion slice: remaining high-ranked AI tool/service files after the pilot. This slice covers:

- `MessageToolTest.php`
- `ScheduleTaskToolTest.php`
- `ToolReadinessServiceTest.php`

Outcome so far:

- `MessageToolTest.php`: keep
- `ScheduleTaskToolTest.php`: keep
- `ToolReadinessServiceTest.php`: tighten

The tool-boundary files remain mock-heavy but behavior-oriented. The readiness service test needed one real tightening because it barely exercised `allSnapshots()` and only covered one conditional tool name.

Next expansion slice: streaming/runtime-adjacent AI services. This slice covers:

- `AgenticToolLoopStreamReaderTest.php`
- `AgenticFinalResponseStreamerTest.php`
- `DispatchTranscriptBridgeTest.php`

Outcome so far:

- `AgenticToolLoopStreamReaderTest.php`: tighten
- `AgenticFinalResponseStreamerTest.php`: tighten
- `DispatchTranscriptBridgeTest.php`: keep

This slice found a real production defect, not just a test gap: `AgenticFinalResponseStreamer` emitted a runtime-error event but then continued into the empty-response path. The current pass tightened the streamer tests and fixed the production control flow so stream errors terminate correctly.

The AI-focused audit sheet is complete at this checkpoint. Later AI-specific cleanup can reopen this document or continue in the master audit plan if new weak candidates surface.

## Design Decisions

### 1. Start with nearby feature tests before the mock-heavy unit cluster

The first slice should be cheap to review and easy to explain. AI feature tests around providers, setup, task models, and menu access are a better opening move than jumping directly into the largest mock-heavy unit files. They give fast signal on whether the rubric is too aggressive or too timid.

### 2. Delete weak smoke checks when stronger nearby tests already protect the area

`ProviderConnectionsTest.php` mixed one weak page-shape assertion with two route-redirect checks. The page-shape assertion mainly checked copy and link presence. Nearby files already cover provider UI behavior and Lara setup behavior more concretely. That made it a good delete candidate, while the redirect contract remained worth keeping.

### 3. Keep behavior-oriented setup and config tests

`LaraSetupTest.php`, `TaskModelsTest.php`, `ProvidersUiTest.php`, and `AiAdminMenuAccessTest.php` all protect behavior with a concrete failure mode: selected models, persisted config, auth-driven menu visibility, device-flow startup, or masked credential rendering. These should remain unless later review finds duplication with stronger tests.

## Public Contract

For each reviewed AI file, this audit sheet records:

- disposition: keep, tighten, merge, or delete
- short reason tied to a specific contract or weakness
- follow-up only when more work is still needed

## Phase 1 — First Slice

Goal: review a small set of AI feature tests and make at least one real cleanup change.

- [x] Choose the pilot target area as AI
- [x] Review `tests/Feature/AI/ProviderConnectionsTest.php`
- [x] Delete the weak empty-state smoke assertion from `ProviderConnectionsTest.php`
- [x] Merge the two legacy redirect checks in `ProviderConnectionsTest.php` into one dataset-driven contract
- [x] Review `tests/Feature/AI/ProvidersUiTest.php`
- [x] Review `tests/Feature/AI/LaraSetupTest.php`
- [x] Review `tests/Feature/AI/TaskModelsTest.php`
- [x] Review `tests/Feature/AI/AiAdminMenuAccessTest.php`
- [x] Review `tests/Unit/Modules/Core/AI/Services/AgenticRuntimeTest.php`
- [x] Review `tests/Unit/Modules/Core/AI/Services/ToolCallingTest.php`
- [x] Review `tests/Unit/Modules/Core/AI/Tools/BrowserToolTest.php`
- [x] Review `tests/Unit/Modules/Core/AI/Services/Browser/BrowserSessionManagerTest.php`
- [x] Review `tests/Unit/Modules/Core/AI/Services/Browser/BrowserRuntimeAdapterTest.php`
- [x] Review `tests/Unit/Modules/Core/AI/Services/Browser/BrowserSessionRepositoryTest.php`
- [x] Review `tests/Unit/Modules/Core/AI/Services/Browser/BrowserPoolManagerTest.php`
- [x] Review `tests/Unit/Modules/Core/AI/Services/Browser/BrowserArtifactStoreTest.php`
- [x] Review `tests/Unit/Modules/Core/AI/Services/Browser/BrowserSsrfGuardTest.php`
- [x] Review `tests/Unit/Modules/Core/AI/Console/Commands/BrowserStatusCommandTest.php`
- [x] Review `tests/Unit/Modules/Core/AI/Console/Commands/BrowserSweepCommandTest.php`
- [x] Review `tests/Unit/Modules/Core/AI/Services/SessionAccessGuardTest.php`
- [x] Review `tests/Unit/Modules/Core/AI/Services/ControlPlane/HealthAndPresenceServiceTest.php`
- [x] Review `tests/Unit/Modules/Core/AI/Services/ControlPlane/LifecycleControlServiceTest.php`
- [x] Move `ToolCallingTest.php` temp-file writes out of `storage/app/` and into an isolated testing path
- [x] Tighten `BrowserSessionRepositoryTest.php` so the ordering test asserts order, not just count
- [x] Add an artifact-storage config seam so `BrowserArtifactStoreTest.php` can avoid the default runtime directory
- [x] End the pilot with a representative sample of setup, browser, control-plane, session, and provider-adjacent tests

## Reviewed Files

### `tests/Feature/AI/ProviderConnectionsTest.php`

- **Disposition:** delete + merge
- **Reason:** the empty-state page-shape assertion was low-signal and mostly copy-driven, while the legacy redirect contract is still worth keeping. The two redirect checks were duplicates and now live as one dataset-driven test.

### `tests/Feature/AI/ProvidersUiTest.php`

- **Disposition:** keep
- **Reason:** it protects masked API-key rendering and company-scoped GitHub Copilot device-flow bootstrap. Those are concrete UI and auth-flow contracts, not generic page-render smoke.

### `tests/Feature/AI/LaraSetupTest.php`

- **Disposition:** keep
- **Reason:** it checks model-selection behavior and persisted Lara config updates, including preservation of execution controls. These are meaningful setup contracts with clear regression value.

### `tests/Feature/AI/TaskModelsTest.php`

- **Disposition:** keep
- **Reason:** it covers task-model persistence, recommendation storage, primary fallback behavior, and execution-control preservation. The file is behavior-oriented even though some assertions are UI-triggered.

### `tests/Feature/AI/AiAdminMenuAccessTest.php`

- **Disposition:** keep
- **Reason:** it protects authz-driven visibility of the AI admin menu through the actual menu snapshot and built tree, which is a useful boundary contract rather than a generic route smoke test.

### `tests/Unit/Modules/Core/AI/Services/AgenticRuntimeTest.php`

- **Disposition:** tighten
- **Reason:** the file protects high-value runtime behavior and remains worth keeping, but one wire-log test wrote into the default `storage/app/ai/wire-logs/` path. The current pass replaced that with a test-local `WireLogger` override so the same contract is checked without mutating default runtime storage.

### `tests/Unit/Modules/Core/AI/Services/ToolCallingTest.php`

- **Disposition:** tighten
- **Reason:** the file largely protects useful tool contracts, but it had an isolation breach by writing temporary fixtures into `storage/app/`. The current pass moved those fixtures into `storage/framework/testing/tool-calling/`, which keeps the contract coverage while respecting the test-storage rule. A later pass can decide whether some metadata-only cases should merge.

### `tests/Unit/Modules/Core/AI/Tools/BrowserToolTest.php`

- **Disposition:** keep
- **Reason:** it is mock-heavy because the browser runtime is an external boundary, but the assertions are still behavior-oriented: SSRF blocking, action validation, session-manager integration, and result-shape contracts.

### `tests/Unit/Modules/Core/AI/Services/Browser/BrowserSessionManagerTest.php`

- **Disposition:** keep
- **Reason:** it protects the manager's real orchestration branches: availability gating, session reuse, per-company concurrency limits, TTL extension, stale-session expiry, and DTO shaping. The file is mock-heavy because the repository and runtime are true boundaries here, but the assertions still target behavior rather than implementation trivia.

### `tests/Unit/Modules/Core/AI/Services/Browser/BrowserRuntimeAdapterTest.php`

- **Disposition:** keep
- **Reason:** it protects the adapter's main failure-prone contracts: actionable-state validation, Busy/Ready/Failed transitions, headless-mode injection, ref freshness enforcement, and ref invalidation after navigation. These are the core rules that sit between the browser tool and Playwright execution.

### `tests/Unit/Modules/Core/AI/Services/Browser/BrowserSessionRepositoryTest.php`

- **Disposition:** tighten
- **Reason:** the file has strong value because it exercises real persistence and lifecycle invariants, but one test claimed ordering by last activity while only asserting count. The current pass tightened that to assert the actual returned order.

### `tests/Unit/Modules/Core/AI/Services/Browser/BrowserPoolManagerTest.php`

- **Disposition:** keep
- **Reason:** despite its smaller surface area, it still protects real pool behavior: enablement gating, session-context reuse, per-company concurrency limits, slot release, and cross-company isolation. There is little delete value here without losing those contracts.

### `tests/Unit/Modules/Core/AI/Services/Browser/BrowserArtifactStoreTest.php`

- **Disposition:** tighten
- **Reason:** the file protects real persistence and cleanup behavior, but it previously wrote artifacts into the default runtime `storage/app/browser-artifacts/...` path. The current pass added a small production seam so the test can isolate artifact writes under `storage/framework/testing/...` without changing runtime behavior.

### `tests/Unit/Modules/Core/AI/Services/SessionAccessGuardTest.php`

- **Disposition:** keep
- **Reason:** it exercises real authz and user-isolation behavior through `SessionManager` and `MessageManager`, including Lara's per-user path isolation and run-metadata behavior. The file already uses a test-local workspace root and does not need tightening in this pass.

### `tests/Unit/Modules/Core/AI/Services/Browser/BrowserSsrfGuardTest.php`

- **Disposition:** keep
- **Reason:** even though the class is a thin wrapper, the test still protects the module-specific SSRF policy contract: browser config keys must correctly feed URL validation for blocked hostnames, private networks, and allowlist bypass behavior.

### `tests/Unit/Modules/Core/AI/Console/Commands/BrowserStatusCommandTest.php`

- **Disposition:** keep
- **Reason:** it protects the operator-facing command contract around argument validation, failed lookups, failure-reason rendering, and company/session views. The assertions are output-oriented, but they match the public surface of the command rather than its internal wiring.

### `tests/Unit/Modules/Core/AI/Console/Commands/BrowserSweepCommandTest.php`

- **Disposition:** keep
- **Reason:** it is small and low-cost, and it still protects the command's observable behavior for the two real branches that matter: no-op sweep versus expiring stale sessions.

### `tests/Unit/Modules/Core/AI/Services/ControlPlane/HealthAndPresenceServiceTest.php`

- **Disposition:** keep
- **Reason:** it protects real policy derivation rather than plumbing: tool readiness versus health, provider verification freshness, and agent health/presence computed from sessions and recent runs. The file is mock-heavy at the service boundary, but the behavior under test is still the control-plane contract.

### `tests/Unit/Modules/Core/AI/Services/ControlPlane/LifecycleControlServiceTest.php`

- **Disposition:** keep
- **Reason:** it exercises the control-plane lifecycle surface across preview, execute, persistence, and failure recording. The breadth is justified because the service coordinates multiple destructive operations and audit behavior behind one public interface.

### `tests/Unit/Modules/Core/AI/Tools/MessageToolTest.php`

- **Disposition:** keep
- **Reason:** despite its size, the file protects the public tool contract rather than its internals: action validation, channel capability gating, company-context enforcement, outbound-service routing, and limit handling across the supported message actions.

### `tests/Unit/Modules/Core/AI/Tools/ScheduleTaskToolTest.php`

- **Disposition:** keep
- **Reason:** it covers the real CRUD surface of the scheduling tool, including company-context gating, service-level validation failures, not-found handling, and argument normalization for add/update/remove/status flows.

### `tests/Unit/Modules/Core/AI/Services/ToolReadinessServiceTest.php`

- **Disposition:** tighten
- **Reason:** the file already covered the main readiness states, but it barely exercised `allSnapshots()` and only proved one conditional-tool name. The current pass extends it so the suite protects metadata-bearing snapshots and both known conditional tools.

### `tests/Unit/Modules/Core/AI/Services/AgenticToolLoopStreamReaderTest.php`

- **Disposition:** tighten
- **Reason:** the file had value but only covered the happy streaming path. The current pass adds the runtime-error return branch so the extracted stream reader is tested as both an accumulator and a failure boundary.

### `tests/Unit/Modules/Core/AI/Services/AgenticFinalResponseStreamerTest.php`

- **Disposition:** tighten
- **Reason:** the file previously covered only the client-action prepend path. The current pass adds the runtime-error and empty-response branches, which exposed and then fixed a real production bug where stream errors fell through into the empty-response handler.

### `tests/Unit/Modules/Core/AI/Services/DispatchTranscriptBridgeTest.php`

- **Disposition:** keep
- **Reason:** it protects the user-visible transcript contract for delegated background work: success and failure outcomes must be mirrored back into Lara's session with the correct target labeling and summary/error text.
