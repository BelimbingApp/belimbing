# AI Agent Architecture

**Document Type:** Architecture Specification
**Status:** Active foundational specification
**Last Updated:** 2026-04-13
**Related:** `docs/architecture/user-employee-company.md`, `docs/architecture/authorization.md`, `docs/architecture/database.md`, `docs/architecture/ai/current-state.md`, `docs/architecture/ai/lara.md`

---

## 1. Problem Essence

BLB needs Agents to be managed as first-class employees under the same organizational model and authorization system as humans, with clear delegation boundaries and accountable supervision.

> For the implementation-first view of what exists in code today, see `docs/architecture/ai/current-state.md`.

---

## 2. Decision Summary

1. Agent is an employee under the same management UI and org structure as human employees.
2. Human and Agent share one employee model/table.
3. Existing employee attributes are reused; non-applicable fields are nullable.
4. Add only minimal new employee fields now: `employee_type` (with value `'agent'`) and `job_description` (see §4.5).
5. Cost/token accounting is deferred to a future HR module.
6. Agent permissions are constrained by delegation and cannot exceed supervisor effective permissions.
7. **Agent context for execution:** OpenClaw-style workspaces (IDENTITY, SOUL, AGENTS, etc.) define “who” and “how”; BLB keeps a single `job_description` field as a short role label for now; full workspace-based context is the target when integrating an OpenClaw-like runtime.
8. **Per-agent and per-task LLM model selection:** Agent workspace `config.json` keeps `llm.models[]` for chat execution and failover. Lara's workspace can additionally define `llm.tasks.*` so specialized workloads such as titling, research, and coding can use different models (see §15).
9. **One visible system primitive:** Lara (`Employee::LARA_ID = 1`) is the only system agent provisioned by the framework and the only user-facing AI chat/configuration surface. Specialized work happens through Lara task profiles (`llm.tasks.*`) and ephemeral sub-agents, not through additional named system employees.

---

## 3. Public Interface

### 3.1 Workforce Subject Model

One subject model for authorization and org operations:

- `Employee` with `employee_type = 'agent'` for Agent; any other value (full_time, part_time, contractor, intern) denotes a human
- Both can be assigned roles and permissions
- Both can supervise subordinate employees
- In AuthZ, Agent is represented as principal type `agent` (`PrincipalType::AGENT`); same capability vocabulary as human actors.

### 3.2 Required Operations

1. `createEmployee(...)`
2. `updateEmployee(...)`
3. `assignSupervisor(employeeId, supervisorId)`
4. `assignRole(employeeId, roleId)`
5. `grantPermission(employeeId, capability)`
6. `revokePermission(employeeId, capability)`
7. `setJobDescription(employeeId, text)`
8. `disableEmployee(employeeId)`

### 3.3 Agent-Specific Management Operations

1. `createAgent(supervisorId, profile)`
2. `setAgentScope(employeeId, scope)`
3. `validateDelegation(supervisorId, subordinateId)`

The UI remains the same employee UI; Agent uses type-aware behavior, not a separate product surface.

### 3.4 Canonical Terms and Naming Alignment

This document follows the AuthZ canonical naming contract in `docs/architecture/authorization.md` §1.1:
1. Use `Agent` (not `PA`).
2. AuthZ actor type is `PrincipalType::AGENT` with persisted value `'agent'`.
3. Framework AI capabilities use `ai.agent.*`.
4. Delegation context links Agent actions to a human accountability chain (`actingForUserId` in current DTO, with future support for richer supervision metadata).

---

## 4. Employee Data Model (Current Scope)

### 4.1 Single Table Strategy

Use one `employees` table for both human and Agent records.

### 4.2 Minimal Additions

1. **Agent in employee_type:** Add `'agent'` as a valid value for the existing `employee_type` column. When `employee_type === 'agent'`, the row is a Agent; otherwise (full_time, part_time, contractor, intern) it is a human. No additional column. The model exposes `isAgent(): bool` (e.g. `return $this->employee_type === 'agent'`) and scopes `scopeAgent()` / `scopeHuman()` for convenience.
2. **job_description** (`TEXT`, nullable at DB): Short role label or summary for the Agent (e.g. “Customer support Agent”, “Leave approver”). Used for HR/UI and optional display in execution context. Full agent identity and behaviour are defined by an OpenClaw-style workspace when that runtime is adopted (see §4.5 and §13).

### 4.3 Attribute Applicability

- Existing attributes remain available for both types.
- Non-applicable attributes are `NULL`.
- `phone` remains nullable and valid for Agent (messaging channels now or later).
- `date_of_birth` is interpreted as employee identity birth date.
  - Human: biological date of birth.
  - Agent: identity creation date.

No additional Agent operational or financial fields are added at this stage.

### 4.4 Indicating Agent in the Employee Module

- **Schema:** No new column. The existing `employee_type` column accepts `'agent'` as a value; when `employee_type === 'agent'`, the row is a Agent. Other values (full_time, part_time, contractor, intern) denote human employees.
- **Model:** Expose `isAgent(): bool` (e.g. `return $this->employee_type === 'agent'`) and query scopes `scopeAgent($query)`, `scopeHuman($query)` so callers can do `Employee::query()->agent()->get()` or `$employee->isAgent()`.
- **UI:** In employee lists, show a badge (e.g. `<x-ui.badge variant="info">Agent</x-ui.badge>`) when `$employee->isAgent()`; in create/edit, add a control (e.g. checkbox or radio “Human / Agent”) that sets `employee_type` to 'agent' for a Agent or to an employment kind for a human. Filter the list by “Human only” / “Agent only” / “All” using `employee_type`.


### 4.5 job_description vs OpenClaw-Style Workspace

**OpenClaw workspace pattern** (from `~/.openclaw/workspace/` and similar): Agent identity and behaviour are defined by a **set of markdown files**, not a single text field:

| File | Purpose |
|------|---------|
| `IDENTITY.md` | Name, creature, vibe, emoji — "Who am I?" |
| `SOUL.md` | Core truths, boundaries, tone — "How I behave." |
| `USER.md` | Who the human is (name, timezone, context) — "Who I'm helping." |
| `AGENTS.md` | Session load order, memory strategy, act-vs-ask, safety, heartbeats — "How I operate." |
| `TOOLS.md` | Env-specific notes (SSH, TTS, devices) — "My cheat sheet." |
| `BOOTSTRAP.md` | One-time onboarding; deleted after first run. |
| `HEARTBEAT.md` | Periodic check prompts. |
| `MEMORY.md` | Long-term curated memory (main session). |
| `memory/YYYY-MM-DD.md` | Daily raw logs. |

**Decision for BLB:**

- **Keep `job_description`** as a single `TEXT` field on the employee row: short, human-readable role label or summary (e.g. "Customer support Agent", "Leave approver"). Use it for HR/UI and, if needed, as a fallback or one-line hint in execution context. Nullable; not mandatory at create for Agent (can be set before activation or left as summary only).
- **Do not** replicate the full OpenClaw file set in the DB as separate columns. When integrating an OpenClaw-like runtime, **Agent context for execution should be workspace-based**: each Agent has an associated workspace (directory or virtual file set) containing IDENTITY, SOUL, AGENTS, USER (or company/supervisor context), TOOLS, etc. The runtime loads these files to build the system prompt and behaviour; `job_description` may be displayed in the UI or injected as a short summary, but the authoritative "job" is the workspace content.
- **Pivot path:** Stage 0 can rely on `job_description` only (or omit it). When adding execution that follows OpenClaw's model, introduce an **Agent workspace** (path or storage key) and treat the workspace as the source of truth for identity and behaviour; keep `job_description` as an optional label for lists and reports.

---

## 5. Authorization and Delegation Rules

### 5.1 Core Invariants

1. Agent effective permissions must be a strict subset of supervisor effective permissions.
2. Delegation cannot create new privileges.
3. Explicit deny always wins.
4. Every Agent must have a supervision chain that resolves to a human accountable owner.
5. Supervision graph must be acyclic.

### 5.2 Supervisor Model

- Supervisor can be human or Agent.
- Human employees with required capability can create/manage subordinate Agents.
- One supervisor can manage multiple subordinate Agents, subject to policy limits.

### 5.3 Capability Gate (Illustrative)

Capabilities for Agent administration should be explicit in AuthZ, for example:

1. `employee.agent.create`
2. `employee.agent.update`
3. `employee.agent.assign_role`
4. `employee.agent.assign_permission`
5. `employee.agent.disable`
6. `ai.agent.configure_llm` — set or change LLM provider/model for a supervised agent (see §15)
7. `ai.provider.manage` — add, update, disable company-level LLM provider credentials (see §15.4)
8. `ai.provider.view` — view available providers (name, status; not raw keys)

The final capability vocabulary is owned by the AuthZ module.

---

## 6. UI and UX Rules

1. Employee listing includes both human and Agent rows.
2. Display type badges: `Human` / `Agent`.
3. Employee create/edit flow includes type selector.
4. Forms render the same base fields; optional guidance can hide non-relevant physical fields for Agent.
5. Supervisor and role assignment flows are shared.
6. Delegation policy violations return explicit deny reasons.

---

## 7. Error Policy

1. Policy violations are denied deterministically by AuthZ.
2. Validation errors return field-specific messages.
3. Privilege escalation attempts are logged as security events.
4. Management actions must be auditable with actor, target employee, and decision reason.

---

## 8. Implementation Dependencies

The current Lara-first surface depends on authorization PRD Stage B (Policy Engine + RBAC) and the already-landed portions of Stage D (Agent Delegation) from `docs/todo/authorization/00-prd.md`. `PrincipalType::AGENT` and the same RBAC substrate as human actors are operational. Remaining Stage D hardening work still matters for future agentic Lara task runtimes, but it is no longer framed as a standalone Playground prerequisite.

---

## 9. Workspace Configuration

The per-Agent workspace base path is configured in `app/Base/AI/Config/ai.php` (module-level config registered by `AIServiceProvider`):

- Config key: `config('ai.workspace_path')`
- Env override: `AI_WORKSPACE_PATH`
- Default: `storage_path('app/workspace')` → `storage/app/workspace/`

Each Agent gets a subdirectory: `{workspace_path}/{employee_id}/` containing `config.json` (per-agent LLM config, see §15), `sessions/`, and future `MEMORY.md`, `memory/`, `memory.db` (see §14).

---

## 10. Implementation Boundaries (Now vs Later)

### 10.1 In Scope Now

1. Agent as `employee_type = 'agent'` in a unified employee model.
2. `job_description` as optional short role label (nullable); full Agent context is workspace-based when OpenClaw-like runtime is adopted (§4.5).
3. Delegation constraints integrated with shared AuthZ.
4. Unified management UI behavior.

### 10.2 Out of Scope Now

1. HR-specific compensation, token spend, and cost accounting.
2. Rich Agent runtime telemetry fields in employee core table.
3. Channel-level integration details (Telegram/WhatsApp) as schema drivers.

---

## 11. Alignment with BLB Principles

1. Deep module boundary: complexity (delegation, policy checks, audit rules) is hidden in AuthZ + employee domain services.
2. Simple public interface: managers operate through familiar employee workflows.
3. Strategic programming: avoid premature Agent-specific schema sprawl while preserving forward compatibility.

---

## 12. Open Questions

1. Resolved: `job_description` is optional short label; workspace is source of truth for execution (§4.5).
2. Should policy set a hard maximum depth for Agent supervision chains?
3. Should Agent creation require dual approval for high-privilege departments?

---

## 13. OpenClaw Architecture (Research Findings)

*Relevant when designing Agent execution, tooling, or channel integration. Source: OpenClaw agent system research. See also §4.5 for how BLB's `job_description` relates to OpenClaw-style workspace files.*

### 13.1 High-Level Architecture

**Pattern:** Skills (teach) + Tools (execute) + Policies (constrain) + Channels (interface)

```
User Message (WhatsApp/Telegram/Slack)
  ↓
Gateway (routing & access control)
  ↓
Queue (session-based serialization)
  ↓
Agent (AI runtime with skills & tools)
  ↓
Tool Execution (sandboxed if configured)
  ↓
Response (streamed back to channel)
  ↓
Session Persistence (JSONL history)
```

### 13.2 Core Components

#### Agent
- Embedded AI runtime based on pi-mono
- Processes messages through serialized execution loop
- Each agent has dedicated workspace directory
- Session manager for conversation history
- **Bootstrap files for context:** IDENTITY.md, SOUL.md, USER.md, AGENTS.md, TOOLS.md, HEARTBEAT.md, MEMORY.md, memory/YYYY-MM-DD.md (see §4.5 for BLB alignment)

**Execution Model:**
- Runs serialized per session (prevents race conditions)
- Each run has unique `runId` for tracking
- Sessions isolated by `sessionKey` (e.g., per user, per group)
- Supports Docker sandboxing for security

#### Skills
AgentSkills-compatible instruction packs (Markdown files)

**Structure:**
```
skill-name/
├── SKILL.md          # YAML frontmatter + instructions
└── (optional files)
```

**Loading Precedence:**
1. Workspace skills (highest priority)
2. Managed skills (user-installed)
3. Bundled skills (shipped with system)

**Conditional Loading (Gating):**
- OS platform requirements
- Required binaries (e.g., csvkit)
- Environment variables
- Config values

#### Tools
Executable functions exposed to the AI

**Categories:**
- **Coding Tools:** File operations (read, write, edit, exec)
- **Web Tools:** External data (web_fetch, web_search)
- **Messaging Tools:** Send messages across channels
- **Session Tools:** Multi-agent coordination (sessions_send, sessions_spawn)
- **Platform Tools:** System integration (browser, canvas)

**Tool Schema:**
```typescript
interface Tool {
  name: string;
  description: string;
  schema: JSONSchema;  // TypeBox/Zod schema for parameters
  execute: (toolCallId: string, params: unknown) => Promise<ToolResult>;
}
```

#### Policies
Multi-level security constraints

**Policy Resolution Layers:**
1. Tool profile policy (e.g., "safe", "full")
2. Per-model overrides
3. Global allow/deny
4. Per-agent overrides
5. Per-group/channel policies
6. Sandbox restrictions
7. Subagent restrictions

**Example Policy:**
```json
{
  "tools": {
    "allow": ["customer_lookup", "invoice_create"],
    "deny": ["database_raw_query"],
    "exec": {
      "security": "allowlist",
      "ask": "on-miss",
      "safeBins": ["git", "npm"]
    }
  }
}
```

### 13.3 Agent Execution Loop (Summary)

1. **Message Entry** — User sends via channel; gateway validates; enqueued in session lane
2. **Session Resolution** — Load history from JSONL; restore context; token budgeting
3. **Context Assembly** — Load bootstrap files and eligible skills; build system prompt
4. **LLM Inference** — Send prompt + tools; stream response; process tool calls
5. **Tool Execution Loop** — Validate against policies; execute (sandbox if configured); log; return result
6. **Response Delivery** — Stream back to channel; persist transcript
7. **Cleanup & Logging** — Save session state; audit log

**Timeout Handling:** Agent runtime ~600s default; wait timeout ~30s client-side; AbortSignal for cancellation.

### 13.4 Security Mechanisms

- **Access Control:** Channel-specific policies (DM vs group), pairing codes, allowlists, mention requirements
- **Tool Policy:** Multi-level allow/deny; per-user, per-company, per-tool; approval workflows for sensitive ops
- **Sandboxing:** Docker containers; workspace access modes (none/ro/rw); resource limits; network isolation
- **Exec Approvals:** Human-in-the-loop for shell commands; allowlist of safe commands
- **Session Isolation:** Separate sessions per user; company-scoped data; no cross-user/cross-company leakage
- **Audit & Compliance:** All tool executions logged; session transcripts retained; security audit commands

### 13.5 Research References

- Agent runtime, session management: `openclaw/src/agents/`
- Skills: `openclaw/skills/` (AgentSkills-compatible)
- Tools: `openclaw/src/agents/tools/`
- Channels: `openclaw/src/{telegram,discord,slack,signal,imessage,web}/`
- Security: `openclaw/docs/gateway/security/`
- Execution flow: `openclaw/docs/concepts/agent-loop.md`

---

## 14. Memory and Recall Architecture

*Relevant when implementing Agent semantic memory (long-term recall beyond the chat transcript). See also §4.5 (workspace files) and §13 (OpenClaw).*

### 14.1 Transcript vs Memory

| Concern | Transcript | Memory (Recall) |
|---------|------------|-----------------|
| **Purpose** | Chat turn-by-turn history (user/assistant messages in order) | Long-term searchable knowledge (facts, decisions, observations) |
| **Source** | JSONL files per session (`workspace/{employee_id}/sessions/{uuid}.jsonl`) | Markdown files (MEMORY.md, memory/YYYY-MM-DD.md) |
| **Usage** | Provide last N turns as LLM context | Semantic search: "recall relevant past knowledge" before responding |
| **Stage** | Stage 0 (Playground) | Post-Stage 0 |

Both are needed for a capable Agent: transcript for immediate context, memory for history.

### 14.2 MemSearch Pattern

[MemSearch](https://zilliztech.github.io/memsearch/) (Zilliz/Milvus) extracts OpenClaw's memory system into a standalone library. Core principles:

- **Markdown as source of truth** — Plain `.md` files are the canonical store; the vector index is derived and rebuildable.
- **Vector store as index** — Embeddings enable semantic search; the index can be dropped and rebuilt from markdown anytime.
- **Git-native** — Version knowledge bases with standard git workflows.
- **No vendor lock-in** — Switch embedding or vector backends without data loss.

Reference: [Milvus blog: We extracted OpenClaw's memory system and open-sourced it (MemSearch)](https://milvus.io/blog/we-extracted-openclaws-memory-system-and-opensourced-it-memsearch.md)

### 14.3 BLB Implementation Direction

**PHP-native implementation** — Implement the MemSearch pattern in PHP to avoid Python subprocesses and keep the stack homogeneous. Components:

- Markdown parsing: `league/commonmark` or similar
- Chunking: by heading and paragraph structure
- Embeddings: HTTP calls to OpenAI, Voyage, or Ollama
- Vector storage: see §14.4

**Vector backend: SQLite per Agent** — Use a dedicated SQLite database per Agent for vector storage:

- Each Agent gets `workspace/{employee_id}/memory.db`
- Aligns with per-agent workspace isolation (OpenClaw pattern)
- Strong tenant isolation by design; backup/export = copy one file
- Requires a vector extension: [sqlite-vec](https://github.com/asg017/sqlite-vec) or [sqlite-vss](https://github.com/asg017/sqlite-vss)

**Workspace layout (per Agent):**

```
workspace/{employee_id}/
├── MEMORY.md              # Persistent facts & decisions
├── memory/
│   ├── 2026-02-07.md      # Daily log
│   └── 2026-02-09.md
└── memory.db              # Vector index for this agent's markdown (derived, rebuildable)
```

**Alternative:** pgvector in the main PostgreSQL database with `employee_id` for tenancy. Simpler ops (one DB, standard migrations) but less natural per-agent isolation. Choose based on scale and deployment constraints.

### 14.4 Search Strategy: Hybrid Vector + BM25

MemSearch demonstrates that hybrid retrieval outperforms pure vector search for agent memory. Default weighting:

- **Vector search (70%):** Semantic matching — a query for "Redis cache config" finds chunks about "Redis L1 cache with 5min TTL" even with different wording.
- **BM25 keyword search (30%):** Exact matching — a query for "PostgreSQL 16" does not return results about "PostgreSQL 15". Critical for error codes, function names, version-specific facts.

The 70/30 split is MemSearch's empirically tuned default. For workflows heavy on exact matches (code references, IDs), raise BM25 weight to 50%. BLB's PHP-native implementation should support configurable weights per Agent or globally.

### 14.5 Compaction: Daily Logs → Long-Term Memory

MemSearch includes a **compact** workflow that distills older daily logs (`memory/YYYY-MM-DD.md`) into curated long-term entries in `MEMORY.md`. This prevents unbounded growth of daily files while preserving key facts and decisions.

**Pattern:**
1. Periodically (e.g., weekly or on threshold), feed older daily logs to the LLM with a distillation prompt.
2. Extract durable facts, decisions, and preferences into `MEMORY.md` (append or merge under headings).
3. Archive or delete processed daily logs (or keep as raw history if storage allows).
4. Re-index after compaction.

**BLB consideration:** Compaction can run as a scheduled Laravel command per Agent. The human supervisor should be able to review and edit `MEMORY.md` directly (transparency principle). Compaction is post–Stage 0 but should be designed alongside the initial memory implementation to avoid rework.

### 14.6 Implementation Scope (Future)

1. Scan markdown in `workspace/{employee_id}/`
2. Chunk by heading/paragraph; embed via HTTP
3. Store vectors in SQLite (sqlite-vec) or pgvector
4. Search: hybrid vector (70%) + BM25 (30%), return top-K chunks with source attribution
5. Deduplication: content hash to skip re-embedding unchanged chunks
6. Sync: file watcher with debounce (~1500ms) or Laravel scheduler for incremental indexing
7. Compaction: scheduled distillation of daily logs into `MEMORY.md`

---

## 15. Per-agent LLM Configuration

Each Agent can use a different LLM provider and model. This enables cost-optimized model assignment by job type: a design-focused agent might use Gemini for multimodal, a coding agent might use Claude Opus, a research agent might use GPT, and a general-purpose agent might use an open-weight model. The architecture separates **provider credentials** (company-level, sensitive) from **model selection** (per-agent, in workspace).

### 15.1 Provider Credentials (Company-Level)

API keys are sensitive and should not be stored in workspace files (plaintext on disk). Provider credentials are stored encrypted in the database, scoped to the company.

**Table: `ai_providers`**

| Column | Type | Description |
|--------|------|-------------|
| `id` | ULID | Primary key |
| `company_id` | FK | Owning company |
| `name` | string | Unique key within company (e.g. `openai`, `anthropic`, `google`, `ollama-local`) |
| `display_name` | string | Human-readable label (e.g. "OpenAI GPT", "Local Ollama") |
| `base_url` | string | API endpoint (e.g. `https://api.openai.com/v1`) |
| `api_key` | encrypted | Provider API key (Laravel encrypted cast) |
| `is_active` | boolean | Whether available for agent assignment |
| `created_by` | FK (employee) | Who configured this provider |
| `timestamps` | | |

**Design rationale:**
- Company-level scoping: the company pays for API access; all agents in that company share the pool of configured providers.
- A company can have multiple providers (OpenAI for general, Anthropic for coding, a self-hosted Ollama for cost-sensitive tasks).
- Keys are encrypted at rest via Laravel's `encrypted` cast — never stored in workspace files or config.
- The `name` column is a stable reference key used in agent workspace `config.json`.

### 15.2 Per-agent Model Selection (Workspace Config)

Each Agent's workspace contains a `config.json` that specifies which provider and model to use. For Lara, the same file can additionally hold task-specific model selections. This file is part of the workspace, not the database.

**Workspace layout (updated):**

```
workspace/{employee_id}/
├── config.json                # agent-specific runtime configuration
├── sessions/
│   ├── {uuid}.jsonl
│   └── {uuid}.meta.json
├── MEMORY.md                  # (future)
├── memory/                    # (future)
└── memory.db                  # (future)
```

**`config.json` structure (chat models plus optional Lara task models):**

```json
{
    "llm": {
        "models": [
            {
                "provider": "anthropic",
                "model": "claude-sonnet-4-20250514",
                "max_tokens": 4096,
                "temperature": 0.5
            },
            {
                "provider": "openai",
                "model": "gpt-4o-mini",
                "max_tokens": 2048,
                "temperature": 0.7
            }
        ],
        "tasks": {
            "titling": {
                "mode": "recommended",
                "provider": "openai",
                "model": "gpt-5-nano",
                "reason": "Fast and sufficient for short titles."
            },
            "research": {
                "mode": "manual",
                "provider": "anthropic",
                "model": "claude-sonnet-4-20250514"
            },
            "coding": {
                "mode": "primary"
            }
        }
    }
}
```

- `models`: ordered list of chat model configurations (maximum two entries). First entry is primary; the optional second entry is a backup tried on transient failures (timeout, connection error, rate limit, server error, empty response).
- Lara's backup model is configured through the Lara setup UI ("Backup Model" card) and can use a different provider than the primary.
- `tasks`: optional Lara-only task-model configuration. Each task entry stores a mode (`primary`, `recommended`, or `manual`) and, for `recommended`/`manual`, a stable saved provider/model pair plus optional UI reason text. Recommendation is on-demand and persists a stable choice; it is not recomputed automatically on every run.
- `provider`: references `ai_providers.name` within the agent's company.
- `model`: the specific model within that provider.
- `max_tokens`, `temperature`: optional per-agent overrides; fall back to global `config('ai.llm.*')` defaults.

### 15.3 Config Resolution Order

The runtime resolves LLM configuration with a cascade:

1. **agent workspace `config.json`** — per-agent overrides (`llm.models[]` for chat, and for Lara optionally `llm.tasks.*` for specialized task routing)
2. **Company provider credentials** — `ai_providers` row matching the provider name + company_id (supplies `base_url` and `api_key`)
3. **Global defaults** — `config('ai.llm.*')` from `app/Base/AI/Config/ai.php` / `.env` (fallback runtime parameters like `max_tokens`, `temperature`, `timeout`)

**Resolution rules:**
- If `config.json` specifies a provider → look up `ai_providers` by `(company_id, name)` → use that row's `base_url` and `api_key`, merged with per-agent model/params.
- If workspace config is missing (or `llm.models[]` is empty) → resolve company default provider+model (`ConfigResolver::resolveDefault()`), then apply runtime defaults for parameters.
- If a Lara task config is missing, incomplete, or points to an inactive provider/model → fall back to Lara's primary chat model.
- If a configured provider cannot be resolved (inactive/not found) or has missing credentials → runtime returns `config_error` for that model (non-transient), and fallback stops at that point.

### 15.4 Authorization for Provider Management

| Capability | Who | Purpose |
|------------|-----|---------|
| `ai.provider.manage` | Company admin | Add, update, disable LLM provider credentials |
| `ai.provider.view` | agent supervisors | See available providers (but not raw API keys) when configuring agents |

Provider management is a company-level operation, separate from agent onboarding. Only users with `ai.provider.manage` can create or edit provider entries. agent supervisors can see the list of available providers (name, display_name, is_active) but never the raw API key.

> Stage 0 implementation note: routes currently use `auth` middleware; capability-specific middleware for `ai.provider.manage` / `ai.provider.view` is planned hardening work.

### 15.5 Fallback Attempt Trace (Runtime Observability)

Inspired by OpenClaw's `FallbackAttempt` type (`openclaw/src/agents/model-fallback.ts`), the Agent runtime captures structured trace metadata for every model attempted during a conversation turn.

**Trace entry structure (per attempt):**

```json
{
    "provider": "anthropic",
    "model": "claude-sonnet-4-20250514",
    "error": "HTTP 500: Internal Server Error",
    "error_type": "server_error",
    "latency_ms": 150
}
```

**Behavior:**
- On success (first or fallback model), `meta.fallback_attempts` is an empty array (no failures to report) or contains entries for each prior failed model.
- On total failure (all models exhausted), `meta.fallback_attempts` contains entries for every attempted model, and the final result carries the last model's error.
- Non-transient errors (`auth_error`, `config_error`, `bad_request`, etc.) halt the fallback chain immediately; the attempts array will be empty since no fallback was tried.
- Operator and run-detail surfaces show a collapsible "Fallback Attempts" section when `fallback_attempts` is non-empty, displaying provider, model, error, error_type, and latency for each attempt.
- **Chat thread visibility:** When a fallback was used, an inline amber notice appears above the assistant response (`"⚠ {error}. Switched to {provider}/{model}."`). A dismissible thread-level banner above the composer suggests the user may want to switch models.

**Fallback-worthy error types:** `timeout`, `connection_error`, `rate_limit`, `server_error`, `empty_response`
**Non-fallback error types:** `auth_error`, `not_found`, `bad_request`, `html_response`, `unsupported_response_shape`, `config_error`, `unexpected_error`

---

## 16. Agent Onboarding

### 16.1 Onboarding Flow

Setting up a Agent is a multi-step process that spans the employee module and AI module. The onboarding UI provides a guided flow (tabbed or wizard-style) within the existing employee management surface.

**Steps:**

1. **Identity** — Create employee with `employee_type = 'agent'`. Set name, job description, supervisor (defaults to current user). Employee module handles this.
2. **LLM Configuration** — Select provider from the company's available providers, pick model, optionally override temperature/max_tokens. Writes `config.json` to the agent workspace.
3. **Authorization** — Assign roles and capabilities. Scoped by what the supervisor has (existing AuthZ Stage D constraint: supervisor can only assign what they have).
4. **Review & Activate** — Summary of the agent setup. Set status to active. The agent becomes available to delegated/runtime workflows.

### 16.2 Authorization for Onboarding

| Capability | Who | Purpose |
|------------|-----|---------|
| `employee.agent.create` | Supervisor | Create a new agent employee record |
| `employee.agent.update` | Supervisor | Edit agent identity, job description |
| `ai.agent.configure_llm` | Supervisor | Set or change LLM provider/model for a agent they supervise |
| `employee.agent.assign_role` | Supervisor | Assign roles (existing AuthZ, supervisor-scoped) |
| `employee.agent.assign_permission` | Supervisor | Grant capabilities (existing AuthZ, supervisor-scoped) |
| `employee.agent.disable` | Supervisor | Deactivate a agent |

**Constraints:**
- A supervisor can only onboard agents under their own supervision (not other users' agents).
- Roles/capabilities assigned to the agent must be a subset of the supervisor's effective permissions (existing AuthZ Stage D invariant).
- LLM provider must be active and belong to the same company.
- The onboarding flow reuses existing employee creation and AuthZ assignment UIs — it is a guided orchestration, not a separate product surface.

### 16.3 Separation of Concerns

| Concern | Owner | Scope |
|---------|-------|-------|
| Provider credentials (API keys, base URLs) | Company admin (`ai.provider.manage`) | Company-wide |
| agent identity (name, job, supervisor) | Employee module (`employee.agent.*`) | Per-agent |
| agent model selection (provider, model, params) | AI module (`ai.agent.configure_llm`) | Per-agent workspace |
| Roles and permissions | AuthZ module (`employee.agent.assign_role/permission`) | Per-agent |

This separation means:
- **Company admin** sets up which LLM providers are available (one-time or occasional).
- **agent supervisor** picks from pre-approved providers when onboarding a agent — they don't need to know API keys.
- **Cost control** is natural: the company admin controls which providers (and thus cost tiers) are available; the supervisor picks the best fit for the agent's job.

---

## Revision History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 0.1 | 2026-02-25 | AI + Kiat | Pivoted from PA document to Agent architecture; unified employee model and delegation invariants |
| 0.2 | 2026-02-26 | AI + Kiat | Added §14 Memory and Recall: transcript vs memory, MemSearch pattern, PHP-native direction, SQLite per agent |
| 0.3 | 2026-02-27 | AI + Kiat | Renamed §3.3 operations to Agent; added §14.4 hybrid search strategy (vector 70% + BM25 30%); added §14.5 compaction workflow |
| 0.4 | 2026-02-27 | AI + Kiat | Added §8 Implementation Dependencies, §9 Workspace Configuration; renumbered §8–12 → §10–14 |
| 0.5 | 2026-02-28 | AI + Kiat | Added §15 Per-agent LLM Configuration (provider credentials, workspace config.json, config resolution); §16 Agent Onboarding (flow, authorization, separation of concerns) |
| 0.6 | 2026-02-28 | AI + Kiat | Updated §15.2 config.json to multi-model format (`llm.models[]`); added §15.5 Fallback Attempt Trace (OpenClaw-inspired runtime observability) |
| 0.7 | 2026-03-04 | AI + Kiat | Refined §15.3 to match current resolver/runtime behavior and added Stage 0 hardening notes for capability middleware |
| 0.8 | 2026-04-05 | AI + Kiat | §15.2: Updated config.json to max 2 entries (backup model), documented UI-configurable backup. §15.5: Corrected fallback-worthy error types to match `AiErrorType::retryable()` (added `timeout`, `empty_response`); added chat thread fallback visibility (inline notice + thread banner). |
