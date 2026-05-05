# Lara: Collapse Primary/Backup Into Provider Priority

**Status:** Complete (Phases 1–5)
**Last Updated:** 2026-05-03
**Agents:** claude/opus-4-7
**Sources:** `app/Modules/Core/AI/Livewire/Setup/Lara.php`, `resources/core/views/livewire/admin/setup/lara.blade.php`, `app/Modules/Core/AI/Livewire/Providers/Providers.php`, `resources/core/views/livewire/admin/ai/providers/providers.blade.php`, `app/Modules/Core/AI/Services/ConfigResolver.php`, `app/Modules/Core/AI/Services/AgentRuntime.php`, `app/Modules/Core/AI/Tools/WebSearchTool.php`, `app/Modules/Core/AI/Services/Workspace/WorkspaceResolver.php`, `app/Modules/Core/AI/Enums/WorkspaceFileSlot.php`, `app/Modules/Core/AI/Resources/lara/system_prompt.md`, `app/Modules/Core/AI/Livewire/Concerns/ManagesChatSessions.php`, `app/Modules/Core/AI/Livewire/Chat.php`, `app/Modules/Core/User/Models/User.php`, `docs/plans/lara-task-models.md`

## Problem Essence

Lara setup asks the user to declare a Primary and a Backup model, but the AI Providers page already encodes the same intent through provider priority — and even advertises priority as a fallback chain in its help text. The reality is messier: the resolver only consumes priority to elect a single default winner; the cross-provider runtime fallback the help promises does not actually happen for agents, and the one place it does happen (`WebSearchTool`) is its own ad-hoc loop. Two surfaces, two mental models, and a runtime that silently retries `llm.models[]` on transient errors so the difference between "primary worked" and "backup saved you" is not visible to the user. Compounding this, the in-chat model picker only persists per session, so a deliberate switch to a stronger or cheaper model feels lost the next time the user starts a new chat. Meanwhile the genuinely Lara-specific concern — her harness (system prompt, operator context, tool notes) — has no operator-facing surface at all, even though the framework already supports workspace overrides for every harness slot.

## Desired Outcome

Provider priority is the single declaration of "which provider/model wins as the default." It is **not** a runtime failover chain. When a provider fails, the failure surfaces honestly to the user — no silent retry against a different provider, no `fallback_attempts` array hiding the real story. The in-chat model picker remains the place where a user expresses "for this conversation, use that model"; the *last* such choice becomes the default for the next new session for that user, so explicit picks stick across sessions without a separate setup field.

The Lara setup page stops pretending to be a model picker and becomes what it should always have been: an operator surface for **Lara's harness** — the prompt files that define her behavior. Operators can see each harness slot, view its current contents, see whether it is the framework default or a workspace override, edit it, and revert it. The override mechanism is the existing two-layer `WorkspaceResolver` (workspace dir overrides framework default); no new storage concept is introduced. Model selection and execution controls live elsewhere; the Lara page is about who Lara *is*, not which engine she runs on.

## Public Contract

### Provider priority and runtime

- Provider priority on `admin/ai/providers` is the canonical declaration of "which provider/model is the default." It is **not** a runtime fallback chain. The help text on that page reflects this exactly.
- `ConfigResolver` returns a single config end-to-end. The list-shape return for `llm.models[]` consumers goes away with the migration. `resolvePrimaryWithDefaultFallback()` is renamed to `resolveDefault($employeeId)` since there is no primary anymore.
- `AgentRuntime::run()` makes one model call. On failure, it returns the error directly. The retry-across-models loop and `meta.fallback_attempts` go away.
- `WebSearchTool` consults `ConfigResolver` for its provider/model the same way every other consumer does. Its internal priority-driven cross-provider loop is removed; failures throw honestly.

### Workspace and last-used hint

- Lara workspace config no longer carries `llm.models[]`. Existing configs are migrated forward in a single idempotent step: per-model execution controls move to their new home (Phase 2), and the rest of the array is dropped.
- Per-user "last used model" is stored on the `User` row (the entity that already keys AI activity via `acting_for_user_id`). Chat session creation seeds the picker from this hint, falling through to the priority chain when the hint is absent or no longer points to an active connected model.
- The chat picker writes to two places when the user picks a model: the per-session override (existing behavior) and the user-scoped last-used hint.

### Execution controls

- Per-model execution controls live on `AiProviderModel` with a per-session override slot on the chat session. The Lara setup page no longer hosts execution-controls UI.

### Task models

- `TaskModelSelectionMode::Primary` is **removed**, not renamed. Modes shrink to `recommended` and `manual`. Missing or unset task config falls through to the same default-resolution path as chat: user's last-used hint → priority #1 default. Saved configs with `mode=primary` upgrade by unsetting `mode` so they collapse into the default path naturally.

### Lara setup page (harness inspector)

- The Lara setup page exists to view and override Lara's harness files. It does not pick models, declare backups, or host execution controls.
- The harness inspector lists each `WorkspaceFileSlot` whose `isPromptContent()` is true (`system_prompt`, `operator`, `tools`, `extension`). The `memory` slot is metadata-only and excluded from the inspector.
- For each slot the inspector shows: slot name, source badge (Framework / Workspace / Missing), and the rendered file content. The source field is already on `WorkspaceFileEntry`.
- Per-slot operator actions: **Override** (copies the framework default into Lara's workspace dir and opens the editor), **Edit** (slot is already overridden), **Revert to framework default** (deletes the workspace file; resolution falls back through `WorkspaceResolver` automatically). Revert is the always-available escape hatch when an edit goes wrong.
- The page also surfaces activation status: idempotent provisioning of Lara's employee record, and a precondition check that at least one active provider exists in the priority chain. When the precondition fails, the inspector remains visible but is read-only and an alert links to the providers page.
- "Tools" in this context is the notes-file slot from `WorkspaceFileSlot`, not tool wiring. Tool registration remains code-defined; the page does not pretend to expose it.
- Task Models is cross-linked from the Lara page but lives at its existing dedicated route (`admin.ai.task-models`). The Lara page no longer parents the task models concept.

## Top-Level Components

### Resolver shrinks back to a single config

`ConfigResolver` continues to return one config and one config only — the top-priority active provider with its default (or first active) model. The temptation to return a list of configs across providers is rejected: that would re-create the failover chain we are deliberately removing. `resolve()`'s list-shape return for agents reading `llm.models[]` goes away with the migration; the resolver becomes single-config end to end. The `resolvePrimaryWithDefaultFallback()` name is replaced with `resolveDefault()` to match the new mental model.

### Runtime drops cross-model retry

`AgentRuntime::run()` resolves once, calls once, and surfaces the result — including failures — directly. The fallback-attempts loop, the per-attempt timing collection, and the `meta.fallback_attempts` payload are removed. Anything reading `fallback_attempts` (UI panels, logs, telemetry) is swept in the same change.

### WebSearchTool stops being special

`WebSearchTool` currently keeps its own list of search providers and walks them on failure. After this change it asks `ConfigResolver` for the same default the rest of the system uses, makes one call, and lets failures bubble. This collapses the second meaning of "priority" in the codebase down to one.

### Providers-page help text becomes accurate

The help block on `admin/ai/providers` is rewritten so "priority" is described as "which provider/model is the default" — full stop. The misleading "falls back to the next one" sentence is removed. The ↑ arrow keeps its current behavior.

### User-scoped last-used hint

The hint lives on the `User` row, not the workspace and not the employee. Reasoning: Lara is one workspace shared across all users of a licensee company, but only users (not employees in general) interact with agents, and AI activity is already keyed by `acting_for_user_id`. Storing the hint at workspace scope would let user A's switch override user B's default; storing it on employee would mis-scope it relative to who actually drives sessions. A single JSON `prefs` slot on `User` keyed by agent (`prefs.lara.last_used_model = {provider, model}`) is the smallest contract that does the job. Validate on read against the active connected models; ignore stale hints silently.

### Execution controls move off positional `llm.models[N]`

Today, per-model execution controls hang off `llm.models[0].execution_controls` and `llm.models[1].execution_controls` — positional slots that disappear once primary/backup are gone. Recommendation: attach controls to `AiProviderModel` so they are shared across all agents that use that model, with the chat session retaining a per-session override slot for one-off tuning ("think harder for this conversation"). A keyed-by-`{provider}/{model}` map in workspace config is the alternative — only worth it if per-agent tuning matters more than cross-agent reuse. This decision is finalized in Phase 2 before Phase 3 deletes the cards that currently host the controls.

### Lara setup page becomes a harness inspector

The page is rebuilt around `WorkspaceResolver`'s existing two-layer model. For each prompt-content slot, the inspector renders the effective file with its source badge and offers Override / Edit / Revert. Override copies the framework default into the workspace dir; edit writes to the workspace dir; revert deletes the workspace file so the framework default takes effect again. No new resolution concept; no new storage layer. The activation gate (employee provisioning + active-provider precondition) is the only non-harness UI on the page and lives as a single status block at the top.

### Task models drop the `primary` mode

`TaskModelSelectionMode::Primary` is removed entirely. `ConfigResolver::resolveTaskWithPrimaryFallback()` loses its primary branch and is renamed to `resolveTask()`; tasks with no saved provider/model resolve through the same default path as everything else. Saved configs with `mode=primary` are migrated by unsetting `mode`.

## Design Decisions

- **No silent retries**: when a provider fails, fail. The cost of one bad call is far smaller than the cost of users not knowing which provider actually answered. This is the load-bearing decision the rest of the plan flows from.
- **Single source of truth for "what runs"**: priority on the providers page, period. Anything that needs a default consults `resolveDefault()`. No duplicate "Lara primary" concept. `WebSearchTool` is brought into line with this rule.
- **Sticky model selection lives in the chat picker, not setup**: the user already expresses model preference there per session; making it the default for new sessions just removes a step. A separate setup field would re-create the duplication this plan removes.
- **Last-used scope is `User`**, because only users use agents and AI activity is already keyed by `acting_for_user_id`. Workspace scope would mis-share across users; employee scope would mis-target the entity that does not actually drive sessions.
- **Execution controls on `AiProviderModel`** is preferred over per-agent positional storage. The controls describe properties of the model under a given provider (which thinking modes work, what max output makes sense), so sharing across agents is the right default. Per-session override covers the per-conversation tuning case.
- **`mode=primary` is deleted, not renamed**: the concept is gone, so the enum case goes with it. Saved configs migrate by unsetting `mode`.
- **The Lara page reason-to-exist is the harness, not models**: model concerns belong on the providers page; harness concerns are genuinely Lara-specific and have no other home. The two pages stop competing and start complementing.
- **Reuse `WorkspaceResolver`'s two-layer override model verbatim**: no new "operator override" abstraction. Workspace dir wins; framework default fills the gap. Revert is "delete the workspace file."
- **Harness inspector ships with edit + revert in the first cut**, since override capability is the explicit requirement. Preview-mode rendering of the assembled prompt and an audit trail of edits are flagged as future work, not blockers.
- **`memory` slot stays out of the inspector**: it is metadata, not prompt content (`isPromptContent()` returns false). Exposing it would invite confusion about what editing it does.
- **Tool wiring stays code-defined**: the `tools` slot is a notes file, and the inspector treats it as such. No promise of editable tool registration.
- **No new "fallback list editor" UI**: there is no fallback list to edit. The priority controls (↑ arrow per provider) on the providers page are the only ordering surface.

## Phases

### Phase 1 — Remove silent fallback; add user-scoped last-used hint

Goal: stop pretending priority is a runtime chain, surface failures honestly, and make the in-chat picker feel persistent.

- [x] Strip the cross-model retry loop from `AgentRuntime::run()`. Resolve once, call once, return the result directly. Remove `fallback_attempts` from the meta payload. {claude/opus-4-7}
- [x] Sweep call sites and views that read `meta.fallback_attempts`; delete dead UI/log paths cleanly rather than leaving them tolerant of an empty array. Also extended to `AgenticRuntime` (cross-config retry, streamWithFallbackConfigs, helpers), `RuntimeResponseFactory`, `MessageManager`, `ChatRunPersister`, `RunRecorder`, `RunInspection`, `AiRun` model, `InspectRunCommand`, chat banner UI, message-meta/assistant-result/error components. Deleted `TranscriptFallbackBannerAttemptResolver` and the now-unused `AgenticFinalResponseStreamer` + tests. Schema change applied destructively to the original `0200_02_01_000013_create_ai_runs_table` migration (column removed in place); no follow-up DDL migration. {claude/opus-4-7}
- [x] Refactor `WebSearchTool` to use a single resolved provider and remove its internal cross-provider fallback loop. Failures bubble. (Note: WebSearchTool's "providers" are search providers, not LLM providers — `ConfigResolver` does not apply; the fix is to pick the highest-priority enabled search provider and call only that one.) {claude/opus-4-7}
- [x] Rename `ConfigResolver::resolvePrimaryWithDefaultFallback()` to `resolveDefault($employeeId)` and update call sites. The previous company-default `resolveDefault(int $companyId)` was renamed to `resolveCompanyDefault()`. The list-shape `resolve()` and `resolveWithDefaultFallback()` were removed; the resolver is now single-config end-to-end. {claude/opus-4-7}
- [x] Added a JSON `prefs` column on `users` (destructively, in the original `0200_01_20_000000_create_users_table` migration) plus an array cast on `User`. Helpers `getLastUsedModel($agentKey)` and `setLastUsedModel($provider, $model, $agentKey)` validate the hint against active connected models on read. {claude/opus-4-7}
- [x] Chat session creation seeds `selectedModel` from the user's hint when present (resolved to the composite `providerId:::modelId`) and falls through to null otherwise. {claude/opus-4-7}
- [x] `ManagesChatSessions::updatedSelectedModel()` writes the user-scoped hint alongside the existing per-session override. {claude/opus-4-7}
- [x] Rewrote the providers-page help block: priority decides the default, not a fallback chain; failure surfaces honestly. {claude/opus-4-7}

### Phase 2 — Decide and execute the execution-controls relocation

Goal: pick the home for per-model execution controls before deleting the Lara cards that currently host them.

- [x] Confirmed storage choice: `AiProviderModel.execution_controls` JSON column. Per-model controls describe properties of the model under a given provider, so sharing across agents is the right default; per-session override (chat) covers the per-conversation tuning case. {claude/opus-4-7}
- [x] Added the JSON `execution_controls` column on `ai_provider_models` destructively in the original `0200_02_01_000001_create_ai_provider_models_table` migration; no follow-up DDL migration. Model fillable + array cast updated. {claude/opus-4-7}
- [x] Resolver layers controls in this order: runtime defaults → `AiProviderModel.execution_controls` → workspace `llm.models[N].execution_controls` (legacy, removed in Phase 3) → session override. Applied in `resolveCompanyDefault()`, `resolveForProvider()`, and `resolveModelConfig()`. {claude/opus-4-7}
- [x] Surfaced controls editor on the providers page per-model row: gear/sliders icon next to the active checkbox opens a modal with the existing execution-controls partial; saves autosave on each change; "Reset to system defaults" clears the override; a small accent dot indicates an active override. {claude/opus-4-7}
- [x] Added per-session execution-controls override slot on `session.llm.execution_controls_override` with `SessionManager::updateExecutionControlsOverride()` / `getExecutionControlsOverride()`. `AgenticRuntime::run()`/`runStream()` accept a new `executionControlsOverride` parameter and layer it onto the resolved config via `applyExecutionControlsOverlay()`. `ChatTurnRunner` reads the override from the session and threads it through `ChatTurnRuntimeContext`. UI to *set* the override is deferred to Phase 5 polish. {claude/opus-4-7}
- [x] ~~Forward-only data migration to copy workspace `llm.models[N].execution_controls` into `ai_provider_models.execution_controls`.~~ **Removed as YAGNI** — no adopters and BLB's destructive workspace evolution means clean workspaces never carried these values. The new column starts empty and is populated by the providers-page editor. {claude/opus-4-7}

### Phase 3 — Pivot the Lara page to a harness inspector

Goal: replace the model-picker UI with the harness inspector, retire `llm.models[]` from workspace writes, and migrate forward idempotently.

- [x] Stripped the Primary Model and Backup Model cards from `livewire/admin/setup/lara.blade.php`. The `provider-diagnostics` partial was deleted (only Lara used it). The shared `llm-provider-model-picker` partial stays — Task Models still uses it. {claude/opus-4-7}
- [x] Removed the corresponding state and methods from `Lara.php`. The `ManagesAgentModelSelection` and `HandlesProviderDiagnostics` traits were the entire surface — both deleted. The Lara setup tests (`tests/Feature/AI/LaraSetupTest.php`) covered the deleted flow and were removed; tests for the new harness inspector are deferred. {claude/opus-4-7}
- [x] Added a harness inspector to the Lara page: one row per prompt-content `WorkspaceFileSlot` (excluding `memory`) with source badge (Workspace override / Framework default / Missing), filename, byte size, and per-slot actions. {claude/opus-4-7}
- [x] Wired per-slot actions: **Override** (copies the framework default into the workspace dir and opens the editor), **Edit/View** (opens the editor with current effective content), **Revert** (deletes the workspace file). Edits land in a textarea modal; saving writes to `workspace/{slot}.md`. Reverting an overridden slot deletes the workspace file so resolution falls back through `WorkspaceResolver` automatically. {claude/opus-4-7}
- [x] Reduced the activation surface to a single status block: idempotent `Employee::provisionLara()` runs on every mount, the page shows an Active/Inactive badge with the live winner from `resolveDefault()`, and an inline alert links to the providers page when no provider is in the priority chain. {claude/opus-4-7}
- [x] Dropped `llm.models[]` from workspace writes — the writeConfig path lived on the deleted `ManagesAgentModelSelection` trait, so it's gone with it. The resolver was simplified to consult only the company default; the legacy workspace `llm.models[0]` read path is removed (BLB's destructive migration evolution makes the "tolerance window" unnecessary — the workspace migration runs alongside the schema reset). {claude/opus-4-7}
- [x] ~~Forward-only data migration to strip `llm.models` from workspace `config.json`.~~ **Removed as YAGNI** — no adopters; clean workspaces under the new code path never write the key. {claude/opus-4-7}
- [x] The "Current Configuration" card was replaced by a slim status block on the Lara page that shows the live winner from `resolveDefault()` (provider/model live values, not a stored selection). The "Default" badge concept is gone — the priority chain *is* the configuration. {claude/opus-4-7}

### Phase 4 — Delete task mode `primary`

Goal: align the task models surface with the new mental model by removing the mode rather than renaming it.

- [x] Removed `TaskModelSelectionMode::Primary` from the enum (and its label branch). Surviving modes: `recommended`, `manual`. {claude/opus-4-7}
- [x] Removed the primary branch from `ConfigResolver::resolveTaskWithPrimaryFallback()` and renamed it to `resolveTask()`. Tasks with no saved provider/model fall through to `resolveDefault()` (priority chain). All call sites updated. {claude/opus-4-7}
- [x] ~~Forward-only data migration to unset `mode=primary` on workspace `llm.tasks.*` entries.~~ **Removed as YAGNI** — no adopters; the enum case is gone and clean workspaces cannot carry it. {claude/opus-4-7}
- [x] Stripped Primary mode from the Task Models page: removed the `Lara Primary` summary card, removed the "primary" branch from per-task render, simplified the "no saved selection" copy, updated activation alert to point to AI Providers instead of Lara setup. The task `Mode` dropdown now only renders the surviving enum cases. {claude/opus-4-7}
- [x] Updated `docs/plans/lara-task-models.md` to mark itself as superseded in part by this plan, drop the `mode=primary` line from the Public Contract, simplify the resolver paragraph (`resolveTask` returns `resolveDefault` when no saved selection), and refresh the Sources list. {claude/opus-4-7}

### Phase 5 — Harness inspector polish

Goal: reduce edit risk and improve operator confidence after the inspector ships.

- [x] Preview mode added to the slot editor as a tab. The "Preview Assembled Prompt" tab concatenates all prompt-content slots in load order, substituting the current draft for the slot being edited. Static workspace assembly only — runtime context (page, capabilities, JSON state) is injected at request time and is not part of the preview, with a note in the UI to that effect. Live-debounced from the textarea so the preview updates as the operator types. {claude/opus-4-7}
- [x] Per-slot audit metadata stored in a `{slot}.audit.json` sidecar next to the workspace file. Records `user_id`, `user_name`, `edited_at` (ISO), and `byte_size`. Written on Override and Save; deleted on Revert. The inspector table shows a new "Last Edit" column with `name · relative time` for overridden slots. {claude/opus-4-7}
- [x] Pre-save linting hooks surfaced as inline warnings beneath the editor (non-blocking; the operator can still save). Initial checks: required-slot empty content, system_prompt suspiciously short (<100 bytes), unbalanced ``` code fences, tabs-in-content advisory. Live-evaluated as the operator types via a `getEditorWarningsProperty` computed property. {claude/opus-4-7}

## Risks and Notes

- **Observability sweep**: removing `meta.fallback_attempts` means anything that reads it (admin dashboards, wire logs, telemetry exports) needs to be updated in the same change set. Grep widely; do not leave readers tolerant of an empty array, since the field is going away.
- **WebSearchTool behavior shift**: existing setups that relied on the tool walking multiple search providers will see hard failures where they used to see silent recovery. That is the intended outcome, but worth a release note.
- **Stale `last_used_model` hints**: a user may switch model, then the connected provider gets disconnected. The read path validates against active connected models and silently ignores invalid hints — no error surface for this case.
- **`User` prefs surface**: if `User` does not already carry a `prefs` JSON column, Phase 1 introduces one. Keep the schema small (`{ "lara": { "last_used_model": {"provider": "...", "model": "..."} } }`) and resist the urge to make this a general-purpose user-prefs system in this plan.
- **Backward read tolerance window**: BLB edits `create_*` migrations destructively and protects production tables with `is_stable` rather than chaining incremental DDL or wiping the database. Phase 3 deletes the legacy `llm.models[0]` read path outright; the file-based workspace migration (forward-only data port) handles the JSON cleanup independently of any DB schema flow.
- **Harness edit safety**: an operator can soft-brick Lara by saving a malformed `system_prompt.md`. Revert to framework default is the always-available escape hatch — its discoverability in the inspector matters. Preview mode and audit trail (Phase 5) reduce the blast radius further but are not blockers.
- **Workspace dir writability**: the inspector assumes Lara's workspace dir is writable by the web process. If `config('ai.workspace_path')` resolves somewhere read-only in some deployments, the inspector must surface a clear error rather than silently failing on Override / Edit / Revert.
- **Scope creep into tool wiring**: the `tools` slot is a notes file. Resist letting the inspector grow into a tool-registration surface; that is a separate decision with its own design.
