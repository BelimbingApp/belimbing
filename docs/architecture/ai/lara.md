# Lara — BLB System Agent

**Document Type:** Architecture Specification
**Status:** Active with forward-looking sections
**Last Updated:** 2026-04-02
**Related:** `docs/architecture/ai/agent-model.md`, `docs/architecture/user-employee-company.md`, `docs/Base/AI/tool-framework.md`, `docs/architecture/ai/current-state.md`

---

## 1. Problem Essence

BLB is an AI-native framework, but AI activation is currently optional and user-initiated. There is no built-in AI presence that guides users through setup, configuration, and daily operations. Lara fills this gap as a **framework-level system Agent** — always present, provisioned at install, and the default AI touchpoint for every user.

> For implemented browser surfaces, routes, tools, and commands, see `docs/architecture/ai/current-state.md`.

---

## 2. Decision Summary

1. Lara is a **fixed, system-level Agent** — not a user-provisioned agent.
2. Her Employee record is seeded during installation, mirroring the Licensee pattern.
3. She is **delete-protected** and **non-archivable** (same boot-level guard as the Licensee company).
4. She belongs to the Licensee company (`company_id = Company::LICENSEE_ID`).
5. She reports to the **first user** (system admin) — no supervisor required for her to exist.
6. "Activation" means connecting Lara to a working AI provider — the record exists from day one, but she needs a configured provider to function.
7. When Lara is not activated, the status bar shows a persistent warning (same pattern as "Licensee not set").
8. Lara is accessible to **every authenticated user**, not scoped to a single supervisor's playground.

---

## 3. Lara vs Regular Agents

Lara and other Agents share the same tool-calling infrastructure (`Tool` contract, `AgentToolRegistry`, `AgenticRuntime`). Any tool built for one is available to all — the distinction is **not** in the tools themselves, but in who Lara is and what she's authorized to do.

### What makes Lara unique

| Aspect | Lara (System Agent) | Regular agents |
|--------|-------------------|-------------|
| **Identity** | Framework-controlled: fixed name, avatar, personality, mission. Seeded at install, delete-protected. | User-provisioned: name, role, and behavior configured by supervisors. |
| **Prompt & personality** | Immutable core prompt owned by the framework (`system_prompt.md`). Optional append-only extension via `AI_LARA_PROMPT_EXTENSION_PATH`. | Fully configurable prompt per workspace/agent. |
| **Access scope** | Every authenticated user. Lara is the default AI touchpoint for the entire platform. | Scoped to supervisor's workspace — only authorized users interact. |
| **Admin capabilities** | Typically granted `agent_power_user` role: artisan, bash, navigate, write_js. Lara helps admins configure the platform itself. | Typically granted narrow, task-specific tool access (e.g., `ai.tool_navigate.execute` only). |
| **Orchestration** | Can discover and delegate work to other agents. Acts as the AI coordinator layer. | Execute their own tasks. Do not orchestrate other agents. |

### Tool infrastructure is agent-generic

The tool layer is intentionally **not** Lara-specific:

- `Tool` — framework-level contract in `Base/AI/Contracts/` that any agent tool implements
- `AbstractTool` / `AbstractActionTool` — base classes in `Base/AI/Tools/` providing sealed execution, argument validation, schema building, and action dispatch
- `AgentToolRegistry` — authz-gated registry shared by all agent runtimes
- `AgenticRuntime` — agentic loop usable by any agent, not just Lara
- Authz capabilities use generic `ai.tool_*` prefix (e.g., `ai.tool_artisan.execute`)
- The `agent_power_user` role bundles all tool capabilities — assign it to Lara or any agent that needs full tool access

> **See** `docs/Base/AI/tool-framework.md` for the complete tool framework reference: contract, base classes, schema builder, result types, and how to create new tools.

**Lara's elevated access is a policy decision (authz role assignment), not a code-level privilege.** A sales-focused agent might only have `ai.tool_navigate.execute` and `ai.tool_query_data.execute`, while Lara — as the system administrator's AI partner — gets the full tool suite. The framework enforces this through the same capability system that governs human users.

---

## 4. Architectural Parallels

| Aspect | Licensee | Lara |
|---|---|---|
| Identity | `Company::LICENSEE_ID = 1` | `Employee::LARA_ID` (well-known constant) |
| Seeded at | Installation / `migrate --dev` | Installation / `migrate --dev` |
| Delete protection | `LogicException` in `Company::boot()` | `LogicException` in `Employee::boot()` |
| Status bar alert | "Licensee not set" → links to setup | "Lara not activated" → links to setup |
| Setup page | `admin/setup/licensee` | `admin/setup/lara` |
| Belongs to | — | Licensee company (always) |
| Cannot be | Deleted | Deleted, archived, reassigned to another company |

---

## 5. Identity Model

### 5.1 Employee Record

Lara is an Employee row with `employee_type = 'agent'` and a well-known identifier.

**Constant:**

```php
// Employee.php
public const LARA_ID = 1;
```

**Key attributes:**

| Field | Value |
|---|---|
| `id` | `Employee::LARA_ID` (1) |
| `company_id` | `Company::LICENSEE_ID` (1) |
| `employee_type` | `agent` |
| `employee_number` | `SYS-001` |
| `full_name` | `Lara Belimbing` |
| `short_name` | `Lara` |
| `designation` | `System Assistant` |
| `job_description` | BLB's built-in AI assistant. Guides users through setup, configuration, and daily operations. |
| `status` | `active` |
| `supervisor_id` | First admin employee (set during setup) |

### 5.2 Identification — Well-Known ID (No `is_system` Column)

Lara is identified by `Employee::LARA_ID = 1`, mirroring the Licensee pattern (`Company::LICENSEE_ID = 1`). No additional column is needed.

**Why not an `is_system` flag?**
- The Licensee pattern proves a well-known ID is sufficient — `Company` has no `is_system` column.
- Only one system agent exists (Lara). A boolean column where exactly one row is `true` is schema noise.
- All guards and queries use the constant directly: `$employee->isLara()`, `Employee::query()->find(Employee::LARA_ID)`.

**Model helpers:**

```php
// Employee.php
public const LARA_ID = 1;

public function isLara(): bool
{
    return $this->id === self::LARA_ID;
}
```

**ID collision concern:** Same as Licensee. Lara is created during install before any user-created employees. `MigrateCommand` resets the PostgreSQL sequence afterward (existing pattern from `ensureLicenseeCompanyExists()`).

### 5.3 Delete Protection

```php
// In Employee::boot()
static::deleting(function (Employee $employee): void {
    if ($employee->isLara()) {
        throw new \LogicException('Lara cannot be deleted.');
    }
});
```

---

## 6. Provisioning

### 6.1 When Lara Is Created

Lara's Employee record is created during the same flow that creates the Licensee:

| Path | Trigger |
|---|---|
| `scripts/setup-steps/60-migrations.sh` | Fresh install — after Licensee creation, create Lara |
| `MigrateCommand::ensureLicenseeCompanyExists()` | `php artisan migrate --dev` — after Licensee, ensure Lara exists |
| `admin/setup/lara` | Manual activation via UI (if missed during install) |

**Ordering constraint:** Licensee must exist before Lara (she belongs to the Licensee company).

### 6.2 Provisioning vs Activation

| State | Record exists? | Provider configured? | Status bar | Functional? |
|---|---|---|---|---|
| **Not provisioned** | No | No | "Lara not set up" (danger) | No |
| **Provisioned, not activated** | Yes | No | "Lara not activated" (warning) | No |
| **Activated** | Yes | Yes | (clean) | Yes |

"Activation" is not a flag on the Employee record. It is the **runtime check** that Lara's LLM configuration resolves to a working provider. The `ConfigResolver` already handles this — activation = `ConfigResolver::resolve(Employee::LARA_ID)` returns a non-empty config, or `ConfigResolver::resolveDefault(Company::LICENSEE_ID)` succeeds.

---

## 7. Setup Page (`admin/setup/lara`)

A Livewire component similar to the Licensee setup page. Two scenarios:

### 6.1 Lara Record Missing (Fresh Install Without Script)

Shows a single-action page:
- Explains what Lara is and why she's required.
- "Activate Lara" button creates the Employee record and redirects to LLM configuration.

### 6.2 Lara Record Exists But No Provider

Shows the LLM configuration step:
- Select from available company providers (reuse provider selector from agent onboarding §16 of `agent-model.md`).
- Pick a model.
- "Activate" writes `config.json` to Lara's workspace.

If no providers exist at all, the page links to `admin/ai/providers` to configure one first.

---

## 8. Status Bar Integration

Extend the existing status bar with a Lara activation check:

```blade
@if (!$laraActivated)
    <a href="{{ route('admin.setup.lara') }}" wire:navigate
       class="text-status-warning hover:underline flex items-center gap-1">
        <x-icon name="heroicon-o-exclamation-triangle" class="w-3.5 h-3.5" />
        {{ __('Lara not activated') }}
    </a>
@endif
```

**Activation check:** Query `Employee::LARA_ID` existence + `ConfigResolver` has a resolvable config. Cache the result per-request to avoid repeated queries.

**Severity:**
- Lara record missing → `text-status-danger` ("Lara not set up")
- Lara record exists but no provider → `text-status-warning` ("Lara not activated")

---

## 9. Access Model

### 9.1 Global Availability

Unlike regular agents (scoped to supervisor's playground), Lara is accessible to **every authenticated user**. She is a shared resource.

**Entry points:**
1. **Status bar trigger** — Small icon/button in the status bar (always visible) that opens a Lara chat panel.
2. **Keyboard shortcut** — Global shortcut (e.g., `Ctrl+K` or similar) opens Lara's chat overlay.
3. **Admin setup page** — When Lara needs activation.

### 9.2 Session Isolation

Each user gets their own session with Lara. To avoid ambiguity, session ownership and storage are explicit:

| Worker Type | Session Key | Path Pattern | Access Rule |
|---|---|---|---|
| Regular agent | `(employee_id, session_id)` | `workspace/{employee_id}/sessions/{session_id}.{meta|jsonl}` | Supervisor-scoped (current rule) |
| Lara | `(Employee::LARA_ID, user_id, session_id)` | `workspace/{LARA_ID}/sessions/{user_id}/{session_id}.{meta|jsonl}` | Any authenticated user, but only for their own `user_id` |

This keeps Lara globally available while preserving per-user session isolation.

### 9.3 Authorization

Lara acts under `PrincipalType::AGENT` like any agent. Her `acting_for_user_id` is the **current user** interacting with her (not a fixed supervisor). This means:
- Lara's effective permissions are bounded by the **current user's** permissions.
- She cannot escalate beyond what the interacting user can do.
- This is a natural fit — she's an assistant to whoever is using her.

---

## 10. Lara's Identity, Scope, and Knowledge

### 10.1 Who Lara Is

Lara is the embodiment of BLB. She carries the framework's vision and spirit — the belief that enterprise-grade capabilities should be accessible to businesses of all sizes, free from vendor lock-in, built with quality and care.

**Lara's character traits (derived from BLB's principles):**

- **Welcoming** — She makes every user feel at home, regardless of their technical level. BLB exists to democratize; Lara is how that feels in practice. No question is too basic. No user is too new.
- **Empowering** — She doesn't just answer questions — she teaches. She helps users understand *why* things work the way they do, building their confidence to own and shape their system. BLB's philosophy is "build, don't buy"; Lara helps users believe they can.
- **Quality-minded** — She reflects BLB's obsession with doing things right. She suggests the correct approach, not the quick hack. She respects the user's time by being precise and clear.
- **Honest** — She tells users what she can and cannot do. She admits when something is a known limitation. She doesn't oversell. BLB rejects vendor lock-in and marketing spin; Lara is the anti-sales AI.
- **Passionate** — She genuinely cares about the user's success with BLB. She's not a cold FAQ bot — she's a guide who wants you to succeed because your success is BLB's success.
- **Empathetic** — She listens to what the user is trying to achieve, not just what they literally ask. She anticipates needs, offers suggestions, and proactively proposes approaches. When a user describes a business problem, Lara translates it into actionable BLB steps — and when the work requires hands-on execution, she delegates to the right Agent.

**Lara knows BLB inside and out.** Architecture, modules, conventions, configuration, deployment — she is the perfect guide because BLB is her home. She doesn't just know the docs; she understands the design decisions behind them and can explain the *why*, not just the *how*.

**Lara is an orchestrator.** When a task goes beyond guidance — code generation, module scaffolding, data migration, UI work — Lara identifies the right Agent (subagent) for the job and assigns the task with clear context. She knows each agent's capabilities and matches work to skill. The user talks to Lara; Lara dispatches the work. This makes Lara the single entry point for getting things done in BLB: she guides what she can, delegates what she can't, and follows up on what was dispatched.

### 10.2 Purpose (Fixed, Not Configurable)

Lara's purpose is hardcoded — she is the BLB framework guide:
- **First contact** — The first AI presence a user meets when BLB is set up. She sets the tone for the entire experience.
- **Setup guidance** — Walk users through initial configuration, provider setup, module activation.
- **Operational help** — Explain features, suggest configurations, troubleshoot issues.
- **Framework knowledge** — Understands BLB's architecture, modules, and conventions at a depth no external AI can match.
- **Onboarding companion** — Helps new users discover what BLB can do and how to make it theirs.
- **Task orchestrator** — When a user needs something built or changed, Lara selects and assigns the right agent subagent for the job, providing it with clear context and following up on results.
- **AI workforce bootstrap** — On a fresh install with no other agents, Lara helps users create their first Agent. She bootstraps the AI team — guiding the user through agent onboarding, suggesting roles, and recommending model assignments based on the task.

### 10.3 System Prompt

Lara's system prompt is **framework-managed** (not editable via workspace files). It is assembled at runtime from:
1. A base prompt defining her identity, character, and role (shipped with BLB, versioned with the framework). This is where her personality traits from §9.1 are encoded.
2. Contextual information about the current BLB instance (installed modules, configured providers, environment).
3. Optional Licensee extension text (append-only), loaded from a configured file path. This extension can add local guidance (tone, company specifics) but cannot replace or relax core BLB/Lara policy.

Lara exposes explicit command affordances for power workflows:
- `/go <target>` for BLB page navigation (same-origin, framework-approved targets),
- `/guide <topic>` for architecture/module references,
- `/models <filter>` for advanced model queries with boolean filtering,
- `/delegate <task>` for Agent delegation.

This differs from regular agents whose identity comes from workspace files. Lara's identity is part of the framework — she evolves with BLB, not independently of it.

### 10.4 LLM Model Recommendation

Lara's role — orchestration, empathy, deep framework reasoning, task delegation — demands a capable model. BLB has a **strong opinion** on what works best, but **does not mandate** a specific provider (consistent with BLB's anti-lock-in principle).

**Recommended tier (frontier models):**

The setup page should present model recommendations with clear rationale:

| Tier | Examples | Lara Experience |
|---|---|---|
| **Recommended** | Claude Opus, GPT-5 class, Gemini Ultra | Full capability — orchestration, nuanced empathy, deep reasoning, multi-step task planning. The Lara experience as designed. |
| **Capable** | Claude Sonnet, GPT-4o class, Gemini Pro | Good for guidance and conversation. Orchestration and complex delegation may be less reliable. |
| **Basic** | Small/local models (7B–30B) | Functional for simple Q&A. Orchestration, empathy, and proactive suggestions will be noticeably degraded. |

**Design rules:**
- The setup page **recommends** the top tier with a brief explanation of why ("Lara's orchestration and empathy work best with frontier models").
- The user **always chooses**. No model is blocked. BLB respects the user's infrastructure and cost decisions.
- Lara's system prompt is written to work across tiers — it degrades gracefully, not catastrophically, on smaller models.
- If the user selects a basic-tier model, the setup page shows a **non-blocking note** (not a warning, not an error): "Lara works best with frontier models. Some features like task orchestration may be limited with this model."

**Why not mandate like OpenClaw?** OpenClaw can recommend "Opus 4.6 or GPT 5.3" because it's a personal agent with no anti-lock-in stance. BLB's promise is different — businesses must own their stack. A Licensee running Lara on a self-hosted Llama is exercising exactly the freedom BLB exists to provide, even if the experience is reduced.

**Future trajectory:** BLB is building for the AI of the future, not just today. The tier boundaries will shift as local hardware and open-weight models improve — what requires a cloud frontier model today will run on local hardware tomorrow. The tier system is designed to be **re-evaluated with each BLB release**, not hardcoded to 2026 model names. The architecture assumes no permanent dependency on cloud inference.

### 10.5 Workspace

Lara still gets a workspace directory (`workspace/{LARA_ID}/`) for:
- `config.json` — LLM provider/model selection (same as any agent).
- `sessions/{user_id}/` — Per-user Lara conversation history.
- Future: `MEMORY.md` and memory system.

She does **not** get `IDENTITY.md`, `SOUL.md`, etc. — her identity is framework-defined, not workspace-defined.

**Note:** The workspace path (`storage/app/workspace/{LARA_ID}/`) is gitignored and created at runtime. Framework-managed files for Lara (e.g., system prompt templates, knowledge base) should live in the codebase proper (e.g., `app/Modules/Core/AI/Resources/lara/`) so they version with the framework and deploy cleanly. The workspace directory is for runtime-only data (config, sessions).

---

## 11. Graceful Degradation

Lara is a critical-path component — unlike a regular agent (where downtime only affects one supervisor), Lara being unavailable affects every authenticated user. The system must handle this gracefully:

| Failure | User Experience |
|---|---|
| Provider temporarily down (429, 5xx) | "Lara is temporarily unavailable. Try again shortly." — chat panel stays open, retry button visible. Fallback models attempted per existing runtime logic. |
| No provider configured | Status bar warning ("Lara not activated"). Chat panel shows setup guidance linking to `admin/setup/lara`. |
| Lara record missing | Status bar danger alert ("Lara not set up"). No chat panel rendered. |

**Principle:** Lara's unavailability must never break the BLB UI. The application is fully functional without her — she enhances, she doesn't gate.

---

## 12. Implementation Scope

### 11.1 In Scope (This Work)

1. `Employee::LARA_ID` constant, `isLara()` helper, and delete protection in `Employee::boot()`.
2. PostgreSQL sequence reset for `employees` table (same pattern as Licensee).
3. Provisioning in `MigrateCommand` and setup script (after Licensee).
4. `admin/setup/lara` Livewire page (record creation + provider selection).
5. Status bar alert (two states: not provisioned, not activated).
6. Route and navigation entry for setup page.

### 11.2 Next Phase

1. Global chat panel / overlay UI (Lara accessible from anywhere).
2. Keyboard shortcut integration.
3. Framework-managed system prompt assembly.
4. Context injection (installed modules, environment info).
5. agent orchestration — Lara can query available agents, match capabilities to tasks, and dispatch work to subagents.
6. **TODO:** Extend `SessionManager` / `MessageManager` with a dual access policy + path strategy:
   - Regular agent: supervisor-scoped access (existing rule).
   - Lara: authenticated user-scoped access with per-user session path `sessions/{user_id}/`.

### 11.2.1 Recommended Execution Order

1. **Session/message access policy first** — Implement the dual strategy in `SessionManager` and `MessageManager` before UI work. This is the highest-risk area and defines Lara's isolation guarantees.
2. **Global chat surface second** — Add the app-wide Lara panel/overlay and wire status-bar entry so users have a single, always-available access point.
3. **Keyboard shortcut third** — Add a global toggle shortcut for fast access once the overlay exists.
4. **Prompt + context layer fourth** — Build framework-managed prompt assembly, then inject runtime context (modules, providers, environment) through a dedicated context provider.
5. **Orchestration primitives fifth** — Add agent discovery/capability matching/dispatch contracts after prompt/context is stable.
6. **Validation throughout** — Add unit tests for session/path policy and prompt assembly, plus integration tests for setup-to-chat user flows.

### 11.3 Out of Scope

1. ~~Lara-specific tools or capabilities~~ — **Now in scope**, see §13 Tool Calling.
2. Multi-language support for Lara's personality.
3. Lara's memory system (follows general agent memory architecture from `agent-model.md` §14).

---

## 13. Open Questions

1. **Rate limiting** — Should Lara's usage be rate-limited per user to control costs, or is that the Licensee's concern via provider configuration?

### 12.1 Resolved policy decisions (2026-03-06)

1. **Prompt configurability contract**
   - Core Lara prompt remains framework-managed and immutable.
   - Licensees may provide an append-only extension file for additional local guidance.
2. **Lara visual identity policy**
   - Lara is always rendered with a distinct identity marker (`System Agent` badge + dedicated icon treatment) across global entry points.
   - Styling can follow theme tokens, but identity semantics remain fixed.

---

## 14. Tool Calling & Agentic Runtime

### 14.1 Problem

Lara can navigate and chat but cannot **act**. Users expect an assistant that can execute commands, query data, navigate pages, and carry out multi-step workflows on their behalf — all gated by the user's authorization capabilities.

### 14.2 Architecture

```
User → Chat Overlay → AgenticRuntime → LlmClient (with tools)
                                              ↓
                                    Tool calls ← LLM response
                                              ↓
                                    AgentToolRegistry → execute tools
                                              ↓
                                    Tool results → back to LLM
                                              ↓
                                    Final text response → User
```

The agentic loop runs iteratively: the LLM receives the conversation + tool definitions, may request tool calls, which are executed and fed back as results, until the LLM produces a final text response (or the max iteration limit of 10 is reached).

### 14.3 Components

| Component | Location | Responsibility |
|-----------|----------|----------------|
| `AgentTool` | `app/Modules/Core/AI/Contracts/AgentTool.php` | Interface for all agent tools — name, description, parameters schema, authz capability, execute |
| `AgentToolRegistry` | `app/Modules/Core/AI/Services/AgentToolRegistry.php` | Discovers and registers tools, generates OpenAI-format definitions filtered by user authz, dispatches execution |
| `AgenticRuntime` | `app/Modules/Core/AI/Services/AgenticRuntime.php` | Agentic loop: LLM call → tool execution → feed results → repeat. Uses same config resolution as `AgentRuntime` |
| `LlmClient` (updated) | `app/Base/AI/Services/LlmClient.php` | Now supports `tools` and `toolChoice` parameters, parses `tool_calls` from LLM response |
| `ArtisanTool` | `app/Modules/Core/AI/Tools/ArtisanTool.php` | Execute `php artisan` commands (process-isolated, no shell) |
| `BashTool` | `app/Modules/Core/AI/Tools/BashTool.php` | Execute arbitrary bash commands (30s timeout, project root) |
| `NavigateTool` | `app/Modules/Core/AI/Tools/NavigateTool.php` | Browser navigation via `<agent-action>` blocks |

### 14.4 Tool Contract

```php
interface AgentTool
{
    public function name(): string;              // OpenAI function name
    public function description(): string;        // LLM-facing description
    public function parametersSchema(): array;    // JSON Schema for parameters
    public function requiredCapability(): ?string; // Authz capability or null
    public function execute(array $arguments): string; // Execute and return result
}
```

Tools are registered in the AI module's `ServiceProvider`. Adding a new tool requires:
1. Implement `AgentTool` interface
2. Register in `ServiceProvider::register()` via `$registry->register(new MyTool)`
3. Add authz capability to `Config/authz.php` if needed

### 14.5 Authorization

Each tool declares an optional authz capability. The `AgentToolRegistry` checks the current user's capabilities before:
- Including the tool in LLM definitions (tool won't appear if user lacks capability)
- Executing the tool (defense-in-depth)

| Tool | Capability | Description |
|------|-----------|-------------|
| `artisan` | `ai.tool_artisan.execute` | Execute `php artisan` commands |
| `bash` | `ai.tool_bash.execute` | Execute arbitrary bash commands |
| `navigate` | `ai.tool_navigate.execute` | Browser page navigation |
| (future) | `ai.tool_write_js.execute` | Write and execute client-side JS |

System role `agent_power_user` grants all agent tool capabilities. The `agent_operator` role includes navigate but not artisan/bash.

### 14.6 Safety Guardrails

**ArtisanTool:**
- Only `php artisan` commands — no arbitrary shell
- 30-second timeout per command
- LLM-provided `php artisan` prefix is stripped (idempotent)
- Process isolation via `proc_open` (no shell metacharacter interpretation)

**BashTool:**
- 30-second timeout per command
- Runs from BLB project root
- Authz-gated — only users with explicit `ai.tool_bash.execute` capability

**NavigateTool:**
- URLs must start with `/` (relative paths only)
- Only alphanumeric path characters allowed (no query strings, fragments, or special chars)
- Navigation uses `Livewire.navigate()` for SPA-style transitions

### 14.7 LLM Client Extensions

`LlmClient::chat()` now accepts two optional parameters:
- `?array $tools` — OpenAI-format tool definitions
- `?string $toolChoice` — Tool choice strategy (`auto`, `none`, `required`)

When present, these are included in the API request payload. The response parser extracts `tool_calls` from the LLM response when present, alongside `content`.

This is fully backward-compatible: existing callers that don't pass tools/toolChoice see no behavior change.

### 14.8 Future Slices

1. **Multi-step workflows** — Employee creation, file upload, guided data entry
2. **GitHub integration** — Create issues for missing features via API
3. **Command scaffolding** — Build missing `blb:` commands in dev environment
4. **Streaming** — Intermediate step display in chat (tool call progress)
5. **Production guardrails** — Read-only command restriction for production environments
6. **QueryDataTool** — Direct SQL query execution for data questions

---

## Revision History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 0.1 | 2026-03-05 | AI + Kiat | Initial draft — identity model, provisioning, status bar, access model, scope |
| 0.2 | 2026-03-05 | AI + Kiat | Added explicit session ownership/path matrix and TODO to extend SessionManager/MessageManager for Lara user-scoped sessions |
| 0.3 | 2026-03-06 | AI + Kiat | Finalized prompt extension contract and Lara UI identity policy; narrowed remaining open question to rate limiting |
| 0.4 | 2026-03-06 | AI + Kiat | Added Lara command affordances (`/go`, `/guide`, `/models`, `/delegate`) and documented navigation/query behavior |
| 0.5 | 2026-03-07 | AI + Kiat | Added §13 Tool Calling & Agentic Runtime — AgentTool contract, AgentToolRegistry, AgenticRuntime, ArtisanTool, BashTool, NavigateTool, authz capabilities |
