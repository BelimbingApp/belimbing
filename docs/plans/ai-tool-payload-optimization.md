# AI Tool Payload Optimization

**Agent:** Amp
**Status:** Phase 1 Complete, Phase 2 Near-Complete — Skill-based architecture drafted
**Last Updated:** 2025-04-21
**Sources:** `storage/app/ai/wire-logs/run_9A4JM8k82gI7.jsonl`, `app/Modules/Core/AI/Services/AgentToolRegistry.php`, `app/Modules/Core/AI/Services/AgenticRuntime.php`, `app/Modules/Core/AI/Services/LaraTaskExecutionProfileRegistry.php`, `app/Modules/Core/AI/Services/ChatToolProfileRegistry.php`, `app/Modules/Core/AI/Resources/lara/system_prompt.md`, `app/Base/Authz/Config/authz.php`

## Problem Essence

Every LLM request sends all 25 tool definitions (~23.8K chars) and a system prompt (~8.2K chars) regardless of whether the user's message needs them. Tool schemas account for 72% of the payload; the system prompt adds another 25%, of which ~29% (~2.4K chars) restates what tool definitions already describe. A simple "go to Users" turn carries the full schemas for `browser`, `bash`, `document_analysis`, `schedule_task`, etc. plus prose re-explaining how `navigate` and `artisan` work — wasting tokens, increasing latency, and diluting the model's attention.

## Desired Outcome

The tool set and system prompt sent per request are right-sized for both the interaction context and the user's authorized capabilities. Simple navigational or conversational turns carry only the tools they might plausibly need and a prompt free of tool-description duplication; additional capabilities are progressively discoverable when needed. Token spend drops by 50–70% for the majority of turns without sacrificing capability. The user's authz-assigned skills determine which tool groups are available, so the AI surface reflects what the user is actually permitted to do.

## Top-Level Components

- **Skill Registry** — first-class entity that bridges authz and AI. Each skill is a named bundle of tools, an authz gate capability, and optional behavioral prompt. Replaces the flat profile approach with authz-aware progressive disclosure.
- **Tool Profile Registry** — declares named tool subsets (profiles) for different interaction contexts. Phase 1 implementation; to be evolved into skill presets (pre-loaded skill combinations).
- **`load_skill` Meta-Tool** — lightweight tool included in the base set that lets the model request additional skill bundles on demand. Subsumes the old "lazy tool injector" concept with a richer, named-skill interface.
- **Intent Classifier** — lightweight mechanism that pre-loads the right skills before the main LLM call, avoiding the extra round-trip when the intent is clear.
- **System Prompt Tightener** — removes tool-description duplication and dead-code sections from the system prompt.
- **Composite Tool Consolidator** — merges related tools behind a single schema with an `action` discriminator.
- **Stored Tool Definitions** — provider-side caching of schemas so they aren't resent on every request.

## Design Decisions

### 1. Tool Profiles for Interactive Chat (lowest effort, highest immediate impact)

The `allowedToolNames` plumbing already exists end-to-end (`AgenticRuntime → AgentToolRegistry → LaraTaskExecutionProfileRegistry`), but interactive chat passes `null` (all tools). Define a small number of named profiles for the chat path — not just background tasks — and select one before calling the runtime.

Recommended starter profiles:

| Profile | Tools (~count) | When |
|---|---|---|
| `chat-core` | `navigate`, `visible_nav_menu`, `guide`, `system_info`, `active_page_snapshot`, `write_js`, `memory_get`, `memory_search` | Default for conversational / navigational turns |
| `chat-data` | `chat-core` + `query_data`, `edit_data`, `artisan` | Data questions, lookups, mutations |
| `chat-action` | `chat-data` + `notification`, `message`, `ticket_update`, `delegate_task`, `agent_list`, `schedule_task` | Action-oriented requests |
| `chat-full` | all 25 | Explicit request or escalation fallback |

Profiles compose additively. The registry should support inheritance (a profile can extend another) to avoid duplication.

### 2. System Prompt Tightening (lowest effort, immediate savings)

The system prompt in `system_prompt.md` (~8.2K chars rendered) has three categories of waste:

**Tool-description duplication (~1,230 chars).** The "Tool calling" section (lines 20–31) restates individual tool purposes (`artisan`, `visible_nav_menu`, `navigate`) that are already fully described in each tool's `description` field. The model already sees those descriptions via function definitions — repeating them in prose is pure duplication. Keep only behavioral guidance the tool descriptions *can't* convey: "prefer action over explanation", "side effects go through tool calls, not raw PHP blocks", "tools are authz-gated".

**Dead fallback section (~820 chars).** The "Browser actions (fallback for non-tool-calling)" section explains `<lara-action>` output blocks for models that can't do tool calling. Lara always runs with tool calling enabled; this section is dead code. Remove entirely.

**Verbose examples (~310 chars).** The "Proactive assistance" section spells out an example workflow ("How do I add an employee?" → artisan → navigate) that duplicates tool descriptions. Collapse to one behavioral line.

**Runtime context bloat (~2,260 chars).** The `knowledge` block in the runtime context JSON contains `default_references` and `query_references` — static documentation indexes that rarely change. These could move into the workspace `tools.md` slot (loaded once) or be served via the `guide` tool on demand, rather than serialized into every request.

Net savings: ~2,360 chars of prompt duplication removed (~29% of system prompt, ~7% of total payload). Combined with runtime context trimming, total prompt savings approach 4,600 chars (~56% of current prompt).

### 3. Intent Classification (two-pass routing)

Before the main LLM call, classify the user message to select a profile. Three implementation tiers, in order of preference:

- **Keyword/heuristic classifier** — regex or keyword map (e.g., "go to" / "navigate" → `chat-core`; "how many" / "show me" → `chat-data`; "send" / "notify" / "schedule" → `chat-action`). Zero latency, zero cost. Good enough for 80% of turns.
- **Small-model classifier** — a single cheap LLM call (e.g., GPT-4.1-mini or local model) with a one-shot prompt: "classify this user message into one of: core, data, action, full". Adds ~200ms and ~100 tokens. Handles ambiguous cases.
- **Hybrid** — heuristic first, small-model only when confidence is low.

The classifier returns a profile name; the runtime resolves it to `allowedToolNames`. Misclassification is safe because the lazy injector (approach 3) provides an escape hatch.

### 4. Lazy Tool Injection (meta-tool escalation)

Register a lightweight meta-tool `request_tools` in the core set. When the model realizes it needs a tool not in its current set, it calls `request_tools(category: "action")` and receives the additional tool definitions injected into the next turn. The runtime re-invokes the LLM with the expanded tool set for the remainder of that conversation turn.

This makes aggressive profile trimming safe — the model can always self-escalate. The `request_tools` schema itself is tiny (~200 chars) so the overhead of including it in every request is negligible.

Implementation: `request_tools` is a special tool that doesn't execute side effects — it returns a signal to the tool-calling loop in `AgenticRuntime.runToolCallingLoop()` to merge the requested profile's tools and re-invoke the LLM.

### 5. Composite Tool Consolidation (schema reduction)

Several tools share a domain and could merge behind an `action` parameter:

| Composite | Merges | Schema savings |
|---|---|---|
| `memory` | `memory_get`, `memory_search`, `memory_status` | 3 → 1 (~600 chars saved) |
| `data` | `query_data`, `edit_data` | 2 → 1 (~400 chars saved) |
| `delegation` | `delegate_task`, `delegation_status`, `agent_list` | 3 → 1 (~800 chars saved) |

Tradeoff: composite tools increase per-schema complexity and may slightly reduce model accuracy on parameter selection. Recommended only for the `memory` group (clear win) and `delegation` group. Keep `query_data` / `edit_data` separate — the read/write distinction is an important authz boundary.

### 6. Provider-Side Stored Tool Definitions (OpenAI Responses API)

The Responses API supports server-side tool storage, avoiding re-transmission of schemas. This is provider-specific and couples the tool surface to OpenAI's lifecycle. Investigate feasibility but treat as a bonus optimization, not a foundation — the architecture must also work for Anthropic and other providers.

Worth pursuing only after profiles (approach 1) and lazy injection (approach 3) are in place, since those reduce the number of tools regardless of provider.

### 7. Skill-Based Architecture (authz-aware progressive disclosure)

Skills unify Phase 1 profiles, Phase 4 lazy injection, and the existing authz system into one coherent model. The direction is goal-up, not authz-down:

**User goals / KPIs → Skills needed to accomplish them → Each skill defines capabilities + tools**

The skill is the **primary entity**. You don't start with "what capabilities does this user have?" — you start with "what does this user need to accomplish?" and assign skills accordingly. Each skill is a self-contained definition (`skill.md`) that carries its own tool set, gate capability, and behavioral guidance. This parallels BLB's workspace markdown approach and makes skills inspectable as documentation, not just code.

A **Skill** bridges three concerns:

- **Goal side:** each skill maps to a job function or operational need. "This user manages employee records" → assign `data` skill. "This user coordinates work across agents" → assign `delegation` skill.
- **AI side:** each skill bundles a set of tools and a behavioral prompt fragment (from `skill.md`). The `load_skill` meta-tool catalog shows only skills the user is assigned. Tools and prompt guidance are loaded together — the model gets context for *how* to use the tools, not just *what* tools exist.
- **Authz side:** each skill declares a gate capability. Assigning a skill to a user's role grants the gate capability; removing it revokes access. The `load_skill` catalog is filtered by gate capabilities before it reaches the LLM, so the model never learns about skills the user can't use.

**Motivation: authz-before-inference.** Currently, tool-level authz happens post-inference — the model calls a tool, the registry checks `requiredCapability()`, and returns "permission denied." This wastes a tool-calling round-trip and confuses the model into retries. With skill-level gating, unauthorized tools are invisible to the model.

**Natural alignment with existing authz roles.** The current authz config already defines two tool-capability tiers that map cleanly to skill bundles:

| Role | Current capabilities | Skill mapping (goal-oriented) |
|---|---|---|
| `agent_operator` | navigate, guide, system_info, query_data, memory, notifications, web, document/image analysis, delegation_status, agent_list | "Daily operational needs" → `navigation`, `memory`, `data` (read), `research`, `documents` |
| `agent_power_user` | operator + artisan, bash, browser, edit_data, message, delegate, schedule, ticket_update, edit_file, write_js | "Build and coordinate" → adds `data` (write), `communication`, `delegation`, `development` |

Skills don't replace these roles — they sit between roles and tools. A role represents a collection of goals; goals map to skills; skills define tools and capabilities. The existing roles become skill bundles: `agent_operator` = a preset of operational skills, `agent_power_user` = operational + power skills.

**Proposed skill definitions:**

| Skill | Goal it serves | Tools | Gate capability |
|---|---|---|---|
| `navigation` | Navigate the app and understand the UI | `navigate`, `visible_nav_menu`, `active_page_snapshot`, `guide`, `system_info`, `write_js` | `ai.skill_navigation.use` |
| `memory` | Remember context across conversations | `memory_get`, `memory_search`, `memory_status` | `ai.skill_memory.use` |
| `data` | Query and modify business data | `query_data`, `edit_data`, `artisan` | `ai.skill_data.use` |
| `communication` | Send messages and notifications | `message`, `notification`, `ticket_update` | `ai.skill_communication.use` |
| `delegation` | Coordinate and assign work to agents | `delegate_task`, `delegation_status`, `agent_list`, `schedule_task` | `ai.skill_delegation.use` |
| `documents` | Analyze documents and images | `document_analysis`, `image_analysis` | `ai.skill_documents.use` |
| `development` | Run commands and edit code/config | `bash`, `edit_file`, `browser` | `ai.skill_development.use` |
| `research` | Search the web and fetch external content | `web_fetch`, `web_search` | `ai.skill_research.use` |

**Progressive disclosure flow:**

1. Every request starts with the `navigation` skill tools (always loaded) plus the `load_skill` meta-tool.
2. The `load_skill` schema includes a catalog of available skills (names + one-line descriptions), filtered by the user's capabilities. The catalog itself is ~300 chars — vs. ~23.8K for all tool schemas.
3. When the model needs additional capabilities, it calls `load_skill(skill: "data")`. The runtime injects the skill's tool definitions and optional prompt fragment, then re-invokes the LLM with the expanded tool set.
4. Multiple skills can be loaded in one turn. A cap of 2 skill loads per turn prevents loops.
5. The intent classifier (Phase 3) pre-loads likely skills before the first LLM call, avoiding the round-trip for predictable turns.

**Effective payload per user role:**

| User role | Base load | Available via `load_skill` | Max tools |
|---|---|---|---|
| `agent_operator` | 7 (navigation + load_skill) | memory, data-read, research, documents | ~18 |
| `agent_power_user` | 7 (navigation + load_skill) | all 8 skills | ~26 |
| Typical navigational turn | 7 | none loaded | 7 |
| Typical data question | 7 + 3 (data pre-loaded) | — | 10 |

**Relationship to Phase 1 profiles.** The existing `ChatToolProfileRegistry` profiles (`chat-core`, `chat-data`, `chat-action`, `chat-full`) become **skill presets** — predefined combinations of skills pre-loaded for known interaction patterns. `chat-core` ≈ `navigation` skill only. `chat-data` ≈ `navigation` + `memory` + `data` pre-loaded. The profile registry remains as the mechanism that maps a preset name to a resolved `allowedToolNames` list.

**Skill definition format (`skill.md`).** Each skill is defined as a markdown file (e.g., `app/Modules/Core/AI/Resources/skills/data/skill.md`) that serves as both documentation and runtime configuration. The file declares the skill's goal, tools, gate capability, and behavioral prompt in a structured frontmatter + prose format. The `AiSkillRegistry` loads these files at boot, making skills inspectable and editable as documentation. This parallels BLB's workspace markdown approach (tool descriptions, system prompts) and the Amp skill pattern.

The `skill.md` behavioral prompt fragment is injected into the system prompt only when the skill is loaded — so the model gets targeted guidance for the tools it's actually using, not a monolithic prompt covering all 26 tools. This extends Phase 2's prompt tightening: instead of one system prompt trying to cover everything, behavioral guidance is distributed across skill definitions and loaded on demand.

**Layered skill resolution (framework → module → company).** BLB is a framework; each licensee's org structure, user goals, and KPIs are different. Skills follow the same three-tier pattern BLB already uses for authz roles and module discovery: framework defines defaults → modules extend → company customizes via database.

*Layer 1 — Framework skills.* BLB ships the 8 default skills above as `skill.md` files under `app/Modules/Core/AI/Resources/skills/`. These cover universal operational needs (navigation, data, communication, etc.) that every deployment gets. They are the "vocabulary" layer — sensible defaults that work out of the box.

*Layer 2 — Module skills.* Each module can register its own skills via auto-discovery. The framework scans `app/Modules/*/AI/Resources/skills/*/skill.md` at boot, the same way it discovers module service providers and authz capabilities. Examples: a Quality module ships a `quality-management` skill with NCR/CAPA/SCAR tools; a Ticketing module ships `ticket-triage` with triage and assignment tools. Module skills register their own gate capabilities in the module's `authz.php`. No changes to framework code needed — drop a `skill.md` in the right path and the skill appears.

*Layer 3 — Company customization (database).* Per-company overrides stored in the database, managed through an admin UI. A company admin can:

- **Disable** a framework or module skill for their org (e.g., disable `development` for non-technical teams)
- **Override** a skill's tool list or behavioral prompt (e.g., a stricter `data` skill prompt for a regulated industry)
- **Create** entirely new skills mapped to their org's specific KPIs and job functions
- **Assign** skills to roles or individual employees based on what each person needs to accomplish

This mirrors how authz works today: `authz.php` defines the vocabulary (capabilities, roles), the database stores assignments (which user has which role). For skills: `skill.md` files define the vocabulary (what skills exist, what tools they bundle), the database stores assignments (which company/role/employee has which skills) and overrides.

**What the licensee's admin controls:** which skills are enabled, who gets which skills, custom skills for their business processes, behavioral prompt overrides. **What they don't touch:** tool implementations (code), gate capability wiring (framework), `load_skill` runtime mechanics (framework).

**Implication for Phase 3 implementation:** start with Layer 1 only (file-backed framework skills, no database). Layer 2 (module discovery) follows when a second module needs skills. Layer 3 (company customization) follows when there's a licensee who needs it. The `AiSkillRegistry` interface should support all three layers from the start (query by company, accept overrides), but the initial implementation only reads from files.

## Phases

### Phase 1 — Chat Tool Profiles

Goal: reduce interactive chat tool payloads from 25 to ~8 tools for typical turns.

- [x] Design the profile data structure in a new `ChatToolProfileRegistry` with `ChatToolProfile` DTO supporting inheritance via `extends`
- [x] Define the four starter profiles (`chat-core` 8 tools, `chat-data` extends core +3, `chat-action` extends data +6, `chat-full` = all)
- [x] Wire `ChatTurnRunner` to resolve a profile via `ChatToolProfileRegistry` and forward `allowedToolNames` to `AgenticRuntime.runStream()`
- [x] Default to `chat-core` when no `tool_profile` is set in `runtime_meta`
- [x] Add `profile.selected` wire-log event with `profile_tools`, `effective_tools`, and `tool_count` in both streaming and non-streaming paths
- [x] Verify existing background task profiles (`research`, `coding`) are unaffected — `LaraTaskExecutionProfileRegistry` untouched

### Phase 2 — System Prompt Tightening

Goal: eliminate duplication between system prompt prose and tool descriptions; remove dead sections.

- [x] Remove per-tool descriptions from the "Tool calling" section (lines 26–28 of `system_prompt.md`) — keep only behavioral rules the tool schemas can't express
- [x] Delete the entire "Browser actions (fallback for non-tool-calling)" section — Lara always uses tool calling; also used wrong tag name (`<lara-action>` vs actual `<agent-action>`)
- [x] Collapse "Proactive assistance" into the Tool calling section as a single directive
- [x] Remove `knowledge.default_references` from runtime context JSON — the `guide` tool already serves these on-demand; `query_references` kept but only included when non-empty
- [x] Verify prompt renders correctly via `LaraPromptFactory::buildForCurrentUser()` in a test
- [ ] Compare wire-log payload sizes before and after

### Phase 3 — Skill Registry & Authz Integration

Goal: replace flat profiles with goal-driven, authz-aware skills so the tool surface reflects what each user needs to accomplish.

- [ ] Create `AiSkill` DTO: `key`, `label`, `goal` (one-line purpose), `tools` (list), `gateCapability` (string), `promptFragment` (optional string from `skill.md`)
- [ ] Create `skill.md` files for each of the 8 skills under `app/Modules/Core/AI/Resources/skills/<key>/skill.md` — each declares the skill's goal, tools, gate capability, and behavioral prompt
- [ ] Create `AiSkillRegistry` service that loads skill definitions from `skill.md` files at boot
- [ ] Add `ai.skill_*.use` gate capabilities to `app/Base/Authz/Config/authz.php` and map to `agent_operator` / `agent_power_user` roles
- [ ] Create `SkillResolver` that collects available skills for the current user by checking gate capabilities via `AuthorizationService`
- [ ] Refactor `ChatToolProfileRegistry` to resolve profiles as skill presets: `chat-core` = `navigation` only, `chat-data` = `navigation` + `memory` + `data`, etc.
- [ ] Verify tool-level `requiredCapability()` checks remain as a second defense layer (belt-and-suspenders with skill gating)
- [ ] Run authz seeder to sync new `ai.skill_*.use` capabilities

### Phase 4 — `load_skill` Meta-Tool (Progressive Disclosure)

Goal: let the model discover and load additional skills on demand, avoiding full-payload defaults.

- [ ] Create `LoadSkillTool` with schema: `skill` (enum of available skill keys), description includes one-line catalog of user's available skills
- [ ] `LoadSkillTool.execute()` returns a signal (not side-effects) to `AgenticRuntime` to inject the skill's tools and re-invoke the LLM
- [ ] Build dynamic tool schema: the `skill` enum and description are generated per-user from `SkillResolver` (only shows skills the user can use)
- [ ] Handle the injection signal in `AgenticRuntime.runToolCallingLoop()` and `runStreamingToolLoop()` — merge skill tools into current set, re-invoke LLM
- [ ] Include `load_skill` in the base navigation skill (always available)
- [ ] Cap skill loads at 2 per turn to prevent loops
- [ ] Add `skill.loaded` wire-log event with skill key and tool count
- [ ] Optional: attach skill-specific prompt fragment when a skill is loaded

### Phase 5 — Intent Classifier (Skill Pre-Loading)

Goal: pre-load the right skills before the first LLM call so the model doesn't need an extra round-trip for predictable turns.

- [ ] Implement keyword/heuristic classifier as a service (`ChatIntentClassifier`)
- [ ] Map classifier output to skill names (not profile names) — e.g., "how many employees" → pre-load `data` skill
- [ ] Integrate into `ChatTurnRunner` before runtime invocation: resolve skills, merge with base `navigation` tools
- [ ] Measure classification accuracy against wire-log history (spot-check recent runs)
- [ ] Optional: add small-model classifier tier for low-confidence cases

### Phase 6 — Composite Tool Consolidation

Goal: reduce total schema size for tools that naturally group.

- [ ] Merge `memory_get`, `memory_search`, `memory_status` into a single `memory` tool with `action` discriminator
- [ ] Merge `delegate_task`, `delegation_status`, `agent_list` into `delegation` tool
- [ ] Update skill definitions to reference consolidated tool names
- [ ] Verify model accuracy on composite tools with representative prompts

### Phase 7 — Provider-Side Stored Definitions

Goal: eliminate re-transmission of tool schemas for providers that support it.

- [ ] Investigate OpenAI Responses API stored tool support and lifecycle
- [ ] Prototype stored tool registration and reference-by-ID in `OpenAiResponsesRequestMapper`
- [ ] Measure payload size reduction vs. provider lock-in cost
- [ ] Decide go/no-go based on multi-provider strategy
