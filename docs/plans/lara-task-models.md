# Lara Task Models

**Status:** In Progress  
**Last Updated:** 2026-04-13  
**Sources:** `app/Modules/Core/AI/Livewire/Concerns/ManagesChatSessions.php`, `app/Modules/Core/AI/Services/ConfigResolver.php`, `app/Modules/Core/AI/Livewire/Concerns/ManagesAgentModelSelection.php`, `app/Modules/Core/AI/Livewire/Setup/Lara.php`, `app/Modules/Core/AI/Livewire/Setup/Kodi.php`, `app/Modules/Core/AI/Livewire/Playground.php`, `app/Modules/Core/AI/Config/menu.php`, `app/Modules/Core/AI/Routes/web.php`, `resources/core/views/livewire/admin/setup/lara.blade.php`, `docs/architecture/ai/agent-model.md` (§2.9 two system primitives, §15.2 config.json shape), `docs/architecture/ai/lara.md`

## Problem Essence

Lara currently treats all LLM work as if it should use the same chat model path. Session title generation therefore uses Lara's primary model, even though titling is a much smaller auxiliary workload with different latency and quality needs. At the same time, the product still carries Kodi and Agent Playground as separate surfaces even though the agreed direction is Lara-only for all users. Cost metadata is not yet reliable enough to drive deterministic automatic selection from catalog data alone, and users should not be expected to manually track which connected models are best suited to these narrow tasks.

## Desired Outcome

Lara becomes the only visible AI agent and configuration surface for end users. Primary and Backup remain Lara's chat execution and failover contract. Auxiliary workloads are handled through two patterns unified under one concept — **Lara Tasks** — distinguished by execution complexity:

- **Simple tasks** are single-inference workloads with no tools or agentic loop. The only decision is which model handles the call. `titling` is the first simple task.
- **Agentic tasks** are multi-step workloads where Lara delegates to a nameless sub-agent with a framework-defined execution profile (system prompt, scoped tool set, iteration limits). `coding` and `research` are agentic tasks.

Both patterns share the same config shape (mode + provider/model per task) and the same UI surface. Users configure model selection per task without needing to know whether the runtime executes a direct inference or spawns a sub-agent. The minimum shipped task set is `titling`, `research`, and `coding`. By default, Lara's primary model recommends which connected model should handle a given task, BLB validates the choice against the currently connected models, and task configuration lives on a dedicated page whose menu entry appears only once Lara is activated. Direct visits before activation should explain that Lara must be configured first.

## Public Contract

- Lara workspace config keeps `llm.models[]` for chat execution only.
- Auxiliary workloads live under `llm.tasks.*`.
- The initial shipped Lara task profiles are `titling`, `research`, and `coding`.
- **Lara Tasks** is the umbrella product concept; **Task Models** is the specific configuration UI for choosing which model handles each Lara task.
- Lara is the only user-facing agent and only user-facing AI chat surface.
- Kodi setup and Agent Playground are removed. Kodi as a named employee is deprecated and ultimately removed once current runtime call sites are rerouted to Lara task profiles. The `coding` sub-agent profile replaces Kodi's functional role. Sub-agents are ephemeral workers defined by their task profile, not pre-provisioned employee records.
- Task Models is a separate user-facing page. Its menu entry is available only when Lara is activated; direct visits before activation show an activation-required notice.
- Each task is classified as either **simple** (single inference, no tools) or **agentic** (sub-agent delegation with own prompt and tools). The classification is framework-defined, not user-configurable. Both types share the same config shape and UI.
- All tasks support a small, explicit mode set:
  - `primary` — use Lara's primary model directly
  - `recommended` — administrator asks Lara's primary model to recommend a model, then saves that recommendation as the task's current stable choice
  - `manual` — administrator picks provider/model explicitly
- Recommendation never creates or persists an unknown provider/model pair. BLB only accepts a recommendation that resolves to an active connected model for the licensee company.
- Recommendation is on-demand, not re-evaluated automatically at task runtime. Once saved, the task keeps using the stored choice until the user refreshes or changes it.
- Task config is persisted in Lara's workspace `config.json` alongside `llm.models[]`. Each task entry carries `mode` (`primary`, `recommended`, or `manual`), and for `recommended`/`manual` modes a stable saved `provider`/`model` pair plus an optional `reason` string for UI display.
- The initial shipped UI exposes `Titling`, `Research`, and `Coding` together on the dedicated Task Models page. `titling` is fully wired as a simple task, and both `coding` and `research` are wired as Lara agentic execution profiles for delegated background work.
- Agentic task execution profiles (system prompt template, tool set, iteration limits) are framework-defined and versioned with BLB, not user-configurable. They live in the codebase (e.g., `app/Modules/Core/AI/Resources/tasks/`), not in workspace config. Users only configure model selection per task.

## Top-Level Components

### Config resolution

`ConfigResolver` gains a task-specific resolution path that reads `llm.tasks.<name>` and falls back cleanly when the task config is missing or invalid. The existing primary/backup resolution path remains unchanged for chat. The resolver is agnostic to task type (simple vs agentic) — it returns model config either way.

### Task registry

A framework-level registry defines all known Lara tasks with their metadata: name, display label, type (simple or agentic), description, and for agentic tasks a reference to their execution profile. The registry is the single source of truth for which tasks exist and what type they are. The UI, resolver, and recommendation service all consult it.

### Sub-agent execution profiles

Each agentic task has a framework-defined execution profile that specifies the sub-agent's behavior when Lara delegates to it. Profiles are versioned with BLB and not user-configurable:

- **System prompt template** — task-specific instructions (e.g., code-focused prompt for `coding`, research-focused prompt for `research`)
- **Tool set** — which tools the sub-agent can use, expressed as authz capabilities (e.g., `coding` gets bash and file tools; `research` gets navigation and search)
- **Constraints** — max iterations, timeout, temperature override if the task warrants it

Sub-agents are ephemeral — they don't have employee records, separate persistent workspaces, or persistent sessions of their own. They execute under Lara's delegation using the current user's authorization scope (same as Lara's own access model in `lara.md` §9.3), and they reuse Lara's workspace context plus per-run scratch/artifact storage where needed. Results flow back into Lara's conversation.

### Recommendation service

A dedicated service prepares the connected-model shortlist, asks Lara's current primary model to recommend the best model for a named task, validates the answer, and returns a normalized provider/model selection plus a short explanation for UI display. The recommendation prompt is task-aware: it includes the task type and workload description so the model can distinguish between recommending a cheap/fast model for simple tasks and a capable model for agentic tasks. Recommendation runs only when the user explicitly asks for it or when BLB seeds an unset task during setup, not on every task execution.

### Lara setup UI

The Lara setup page remains focused on activation plus chat execution and failover configuration. It shows a compact summary of task-model status and links to the separate Task Models page once Lara is activated.

### Task Models page

A dedicated Task Models page appears under the AI menu only when Lara is activated. It is the user-facing configuration surface for Lara Tasks and owns task-specific model selection for `Titling`, `Research`, and `Coding`, with recommendation and manual override flows per task. The page does not expose the simple/agentic distinction directly — it presents all tasks uniformly as "which model should handle this work."

### Task-model selection state

The existing `ManagesAgentModelSelection` trait is coupled to the primary/backup two-slot pattern and writes exclusively to `llm.models[]`. Task-model selection therefore needs its own read/write state for `llm.tasks.*` entries with mode, provider, model, and reason. The existing provider/model picker Blade partial can be reused, but the persistence and hydration logic is distinct.

### Titling call site

Session-title generation switches from `resolvePrimaryWithDefaultFallback()` to the task-specific resolver so the task model is actually used. This is a simple task — direct `LlmClient::chat()` call with the resolved model, no agentic loop.

### Surface consolidation

Menu entries, routes, docs, and setup flows stop presenting Kodi and Agent Playground as product surfaces. Kodi's employee record and workspace are deprecated — the `coding` sub-agent profile replaces its functional role. A new Task Models menu entry appears only after Lara activation.

## Design Decisions

### D0: Collapse the visible AI product surface to Lara only

Kodi and Agent Playground were exploratory surfaces and are not part of the chosen product direction. Rather than carrying multiple visible agents and per-agent setup flows, BLB should present one visible agent, Lara, and express specialization through task profiles under Lara. Kodi's functional role (code generation, IT tickets) is absorbed by the `coding` agentic task profile — a nameless sub-agent that Lara delegates to, rather than a named system primitive the user configures separately. `Employee::KODI_ID` and its seeder are deprecated, but the record should not be physically removed until current Kodi-targeting runtime call sites have been rerouted to Lara task/profile execution.

### D1: `titling` is the first concrete task profile

Use `llm.tasks.titling`, not `session_title` and not `naming`. `session_title` is too UI-specific and `naming` is too broad. `titling` stays close to the actual workload while leaving room for later short-label/title use cases.

### D2: Recommendation is advisory, validation is deterministic

The primary model is used to choose among connected candidates because current frontier models are strong at capability matching and this avoids burdening users with model churn. However, the model does not get to invent IDs. BLB supplies the candidate list, validates the response, and only persists a known active model. Recommendation is an on-demand selection helper, not a hidden runtime policy: once a recommendation is accepted, the saved provider/model pair remains stable until refreshed or changed by the user.

### D3: Keep chat failover separate from auxiliary task selection

Primary and Backup continue to model chat execution and runtime failover only. Task models are a separate concern and should not be represented as a third peer beside Primary and Backup. This avoids muddying the existing fallback contract and keeps the Lara setup page understandable.

### D4: Separate chat setup from task setup

Primary/Backup and activation stay on the Lara page. Task-specific model configuration moves to its own page because `titling`, `research`, and `coding` are enough to justify a dedicated surface. This keeps Lara setup readable and avoids turning activation into a dense matrix of per-task options.

### D5: Ship all three minimum tasks together, wire titling first

There is no product reason to defer `research` and `coding` if the information architecture, resolver, and recommendation flow already need to support named task profiles. The first implementation should ship `Titling`, `Research`, and `Coding` together so the user sees the real task-model concept immediately rather than a partial placeholder. `titling` (a simple task) is fully wired, and both `coding` and `research` are now wired as Lara agentic task profiles. Recommendation and model-selection behavior stay shared across all three tasks even though the runtime patterns differ.

### D6: Recommendation should degrade safely

If recommendation fails because the primary model is unavailable, returns an invalid answer, or no suitable connected candidate exists, Lara falls back to a safe path rather than blocking setup:
- if task mode is `recommended`, the resolver falls back to `primary`
- the UI shows that recommendation could not be completed and allows manual override
- runtime never retries recommendation implicitly; it only uses the saved stable choice or the documented fallback

### D7: Keep the user-facing explanation short and concrete

The recommendation result should include a short reason such as low-latency suitability, small-output workload fit, or stronger summarization/labeling behavior. The UI should show that rationale next to the saved choice so users understand why Lara picked that model without reading provider docs.

### D8: Specialization is task-shaped with two execution tiers, not persona-shaped

`coding` and `research` are part of the minimum shipped task set, and `review` is a likely next follow-up. These should extend `llm.tasks.*` under Lara rather than resurrecting a second visible system agent. The key architectural distinction is between simple tasks (model routing for single inferences) and agentic tasks (sub-agent delegation with own prompt, tools, and loop). Both tiers share the same config surface and model selection UX — the execution tier is a framework concern, not a user concern. This keeps the UX simple while enabling deep internal specialization: a simple task like `titling` is a direct LLM call, while an agentic task like `coding` spawns a full sub-agent via `AgenticRuntime` with specialized tools and prompting.

### D9: Sub-agents are ephemeral, not provisioned

Sub-agents spawned for agentic tasks do not have employee records, separate persistent workspaces, or persistent identity. They are instantiated from a framework-defined execution profile, run under Lara's delegation with the current user's authorization scope, reuse Lara's workspace context plus per-run scratch/artifact storage, and discard state when done. This avoids the overhead of pre-provisioning named agents (Kodi pattern) and keeps the authorization model simple — sub-agent permissions are bounded by the delegating user's capabilities, exactly as Lara's own access works (`lara.md` §9.3). If a future use case needs persistent sub-agent state (e.g., memory across coding sessions), it can be introduced as an extension to the execution profile, not as a return to named employee agents.

## Phases

### Phase 1 — Collapse UI to Lara only

- [x] Remove Kodi setup and Agent Playground from the user-visible menu and route surface
- [x] Remove the dead Kodi setup Livewire component and Blade view now that the public route already redirects to Lara setup
- [ ] Audit UI copy, help text, and docs that still describe Kodi or the Playground as current product surfaces
- [ ] Deprecate Kodi as a product surface immediately: remove setup Livewire component, route, Blade view, menu entry, and seeder provisioning. Mark `Employee::KODI_ID` as deprecated in code.
- [ ] Inventory every runtime call site that still targets Kodi directly and plan its reroute to Lara task/profile execution before removing the underlying employee record
- [x] Consolidate architecture doc updates: update `agent-model.md` (§2.9 deprecate Kodi as system primitive, §15.2 `config.json` shape to show `llm.tasks.*`), `lara.md` (Lara as only visible agent, add sub-agent delegation concept), and `current-state.md` (remove Kodi/Playground surface references)
- [x] Add the target information architecture to docs: `AI > Lara` for activation/chat config, `AI > Task Models` for task-specific model selection after Lara activation

### Phase 2 — Define task contract and registry

- [x] Define the task type enum: `simple` (single inference) and `agentic` (sub-agent delegation)
- [x] Build the task registry with the three initial tasks: `titling` (simple), `coding` (agentic), `research` (agentic) — each with name, display label, type, and workload description
- [x] Extend workspace config semantics to document `llm.tasks.titling`, `llm.tasks.research`, and `llm.tasks.coding`
- [x] Define the shared persisted shape for `primary`, `recommended`, and `manual` modes — identical across simple and agentic tasks (mode + optional provider/model/reason)
- [x] Add task-resolution methods to `ConfigResolver` without changing the existing primary/backup chat path
- [ ] Define the sub-agent execution profile contract for agentic tasks: prompt template path, tool capability list, max iterations, optional parameter overrides. Profiles are framework-defined, not user-configurable.
- [x] Update architecture documentation so Primary/Backup remain the chat-only contract and Lara Tasks (simple + agentic) are the specialization mechanism

### Phase 3 — Implement recommendation flow

- [x] Add a task-model recommendation service that gathers active connected candidates for the licensee company
- [x] Build a constrained recommendation prompt for Lara's current primary model that includes task intent, required output shape, and exact candidate IDs
- [x] Make the recommendation input task-specific so `titling`, `research`, and `coding` can describe different workload needs
- [x] Validate and normalize the returned provider/model pair before saving
- [x] Persist the selected task configuration plus a short explanation suitable for UI display
- [x] Define the refresh semantics explicitly in UI and runtime: recommendation runs on demand and saves a stable chosen model
- [x] Add safe fallback behavior when recommendation fails or returns an unusable model

### Phase 4 — Wire titling runtime

- [x] Change session-title generation to resolve the `titling` task model instead of always using Lara's primary model (simple task — direct `LlmClient::chat()`, no agentic loop)
- [x] Preserve current behavior as fallback when no task config exists yet
- [x] Add focused tests for recommended/manual/primary resolution across all three tasks, plus invalid recommendation fallback
- [x] Verify that `coding` and `research` config resolves correctly before their agentic execution profiles are wired

### Phase 5 — Add task-model UI

- [x] Keep the Lara setup page focused on activation, Primary Model, and Backup Model
- [x] Add a separate `Task Models` page and route with Lara activation guard semantics (menu-gated; direct visits show an activation-required notice)
- [x] Add a new menu entry for `Task Models`, gated on Lara activation
- [x] Expose task rows/cards for the minimum planned set: `Titling` (simple, fully wired), `Research` (agentic, runtime wired), and `Coding` (agentic, runtime wired)
- [x] Build dedicated task-model state/persistence handling for `llm.tasks.*` with mode, provider, model, and reason
- [x] Reuse the existing provider/model picker Blade partial for manual mode instead of inventing a new picker surface
- [x] Show a compact task-model summary on the Lara page with a link to the dedicated page
- [x] Keep the page clean when recommendation is unavailable or not yet run

### Phase 6 — Verify and leave room for follow-up tasks

- [x] Add Livewire/UI tests covering the `Titling`, `Research`, and `Coding` setup flows
- [x] Add gating tests covering the shipped activation guard semantics for Task Models (menu-gated; activation-required notice before Lara activation; working setup flows after activation)
- [x] Confirm Lara activation still works with no task config present
- [x] Document likely future Lara task profiles beyond the minimum set as follow-up candidates only, not shipped scope: `review` (agentic), `summarization` (simple), `translation` (simple), `classification` (simple), `routing` (simple)

### Phase 7 — Wire agentic task runtimes (follow-up)

- [x] Implement the first sub-agent execution profile for `coding` (prompt template, tool allowlist, background execution policy)
- [x] Add the first delegation flow: when `/delegate` cannot route to a supervised agent, Lara falls back to the `coding` task profile via `AgenticRuntime` with the configured `llm.tasks.coding` model
- [x] Implement the `research` execution profile (prompt template, tool set, constraints)
- [x] Extend delegation flow so Lara can choose among multiple task profiles instead of falling back only to `coding`
- [x] Reroute ticket-update actor fallback from Kodi to Lara so coding/research task profiles no longer attribute ticket work to `Employee::KODI_ID`
- [ ] Reroute current Kodi-targeting runtime call sites and ticket/delegation flows to Lara task/profile execution, then remove `Employee::KODI_ID` and any remaining Kodi-owned workspace/runtime assumptions
- [ ] Define how sub-agent tool results and multi-step output are presented in Lara's chat thread
- [ ] Add tests for sub-agent delegation, authorization scoping, and result surfacing
- [x] Update Task Models UI to reflect fully wired status for `research`

### Future considerations

- Reassess whether `recommended` should remain the default task mode once reliable capability/cost metadata exists in the model catalog. At that point, deterministic selection from catalog data may be preferable to an LLM-driven recommendation.
- Evaluate whether agentic sub-agents need persistent state (e.g., memory across coding sessions). If so, extend the execution profile contract rather than returning to named employee agents.
- Consider whether sub-agent execution should be visible to the user (showing delegation in the chat thread) or transparent (Lara presents results as her own). The industry trend is toward visible delegation with attribution.
