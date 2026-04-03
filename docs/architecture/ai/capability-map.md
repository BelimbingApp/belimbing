# Agent Tools Blueprint — Mirroring OpenClaw Capabilities for BLB

**Document Type:** Architecture Blueprint (Study & Plan)
**Status:** Active parity map with implemented OpenClaw-parity Phases 1-6 plus later runtime and ledger updates
**Last Updated:** 2026-04-03
**Related:** `docs/Base/AI/tool-framework.md` (tool abstraction layer), `docs/architecture/ai/lara.md` §3 (Lara vs agents), §14 (Tool Calling), `docs/architecture/ai/agent-model.md` §13–§14, `docs/architecture/ai/current-state.md`

> **Important:** The tool-calling infrastructure described here is **agent-generic**, not Lara-specific. All tools implement the `Tool` contract (`App\Base\AI\Contracts\Tool`), extend `AbstractTool` or `AbstractActionTool`, are registered in the shared `AgentToolRegistry`, and execute through the common `AgenticRuntime`. Lara is distinguished from other agents by her framework-controlled identity, personality, and mission — not by unique tool code. Which tools a agent can use is a **policy decision** controlled by authz role assignment. See `docs/architecture/ai/lara.md` §3 for the full distinction.

---

## 1. Problem Essence

BLB's Agents currently have a small tool surface (ArtisanTool, BashTool, NavigateTool) and a set of slash-command orchestration services (`/go`, `/models`, `/guide`, `/delegate`). OpenClaw provides a comprehensive 20+ tool surface that makes its agent a fully capable autonomous operator. BLB needs to mirror these capabilities while respecting its own architectural principles: deep modules, authz-gated execution, server-side PHP execution (no Node.js gateway), and the Lara-as-orchestrator model. **Browser automation** (enterprise RPA, web scraping, form filling) and **multi-channel messaging** (WhatsApp, Telegram, LinkedIn, Slack) are key differentiators — they are core enterprise features, not peripherals.

---

## 2. OpenClaw Tool Inventory (Full Analysis)

### 2.1 Tool Categories

OpenClaw organizes tools into groups with an allow/deny policy system:

| Group | Tools | OpenClaw Purpose |
|-------|-------|-----------------|
| `group:runtime` | `exec`, `bash`, `process` | Shell execution, background processes |
| `group:fs` | `read`, `write`, `edit`, `apply_patch` | File system operations |
| `group:sessions` | `sessions_list`, `sessions_history`, `sessions_send`, `sessions_spawn`, `session_status` | Multi-agent session management |
| `group:memory` | `memory_search`, `memory_get` | Semantic recall from workspace markdown |
| `group:web` | `web_search`, `web_fetch` | Web search + content extraction |
| `group:ui` | `browser`, `canvas` | Chromium automation, canvas rendering |
| `group:automation` | `cron`, `gateway` | Scheduled tasks, gateway lifecycle |
| `group:messaging` | `message` | Cross-platform messaging (Discord, Slack, Telegram, WhatsApp, etc.) |
| `group:nodes` | `nodes` | Paired device discovery, notifications, camera/screen capture |
| (standalone) | `image`, `pdf`, `tts` | Media analysis and synthesis |
| (standalone) | `agents_list` | Agent discovery for sub-agent targeting |
| (plugin) | `llm-task`, `lobster`, `diffs`, `voice_call` | JSON-only LLM steps, workflow engine, diff viewer, telephony |

### 2.2 Tool Architecture Patterns (from source analysis)

**Common infrastructure (`common.ts`):**
- Typed parameter readers (`readStringParam`, `readNumberParam`, `readStringArrayParam`) with validation
- `ToolInputError` (400) and `ToolAuthorizationError` (403) error hierarchy
- `ActionGate<T>` — per-action enable/disable flags
- `jsonResult()` / `imageResult()` — standardized response builders
- `ownerOnly` flag for restricting tools to session owner

**Tool lifecycle:**
- Tools are factory functions (e.g., `createMemorySearchTool(options)`) that return `AgentTool` objects or `null` (when prerequisites aren't met)
- Each tool declares: `name`, `label`, `description`, `parameters` (TypeBox schema), `execute(toolCallId, params)`
- Tools receive runtime context (config, session key, gateway URL) at creation time — not per-call

**Policy enforcement:**
- Tool profiles (`minimal`, `coding`, `messaging`, `full`) define base allowlists
- `tools.allow` / `tools.deny` refine per-agent, per-provider, and per-sandbox
- Groups (`group:fs`, `group:runtime`, etc.) are shorthands that expand to tool lists
- Loop detection (optional) prevents stuck agents from burning tokens

### 2.3 Key Design Decisions in OpenClaw

1. **Gateway-mediated execution** — `exec`, `cron`, `nodes`, `canvas` route through a long-running Node.js gateway process with WebSocket RPC
2. **Background processes** — `exec` can auto-background after `yieldMs`, returning a `sessionId` for `process` polling
3. **Multi-agent sessions** — First-class session spawning, agent-to-agent messaging, and announce-back pattern
4. **Browser = Playwright over CDP** — Isolated browser profiles, snapshot-based interaction (not CSS selectors), SSRF guards
5. **Memory = MemSearch** — Markdown-native, hybrid vector + BM25, per-agent workspace isolation
6. **Skills = Prompt injection** — Markdown files with YAML frontmatter, gated by binary/env/config presence, injected into system prompt
7. **Plugin system** — Runtime-loaded TypeScript modules that register tools, commands, RPC, HTTP routes, and skills

---

## 3. BLB Current State (What Exists)

### 3.1 Tool Infrastructure (Implemented)

| Component | Location | Status | Notes |
|-----------|----------|--------|-------|
| `Tool` interface | `Base/AI/Contracts/` | ✅ Done | 7 methods: `name()`, `description()`, `parametersSchema()`, `requiredCapability()`, `category()`, `riskClass()`, `execute()` |
| `AbstractTool` | `Base/AI/Tools/` | ✅ Done | Sealed `execute()` → `handle()`, typed argument extractors, `ToolSchemaBuilder` integration |
| `AbstractActionTool` | `Base/AI/Tools/` | ✅ Done | Multi-action dispatch: auto-injects `action` enum, routes to `handleAction()` |
| `ToolSchemaBuilder` | `Base/AI/Tools/Schema/` | ✅ Done | Fluent JSON Schema builder replacing hand-crafted arrays |
| `ToolResult` | `Base/AI/Tools/` | ✅ Done | Structured result with `success()`/`error()`/`withClientAction()` factories; `Stringable` for backward compat |
| `ToolCategory` enum | `Base/AI/Enums/` | ✅ Done | 9 categories with `label()` and `sortOrder()` |
| `ToolRiskClass` enum | `Base/AI/Enums/` | ✅ Done | 6 risk classes with `label()`, `color()`, and `sortOrder()` |
| `AgentToolRegistry` | `Core/AI/Services/` | ✅ Done | Register, authz-filtered definitions, dispatch execution |
| `AgenticRuntime` | `Core/AI/Services/` | ✅ Done | Iterative tool-calling loop (max 10 iterations) |
| LLM tool calling in `LlmClient` | `Base/AI/Services/` | ✅ Done | `tools` and `toolChoice` params, `tool_calls` parsing |

> **Architecture note:** The tool abstraction layer lives in `Base/AI` (framework infrastructure), enabling any module — Core, Business, or Extension — to define tools. See `docs/Base/AI/tool-framework.md` for the full reference.

### 3.2 Orchestration Services (Implemented)

| Component | Status | Notes |
|-----------|--------|-------|
| `LaraOrchestrationService` | ✅ Done | `/go`, `/models`, `/guide`, `/delegate` dispatch |
| `LaraPromptFactory` | ✅ Done | Framework-managed system prompt with extension support |
| `LaraKnowledgeNavigator` | ✅ Done | Curated keyword-scored reference search |
| `LaraCapabilityMatcher` | ✅ Done | agent discovery + task-to-agent matching |
| `LaraTaskDispatcher` | ✅ Done | agent delegation dispatch (queued, not yet executed) |
| `LaraNavigationRouter` | ✅ Done | `/go <target>` page navigation |
| `LaraModelCatalogQueryService` | ✅ Done | `/models <filter>` boolean expression queries |
| `LaraContextProvider` | ✅ Done | Runtime context injection for prompts |

### 3.3 Infrastructure (Implemented)

| Component | Status | Notes |
|-----------|--------|-------|
| `LlmClient` | ✅ Done | Stateless OpenAI-compatible chat with tool support |
| `AgentRuntime` | ✅ Done | Config cascade + ordered fallback |
| `ConfigResolver` | ✅ Done | agent workspace → company provider → global defaults |
| `ModelCatalogService` | ✅ Done | models.dev catalog fetch + cache |
| `ProviderDiscoveryService` | ✅ Done | Live `/models` endpoint discovery |
| `GithubCopilotAuthService` | ✅ Done | Device flow token exchange |
| Provider management UI | ✅ Done | Provider CRUD + model registry |
| agent playground UI | ✅ Done | Chat + LLM config assignment |

### 3.4 Gaps vs OpenClaw

| OpenClaw Capability | BLB Equivalent | Gap |
|---------------------|----------------|-----|
| `exec` + `process` | `bash` tool + background command dispatch ledger | Implemented shell execution and queued background work, but not OpenClaw-style process-session polling |
| `read` / `write` / `edit` | `edit_file` / `edit_data` tools | Partial parity; BLB favors deeper editing tools over a raw read/write/apply_patch suite |
| `web_search` / `web_fetch` | `web_search` / `web_fetch` tools | Implemented |
| `browser` | `BrowserTool` + persistent browser session subsystem | Implemented; differs from OpenClaw by using BLB-managed server-side browser infrastructure |
| `memory_search` / `memory_get` | `memory_search` / `memory_get` tools + per-agent memory index | Implemented |
| `sessions_spawn` / sub-agents | `delegate_task`, `delegation_status`, `SpawnAgentSessionJob`, dispatch ledger | Implemented as Lara-led delegation, not peer-to-peer session spawning |
| `cron` | `schedule_task` + schedule planner/tick services | Implemented |
| `message` | `message` tool + channel adapter infrastructure | Implemented |
| `image` / `pdf` / `tts` | — | No media analysis tools |
| `canvas` / `nodes` | — | No device/canvas integration |
| Tool profiles + groups | — | No tool policy system |
| Loop detection | Max iterations (10) | Basic, no pattern detection |
| Plugin system | — | No plugin/extension architecture for tools |
| Skills system | — | No AgentSkills-compatible skill loading |

---

## 4. BLB Tool Roadmap (Phased)

### 4.1 Design Principles (BLB-Specific Divergences from OpenClaw)

1. **Server-side PHP, not a Node.js gateway** — BLB tools execute within Laravel's request/queue lifecycle. No long-running gateway process. Background work uses Laravel queues and jobs.

2. **Authz-first, not config-file policy** — OpenClaw uses `openclaw.json` allow/deny lists. BLB uses the existing AuthZ capability system. Each tool declares a `requiredCapability()` string; the `AgentToolRegistry` enforces it at both definition-time and execution-time. Tool groups map to authz role bundles, not config entries.

3. **Lara is the orchestrator, agents are agents** — OpenClaw's multi-agent model has peer agents. BLB's model is hierarchical: Lara dispatches to Agents. Sub-agent tools (`sessions_spawn`) become Lara's delegation primitives, not generic session management.

4. **Enterprise multi-tenancy** — All tools are company-scoped. Provider credentials, tool access, and data isolation follow `company_id` boundaries. OpenClaw is single-tenant.

5. **Deep modules over thin tools** — Where OpenClaw has many granular tools (`read`, `write`, `edit`, `apply_patch`), BLB should consider fewer, richer tools that hide complexity. Example: a single `QueryDataTool` instead of separate read/write/edit tools.

6. **Browser automation is a first-class capability** — Unlike OpenClaw's personal-agent model (local Chromium profiles), BLB treats browser automation as an enterprise capability: server-side headless Chromium for web scraping, form filling, RPA workflows, and competitor monitoring. agents can automate external websites on behalf of the business. This is managed infrastructure, not a personal browser.

7. **Multi-channel messaging is a core feature — business AND personal** — BLB's Agents interact with customers, partners, and teams across WhatsApp, Telegram, LinkedIn, Signal, iMessage, and other channels. This includes both business accounts (WhatsApp Business API) **and** personal relays (personal WhatsApp, Telegram, Signal, iMessage via bridge). What differentiates BLB from OpenClaw is not restricting capabilities — it is the **authz system**. The human supervisor decides what their agents can do. Trust is built through transparency and control, not through artificial limitation.

8. **Unleash capability, govern with authz** — BLB's philosophy is to build trust, not build fear. Every tool capability is granular in authz: a supervisor can grant a agent permission to send Telegram messages but not WhatsApp. To read web pages but not execute browser automation. To query data but not run artisan commands. The power is real; the control is in human hands. This is how human–AI collaboration evolves — by giving AI real capability and giving humans real authority over it.

### 4.2 Phased Implementation

#### Phase 1: Foundation Tools (Immediate Priority)

These tools directly serve Lara's core mission and build on existing infrastructure.

**1a. `QueryDataTool`** — Execute read-only database queries
- **OpenClaw parallel:** Part of `exec` (running SQL via CLI)
- **BLB approach:** A dedicated tool with SQL validation, query limits, read-only enforcement (`SELECT` only), and result formatting
- **Parameters:** `query` (SQL string), `limit` (max rows, default 50)
- **Safety:** Parse SQL to reject writes (`INSERT`, `UPDATE`, `DELETE`, `DROP`, `ALTER`, `TRUNCATE`). Statement timeout. Row count cap.
- **Capability:** `ai.tool_query_data.execute`
- **Value:** Lara can answer data questions ("How many employees are active?", "Show recent orders") without piping through artisan tinker

**1b. `WebSearchTool`** — Search the web for information
- **OpenClaw parallel:** `web_search`
- **BLB approach:** Server-side HTTP via Laravel's `Http` facade. Configurable search provider via `config('ai.tools.web_search.provider')`. API key per-provider via `config('ai.tools.web_search.{provider}.api_key')`.
- **Supported providers:**
  - **Parallel** (recommended) — `api.parallel.ai/v1beta/search`. AI-native search with semantic objectives and LLM-optimized excerpts. Supports `objective` + `search_queries` + `max_results` + `excerpts`. Auth: `x-api-key` header. Best accuracy/cost ratio for agentic use.
  - **Brave Search** — `api.search.brave.com/res/v1/web/search`. Simple keyword search with structured results. Auth: `X-Subscription-Token` header.
- **Parameters:** `query` (or `objective` for Parallel), `count` (1–10, default 5), `freshness` (day/week/month)
- **Safety:** No SSRF concerns (external API call, not arbitrary URL fetch)
- **Capability:** `ai.tool_web_search.execute`

**1c. `WebFetchTool`** — Fetch and extract content from a URL
- **OpenClaw parallel:** `web_fetch`
- **BLB approach:** HTTP GET + HTML-to-text extraction. Use `league/html-to-markdown` or Readability-equivalent for content extraction. SSRF protection: block private/internal hostnames, limit redirects.
- **Parameters:** `url`, `max_chars` (truncation limit, default 50000), `extract_mode` (markdown/text)
- **Safety:** SSRF guard (block RFC1918, link-local, loopback). Timeout (30s). Response size cap.
- **Capability:** `ai.tool_web_fetch.execute`

**1d. `SystemInfoTool`** — Report BLB system state
- **OpenClaw parallel:** Part of `session_status` + `gateway config.get`
- **BLB approach:** Returns structured JSON about the current BLB instance: Laravel version, PHP version, active modules, configured providers, queue status, cache status, disk usage, recent migration status.
- **Parameters:** `section` (optional: `all`, `modules`, `providers`, `health`)
- **Safety:** Read-only, no sensitive data (API keys masked)
- **Capability:** `ai.tool_system_info.execute`

#### Phase 2: Memory & Knowledge (Post-Foundation)

**2a. `MemorySearchTool`** — Semantic search across Lara's knowledge
- **OpenClaw parallel:** `memory_search`
- **BLB approach:** PHP-native implementation of the MemSearch pattern (see `agent-model.md` §14). Hybrid vector + BM25 search over markdown workspace files. Per-agent SQLite vector storage (`sqlite-vec`).
- **Parameters:** `query`, `max_results` (default 10), `min_score` (float)
- **Dependency:** Requires embedding provider configuration and sqlite-vec extension
- **Capability:** `ai.tool_memory_search.execute`

**2b. `MemoryGetTool`** — Read a specific knowledge file
- **OpenClaw parallel:** `memory_get`
- **BLB approach:** Read from the agent workspace with path validation (no directory traversal)
- **Parameters:** `path`, `from` (line number), `lines` (count)
- **Capability:** `ai.tool_memory_get.execute`

**2c. `GuideTool`** — Query BLB framework documentation
- **OpenClaw parallel:** Skills + AGENTS.md context injection
- **BLB approach:** Upgrade `LaraKnowledgeNavigator` from keyword scoring to embedding-based search over `docs/` directory. Return relevant doc sections as tool results.
- **Parameters:** `topic`, `max_sections` (default 5)
- **Capability:** `ai.tool_guide.execute`
- **Note:** This replaces the current `/guide` slash command with a proper tool the LLM can invoke autonomously

#### Phase 3: Delegation & Multi-Agent (Post-Memory)

**3a. `DelegateTaskTool`** — Dispatch work to a Agent
- **OpenClaw parallel:** `sessions_spawn`
- **BLB approach:** Extend `LaraTaskDispatcher` to actually queue and execute agent tasks. Use Laravel queues for async execution. Return a `dispatch_id` immediately, support polling for results.
- **Parameters:** `task` (description), `agent_id` (optional, auto-match if omitted), `timeout_seconds` (default 300)
- **Capability:** `ai.tool_delegate.execute`
- **Design:** Lara dispatches → Laravel job queued → agent runtime executes in agent process → result stored → Lara polls or gets notified

**3b. `DelegationStatusTool`** — Check status of dispatched tasks
- **OpenClaw parallel:** `session_status` + `sessions_history`
- **BLB approach:** Query dispatch status by `dispatch_id`. Returns status (queued/running/completed/failed), result preview, timing.
- **Parameters:** `dispatch_id`
- **Capability:** `ai.tool_delegation_status.execute`

**3c. `AgentListTool`** — List available Agents
- **OpenClaw parallel:** `agents_list`
- **BLB approach:** Wraps `LaraCapabilityMatcher::discoverDelegableAgentsForCurrentUser()` as a tool the LLM can call
- **Parameters:** none (or optional `capability_filter`)
- **Capability:** `ai.tool_agent_list.execute`

#### Phase 4: Automation & Scheduling (Post-Delegation)

**4a. `ScheduleTaskTool`** — Create/manage scheduled tasks
- **OpenClaw parallel:** `cron`
- **BLB approach:** CRUD for Laravel-native scheduled tasks stored in DB. Each task defines: cron expression, agent target, task description, enabled state.
- **Parameters:** `action` (list/add/update/remove/status), plus action-specific params
- **Capability:** `ai.tool_schedule.execute`
- **Design:** Scheduled tasks execute via Laravel's scheduler, dispatching to agent runtimes

**4b. `NotificationTool`** — Send notifications to users
- **OpenClaw parallel:** `message` (simplified)
- **BLB approach:** Send notifications via Laravel's notification system (database, email, broadcast). Not a full messaging platform — targeted at internal BLB notifications.
- **Parameters:** `user_id` (or `all`), `channel` (database/email/broadcast), `subject`, `body`
- **Capability:** `ai.tool_notification.execute`

#### Phase 5: Browser Automation (Post-Delegation — Key Feature)

BLB treats browser automation as enterprise-grade RPA infrastructure. OpenClaw uses Playwright over CDP to control local Chromium profiles for a single personal user. BLB inverts this: a server-side headless browser pool that Agents share, managed as company infrastructure with full audit trails.

**5a. `BrowserTool`** — Headless browser automation for agents
- **OpenClaw parallel:** `browser` (status/start/stop/snapshot/act/screenshot/navigate/tabs)
- **BLB approach:** Server-side headless Chromium via a PHP-managed process. Two sub-systems:
  1. **Browser pool** — A managed pool of headless Chromium instances (via `chrome-php/chrome` or Playwright CLI). Each agent session gets an isolated browser context (separate cookies, storage). Pool size configurable per company.
  2. **BrowserTool** — Single deep tool with action-based dispatch (OpenClaw pattern): `navigate`, `snapshot`, `screenshot`, `act` (click/type/select), `tabs`, `evaluate`, `pdf`, `cookies`, `wait`.
- **Parameters:** `action` (required), plus action-specific params (same shape as OpenClaw's browser tool)
- **Key actions:**

| Action | Parameters | Description |
|--------|-----------|-------------|
| `navigate` | `url` | Navigate to URL (SSRF-guarded) |
| `snapshot` | `format` (ai/aria), `interactive`, `compact` | Return page structure with refs for interaction |
| `screenshot` | `full_page`, `ref`, `selector` | Capture viewport or element |
| `act` | `kind` (click/type/select/press/drag/hover/scroll/fill), `ref`, `text`, `submit` | Interact using snapshot refs |
| `tabs` | — | List open tabs |
| `open` | `url` | Open new tab |
| `close` | `tab_id` | Close tab |
| `evaluate` | `script` | Execute JS in page context |
| `pdf` | — | Save page as PDF |
| `cookies` | `action` (get/set/clear), `name`, `value`, `url` | Cookie management |
| `wait` | `text`, `selector`, `url`, `timeout_ms` | Wait for page state |

- **Safety:**
  - SSRF guard on all navigation (block private/internal IPs, configurable allowlist)
  - `evaluate` disabled by default, opt-in via `config('ai.tools.browser.evaluate_enabled')`
  - Per-company concurrent browser limit (default 3)
  - Session timeout (auto-close idle browsers after 5 minutes)
  - All browser actions logged for audit
- **Capability:** `ai.tool_browser.execute`
- **Architecture:**

```
agent Tool Call → BrowserTool::execute()
    → BrowserPoolManager::acquireContext(companyId, sessionId)
        → Headless Chromium (server-side, isolated context)
    → Execute action (navigate/snapshot/act/...)
    → Return result (text snapshot, screenshot base64, structured JSON)
    → BrowserPoolManager::releaseContext() (on session end)
```

**5b. Browser Pool Infrastructure** — Managed headless browser lifecycle
- **Not a tool** — infrastructure that tools depend on
- **Components:**
  - `BrowserPoolManager` — Acquire/release browser contexts, enforce per-company limits
  - `BrowserContextFactory` — Create isolated Chromium contexts (separate cookie jars, storage)
  - `BrowserSsrfGuard` — URL validation before navigation (reusable by WebFetchTool)
- **Configuration:** `config('ai.tools.browser')`:

```php
'browser' => [
    'enabled' => env('AI_BROWSER_ENABLED', false),
    'executable_path' => env('AI_BROWSER_PATH', null),  // auto-detect if null
    'headless' => true,
    'max_contexts_per_company' => 3,
    'context_idle_timeout_seconds' => 300,
    'evaluate_enabled' => false,
    'ssrf_policy' => [
        'allow_private_network' => false,
        'hostname_allowlist' => [],  // e.g., ['*.example.com']
    ],
],
```

- **Deployment:** Requires Chromium installed on the server. Docker image should include `chromium-browser`. For local dev, auto-detect system Chrome/Brave/Chromium (same logic as OpenClaw).
- **Why not Browserless/remote CDP?** Support it as an option (`browser.remote_cdp_url`), but default to local headless. Enterprise customers may want to bring their own Browserless instance for scale.

**5c. OpenClaw vs BLB Browser Architecture Comparison**

| Aspect | OpenClaw | BLB |
|--------|----------|-----|
| Execution | Client-side (user's machine) | Server-side (headless, no GUI) |
| Profile isolation | Named profiles per user | Contexts per agent session per company |
| Browser launch | User-initiated or auto-start | Pool-managed, on-demand |
| Snapshot format | AI + ARIA refs | Same (adopt OpenClaw's ref-based interaction) |
| SSRF | Default allow private (trusted network) | Default deny private (enterprise security) |
| Use cases | Personal web automation | Enterprise RPA, scraping, monitoring |
| Playwright | Required for advanced actions | Required (PHP wrapper or CLI bridge) |

**PHP ↔ Chromium Bridge Options:**

| Option | Pros | Cons |
|--------|------|------|
| `chrome-php/chrome` (PHP CDP client) | Pure PHP, no Node dependency | Limited Playwright features, no AI snapshots |
| Playwright CLI subprocess | Full Playwright power, AI/ARIA snapshots | Requires Node.js runtime on server |
| `spatie/browsershot` | Simple screenshot/PDF | No interaction, no snapshots |
| Custom CDP client | Full control | High maintenance |

**Recommendation:** Use **Playwright CLI as a subprocess** (same as OpenClaw's architecture but invoked from PHP). Playwright provides the best snapshot/interaction quality. The Node.js dependency is contained to a CLI binary (not a gateway process). Package via `npm install playwright` in the project and invoke via `Process::run()`.

#### Phase 6: Multi-Channel Messaging Gateway (Post-Browser — Key Feature)

BLB's messaging gateway enables Agents to communicate across multiple platforms — both business accounts and personal relays. This is the full OpenClaw capability set, governed by BLB's authz system instead of config-file policies.

**Philosophy:** OpenClaw proves that personal messaging relays (personal WhatsApp, Telegram, Signal, iMessage) are genuinely useful — they let AI agents handle real-world communication. BLB does not restrict this capability. Instead, it makes it **safe through authz**: the human supervisor decides exactly which channels, which actions, and which agents can participate. Trust is built through transparency and control.

**6a. Channel Architecture**

OpenClaw's channel adapter pattern is well-designed and adopted in full:

```
Inbound Message (WhatsApp/Telegram/LinkedIn/Signal/iMessage/etc.)
    → Webhook Controller or Bridge Listener (Laravel HTTP / queue)
    → ChannelRouter (resolve company + account + agent + session)
    → Queue (async processing, session-serialized)
    → agent Runtime (agentic response, authz-checked per action)
    → Outbound Adapter (send reply back to channel)
```

**How BLB governs what OpenClaw leaves to config files:**

| Concern | OpenClaw | BLB |
|---------|----------|-----|
| Who can send messages | `openclaw.json` allow/deny | AuthZ: `messaging.{channel}.send` capability on the agent |
| Who can connect accounts | Manual config edit | AuthZ: `messaging.account.manage` on the supervisor |
| Channel restrictions per agent | Config per agent | AuthZ: supervisor grants/revokes per-channel capabilities |
| Rate limiting | Config per channel | AuthZ + rate limit middleware (per company + per channel) |
| Audit | Transcript files | Database audit log with actor, channel, direction, timestamp |
| Personal vs business | Config (personal by default) | Both supported; account type is metadata, authz governs access |

**Account model — business AND personal:**
- **Business accounts** — WhatsApp Business API, Telegram Bot, LinkedIn Company Page, Slack Workspace App. Credentials belong to the company.
- **Personal relays** — Personal WhatsApp (via WhatsApp Web bridge), personal Telegram (user token), Signal (signal-cli bridge), iMessage (BlueBubbles/mac bridge). Credentials belong to the individual employee or supervisor.
- Both types live in `messaging_accounts` with `company_id` FK and `account_type` enum (`business` | `personal`).
- Personal accounts are **opt-in by the account owner** and visible only to agents that the owner supervises (authz-enforced).
- The supervisor who connects a personal account decides which of their agents can use it — not a global admin.

**6b. `MessageTool`** — Send messages across channels
- **OpenClaw parallel:** `message` tool (send/react/edit/delete/poll/search + channel-specific actions)
- **BLB approach:** Single deep tool with action-based dispatch, company-scoped, with channel routing

| Action | Parameters | Description |
|--------|-----------|-------------|
| `send` | `channel`, `target`, `text`, `media_path` | Send a message |
| `reply` | `channel`, `message_id`, `text` | Reply to a specific message |
| `react` | `channel`, `message_id`, `emoji` | React to a message |
| `edit` | `channel`, `message_id`, `text` | Edit a sent message |
| `delete` | `channel`, `message_id` | Delete a sent message |
| `poll` | `channel`, `target`, `question`, `options` | Create a poll |
| `list_conversations` | `channel`, `limit` | List recent conversations |
| `search` | `channel`, `query`, `limit` | Search message history |

- **Parameters:** `action` (required), `channel` (whatsapp/telegram/linkedin/slack/email), plus action-specific params
- **Safety:**
  - Rate limiting per channel per company (respect platform API limits)
  - Content policy enforcement (configurable blocklists, PII detection hooks)
  - Media file validation (type, size limits)
  - No cross-company message routing (strict tenant isolation)
- **Capability:** `ai.tool_message.execute` (Lara), `messaging.{channel}.send` (agents)

**6c. Channel Adapters** — Per-platform integration modules

Each channel is a BLB module under `app/Modules/Channels/`:

**Business account channels:**

| Channel | API | Account Type | Priority |
|---------|-----|-------------|----------|
| **WhatsApp Business** | WhatsApp Business Cloud API (Meta) | Business account | High |
| **Telegram Bot** | Telegram Bot API | Bot token | High |
| **LinkedIn** | LinkedIn Marketing/Messaging API | Company Page + OAuth | High |
| **Slack** | Slack Web API + Events API | Workspace app (OAuth) | Medium |
| **Email** | SMTP/IMAP (Laravel Mail) | Company email account | Medium |
| **SMS** | Twilio / Vonage API | Business phone number | Medium |
| **Instagram** | Instagram Graph API (Meta) | Business account | Medium |
| **Facebook Messenger** | Messenger Platform API (Meta) | Business Page | Medium |

**Personal relay channels (OpenClaw-proven, authz-governed):**

| Channel | Bridge/API | Account Type | Priority |
|---------|-----------|-------------|----------|
| **WhatsApp Personal** | WhatsApp Web bridge (Baileys/whatsapp-web.js) | Personal (supervisor-owned) | High |
| **Telegram Personal** | Telegram User API (MTProto / tdlib) | Personal (user token) | High |
| **Signal** | signal-cli bridge | Personal (phone number) | Medium |
| **iMessage** | BlueBubbles / mac bridge (requires macOS host) | Personal (Apple ID) | Lower |
| **Discord** | Discord Bot API | Server bot or personal | Lower |

**Personal relay safety model:**
- Personal accounts are connected by the **account owner** (supervisor or employee), not by a system admin
- The owner explicitly grants access to specific agents under their supervision
- AuthZ enforces: a agent can only use personal channels that its supervisor (or the supervisor's chain) has authorized
- Personal relay credentials are encrypted and isolated — no agent or other supervisor can access them
- All messages sent via personal relays are logged in the audit trail with the agent actor and the authorizing supervisor
- Rate limits on personal relays are stricter by default (protecting the account owner from platform bans)

**Per-adapter contract (inspired by OpenClaw's channel plugin interface):**

```php
interface ChannelAdapter
{
    /** Channel identifier (e.g., 'whatsapp', 'telegram') */
    public function channelId(): string;

    /** Human-readable label */
    public function label(): string;

    /** Resolve account config for a company */
    public function resolveAccount(int $companyId, ?string $accountId = null): ?ChannelAccount;

    /** Send a text message */
    public function sendText(ChannelAccount $account, string $target, string $text, array $options = []): SendResult;

    /** Send media (image, document, audio) */
    public function sendMedia(ChannelAccount $account, string $target, string $mediaPath, ?string $caption = null): SendResult;

    /** Process inbound webhook payload */
    public function parseInbound(Request $request): ?InboundMessage;

    /** Supported capabilities for this channel */
    public function capabilities(): ChannelCapabilities;
}
```

**`ChannelCapabilities` value object:**

```php
class ChannelCapabilities
{
    public function __construct(
        public readonly bool $supportsReactions = false,
        public readonly bool $supportsEditing = false,
        public readonly bool $supportsDeletion = false,
        public readonly bool $supportsPolls = false,
        public readonly bool $supportsThreads = false,
        public readonly bool $supportsMedia = true,
        public readonly bool $supportsButtons = false,
        public readonly bool $supportsSearch = false,
        public readonly array $mediaTypes = ['image', 'document'],
        public readonly int $maxMessageLength = 4096,
    ) {}
}
```

**6d. Messaging Infrastructure**

**Database tables:**

| Table | Purpose |
|-------|---------|
| `messaging_accounts` | Company-scoped channel credentials (encrypted). Includes `account_type` (`business`/`personal`), `owner_employee_id` (for personal relays), `channel_id`, `company_id`. |
| `messaging_conversations` | Conversation threads (channel, participants, agent assignment, company_id) |
| `messaging_messages` | Message log (direction, content_hash, status, actor_id, timestamps) |
| `messaging_account_grants` | Which agents can use which accounts. FK: `account_id`, `employee_id` (agent), `granted_by` (supervisor). AuthZ-enforced. |

**Configuration:** `config('messaging')` in a dedicated Messaging module config:

```php
'channels' => [
    'whatsapp' => [
        'enabled' => env('MESSAGING_WHATSAPP_ENABLED', false),
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'rate_limit_per_minute' => 60,
    ],
    'telegram' => [
        'enabled' => env('MESSAGING_TELEGRAM_ENABLED', false),
        'rate_limit_per_minute' => 30,
    ],
    'linkedin' => [
        'enabled' => env('MESSAGING_LINKEDIN_ENABLED', false),
        'rate_limit_per_minute' => 20,
    ],
],
```

**6e. Inbound Message Processing**

```
Webhook POST /api/messaging/{channel}/webhook
    → ChannelWebhookController::handle()
    → $adapter->parseInbound($request)
    → ChannelRouter::route($inboundMessage)
        → Resolve company by account
        → Resolve or create conversation
        → Resolve assigned agent (or Lara as default)
    → Dispatch ProcessInboundMessage job (queue)
    → agent agentic runtime processes message
    → $adapter->sendText() for response
```

**OpenClaw pattern adopted:** Session-based serialization (one conversation = one queue lane) to prevent race conditions on concurrent inbound messages.

**6f. LinkedIn-Specific Considerations**

LinkedIn has unique constraints compared to WhatsApp/Telegram:
- **OAuth 2.0 required** — No simple API key; requires company admin to authorize the app
- **Rate limits are strict** — LinkedIn aggressively rate-limits messaging APIs
- **Message types differ** — LinkedIn messaging (InMail, connection messages) vs. Company Page posts
- **Compliance requirements** — LinkedIn enforces anti-spam policies; bulk messaging is restricted
- **API access tiers** — Some messaging features require LinkedIn Marketing Developer Platform approval

**BLB approach for LinkedIn:**
- Start with **Company Page posting** (simpler API, broader access)
- Add **LinkedIn Messaging** when the company has Marketing Developer Platform access
- Use **LinkedIn Lead Gen Forms** webhook integration for inbound leads → agent assignment
- Store OAuth tokens encrypted in `messaging_accounts` with refresh token rotation

**6g. Messaging AuthZ Capabilities** — Granular, supervisor-controlled

Every messaging action is a distinct authz capability. The supervisor decides what their agents can do — per channel, per action. This is BLB's key differentiator: OpenClaw uses config-file allow/deny lists; BLB uses the proven AuthZ capability system with delegation constraints (agent can never exceed supervisor's own capabilities).

| Capability | Scope | Description |
|-----------|-------|-------------|
| `messaging.account.manage` | Supervisor | Connect/disconnect channel accounts (business or personal) |
| `messaging.account.grant` | Supervisor | Grant a agent access to a specific account |
| `messaging.account.revoke` | Supervisor | Revoke a agent's access to a specific account |
| `messaging.whatsapp.send` | agent | Send messages via WhatsApp (business or personal) |
| `messaging.whatsapp.react` | agent | React to WhatsApp messages |
| `messaging.whatsapp.media` | agent | Send media (images, documents) via WhatsApp |
| `messaging.telegram.send` | agent | Send messages via Telegram |
| `messaging.telegram.react` | agent | React to Telegram messages |
| `messaging.telegram.edit` | agent | Edit sent Telegram messages |
| `messaging.telegram.delete` | agent | Delete Telegram messages |
| `messaging.telegram.poll` | agent | Create Telegram polls |
| `messaging.linkedin.send` | agent | Send LinkedIn messages or post to Company Page |
| `messaging.signal.send` | agent | Send messages via Signal relay |
| `messaging.imessage.send` | agent | Send messages via iMessage relay |
| `messaging.slack.send` | agent | Send Slack messages |
| `messaging.email.send` | agent | Send emails |
| `messaging.sms.send` | agent | Send SMS messages |
| `messaging.any.search` | agent | Search message history across channels |
| `ai.tool_message.execute` | Lara | Lara can invoke the MessageTool |

**AuthZ role bundles for messaging:**

| Role | Capabilities | Use Case |
|------|-------------|----------|
| `messaging_reader` | `messaging.any.search` | agent that monitors conversations (read-only) |
| `messaging_responder` | `messaging.{channel}.send` + `messaging.{channel}.react` | agent that responds to inbound messages |
| `messaging_operator` | All `messaging.*` for assigned channels | agent with full messaging power on granted channels |
| `messaging_admin` | `messaging.account.manage` + `messaging.account.grant` + `messaging.account.revoke` | Supervisor who manages channel accounts |

**Delegation constraint (existing AuthZ invariant):** A supervisor can only grant `messaging.whatsapp.send` to a agent if the supervisor themselves has `messaging.whatsapp.send`. This prevents privilege escalation — the same invariant that governs all agent capabilities in BLB.

---

### 5.6 Comprehensive Tool AuthZ Capability Map

Every tool in BLB declares a `requiredCapability()`. The supervisor grants or revokes these on a per-agent basis. The full map across all phases:

| Tool | Capability | Phase | Description |
|------|-----------|-------|-------------|
| `artisan` | `ai.tool_artisan.execute` | Existing | Run `php artisan` commands |
| `navigate` | `ai.tool_navigate.execute` | Existing | Client-side SPA navigation |
| `query_data` | `ai.tool_query_data.execute` | 1 | Read-only SQL queries |
| `web_search` | `ai.tool_web_search.execute` | 1 | Web search via provider API |
| `web_fetch` | `ai.tool_web_fetch.execute` | 1 | Fetch + extract URL content |
| `system_info` | `ai.tool_system_info.execute` | 1 | BLB instance state |
| `memory_search` | `ai.tool_memory_search.execute` | 2 | Semantic memory search |
| `memory_get` | `ai.tool_memory_get.execute` | 2 | Read knowledge files |
| `guide` | `ai.tool_guide.execute` | 2 | Framework documentation |
| `delegate_task` | `ai.tool_delegate.execute` | 3 | Dispatch work to agents |
| `delegation_status` | `ai.tool_delegation_status.execute` | 3 | Poll dispatch results |
| `agent_list` | `ai.tool_agent_list.execute` | 3 | Discover available agents |
| `schedule_task` | `ai.tool_schedule.execute` | 4 | CRUD scheduled tasks |
| `notification` | `ai.tool_notification.execute` | 4 | Internal notifications |
| `browser` | `ai.tool_browser.execute` | 5 | Headless browser automation |
| `browser.evaluate` | `ai.tool_browser_evaluate.execute` | 5 | JS execution in browser (opt-in, high-trust) |
| `message` | `ai.tool_message.execute` | 6 | Multi-channel messaging (Lara) |
| `message` (per channel) | `messaging.{channel}.send` | 6 | Per-channel send (agents) — see §6g |
| `document_analysis` | `ai.tool_document_analysis.execute` | 7 | PDF analysis |
| `image_analysis` | `ai.tool_image_analysis.execute` | 7 | Vision model analysis |
| `write_js` | `ai.tool_write_js.execute` | 8 | Client-side JS execution |

**How a supervisor configures a agent (example):**

A sales manager creates a "Lead Qualifier" agent and grants:
- ✅ `messaging.whatsapp.send` — respond to WhatsApp leads
- ✅ `messaging.linkedin.send` — post to LinkedIn Company Page
- ✅ `ai.tool_web_search.execute` — research prospects
- ✅ `ai.tool_web_fetch.execute` — read prospect websites
- ✅ `ai.tool_query_data.execute` — look up CRM data
- ❌ `ai.tool_artisan.execute` — no system commands
- ❌ `ai.tool_browser.execute` — no browser automation
- ❌ `messaging.telegram.send` — not needed for this role

The agent can only do what its supervisor explicitly allows. The supervisor can only grant what they themselves have. The chain is auditable. The power is real. The control is human.

---

#### Phase 7: Media & Document Analysis (Post-Messaging)

**7a. `DocumentAnalysisTool`** — Analyze PDFs and documents
- **OpenClaw parallel:** `pdf`
- **BLB approach:** For providers supporting native PDF (Anthropic, Google), send raw bytes. For others, extract text via `smalot/pdfparser` or similar PHP library, then analyze via LLM.
- **Parameters:** `path` (storage path or URL), `prompt`, `pages` (filter), `model` (optional override)
- **Capability:** `ai.tool_document_analysis.execute`

**7b. `ImageAnalysisTool`** — Analyze images with vision models
- **OpenClaw parallel:** `image`
- **BLB approach:** Send image to configured image model (multimodal providers)
- **Parameters:** `path` (storage path or URL), `prompt`
- **Capability:** `ai.tool_image_analysis.execute`

#### Phase 8: Enhanced Artisan & Runtime (Post-Media)

**8a. `ArtisanTool` v2** — Enhanced command execution
- **OpenClaw parallel:** `exec` + `process`
- **BLB approach:** Add background execution support via Laravel queues. Support `timeout`, `background` flag, polling for results. Keep artisan-only restriction.
- **Parameters (additions):** `background` (bool), `timeout` (seconds)
- **Existing capability:** `ai.tool_artisan.execute`

**8b. `WriteJsTool`** — Execute client-side JavaScript
- **OpenClaw parallel:** `browser evaluate`
- **BLB approach:** Return `<agent-action>` blocks (same pattern as NavigateTool) for client-side execution
- **Parameters:** `script` (JS string), `description` (what it does)
- **Safety:** Script validation, CSP compliance, whitelisted APIs only
- **Capability:** `ai.tool_write_js.execute`

---

## 5. Tool Infrastructure Enhancements

### 5.1 Tool Groups & Profiles (Adapt OpenClaw Pattern)

Map OpenClaw's config-file tool groups to BLB's authz role system:

| BLB Authz Role | Tools Included | OpenClaw Equivalent |
|----------------|----------------|---------------------|
| `lara_viewer` | `system_info`, `guide`, `agent_list`, `delegation_status` | `minimal` |
| `lara_analyst` | Above + `query_data`, `web_search`, `web_fetch`, `memory_search`, `memory_get` | `coding` (adapted) |
| `lara_operator` | Above + `navigate`, `delegate_task`, `schedule_task`, `notification`, `message` | `messaging` (adapted) |
| `agent_power_user` | All tools including `artisan`, `browser`, `write_js`, `document_analysis`, `image_analysis` | `full` |

This maps cleanly to existing BLB authz infrastructure — no new config system needed.

### 5.2 Tool Result Envelope

Standardize tool results with a consistent structure (inspired by OpenClaw's `jsonResult` / `imageResult`):

```php
interface AgentToolResult
{
    public function toToolResponse(): string;   // For LLM consumption
    public function toMeta(): array;            // For debug panel / logging
}
```

Concrete result types:
- `TextToolResult` — plain text response
- `JsonToolResult` — structured JSON (pretty-printed for LLM)
- `ActionToolResult` — contains `<agent-action>` blocks (NavigateTool, WriteJsTool)
- `ErrorToolResult` — structured error with code + message

### 5.3 Tool Execution Safety

Enhance the existing safety model with OpenClaw-inspired patterns:

**Loop detection (lightweight):**
- Track tool call history per conversation turn (already done: max 10 iterations)
- Add: detect repeated identical tool calls (same name + same args) within a turn
- Add: configurable per-company via `config('ai.tools.max_iterations')` (default 10)

**Execution timeout:**
- Each tool declares a `timeout(): int` (seconds) in the `AgentTool` interface
- Registry enforces via `set_time_limit()` or `pcntl_alarm()` wrapping
- Default: 30 seconds (same as current ArtisanTool)

**Cost tracking:**
- Tools that make LLM sub-calls (DocumentAnalysisTool, ImageAnalysisTool) must report token usage in their result metadata
- Aggregate tool-call costs into the parent conversation turn's `meta.tokens`

### 5.4 Tool Registration Pattern

Keep the current explicit registration pattern (in AI ServiceProvider), but add auto-discovery as an option:

```php
// Current (explicit) — keep for control
$registry->register(new ArtisanTool);
$registry->register(new NavigateTool);

// Future (auto-discovery) — scan Tools/ directory
// Only if/when the tool count grows significantly
```

### 5.5 Async Tool Execution

For long-running tools (web search, document analysis, delegation), add async support:

```php
interface AsyncAgentTool extends AgentTool
{
    /**
     * Whether this tool should execute asynchronously via queue.
     */
    public function isAsync(): bool;

    /**
     * Execute asynchronously. Returns a dispatch ID.
     * Result is retrieved via a separate polling mechanism.
     */
    public function dispatch(array $arguments): string;

    /**
     * Poll for async result.
     */
    public function poll(string $dispatchId): ?string;
}
```

The `AgenticRuntime` loop would handle async tools by:
1. Dispatching the tool and getting a `dispatch_id`
2. Injecting a "pending" tool result
3. On next iteration, polling for completion
4. Continuing when result is available

---

## 6. What NOT to Mirror from OpenClaw

| OpenClaw Feature | Why Not for BLB |
|------------------|-----------------|
| `nodes` (device pairing, camera, screen) | BLB is a web app. Camera/screen capture adds complexity without clear enterprise value. Reconsider when use cases emerge. |
| `canvas` (A2UI rendering) | BLB uses Livewire/Blade for UI, not a canvas overlay |
| Personal browser profiles (named `openclaw`, `work`, `chrome`) | BLB's browser is server-side headless (Phase 5), not a personal browser manager. No profile switching, no extension relay. |
| Gateway lifecycle management (`gateway restart`, `config.apply`) | BLB is a standard Laravel app managed by ops tooling, not a self-managing daemon |
| Plugin/extension runtime loading (jiti TypeScript modules) | BLB modules are PHP, discovered by Laravel's service container; the module system already exists |
| AgentSkills `.md` files with YAML frontmatter | BLB's prompt engineering is framework-managed (LaraPromptFactory), not file-driven; skill-like knowledge goes into `LaraKnowledgeNavigator` or memory |
| `apply_patch` (multi-file code edits) | BLB is not a code editor; ArtisanTool + future scaffolding commands cover this |
| `tts` (text-to-speech) | Not a core need today; can be a module extension later |

**Note:** Personal messaging relays (WhatsApp, Telegram, Signal, iMessage) are explicitly **IN scope** — see Phase 6. BLB does not limit capability out of fear. It governs with authz.

---

## 7. Implementation Order (Recommended)

```
Phase 1: Foundation Tools (immediate)
  1a: QueryDataTool              ← Highest user value (data questions)
  1b: WebSearchTool              ← External knowledge access
  1c: WebFetchTool               ← URL content extraction
  1d: SystemInfoTool             ← Instance self-awareness
   ↓
Phase 2: Memory & Knowledge
  2a: MemorySearchTool           ← Semantic recall (needs embedding infra)
  2b: MemoryGetTool              ← Direct file access
  2c: GuideTool                  ← Framework docs as tool
   ↓
Phase 3: Delegation & Multi-Agent
  3a: DelegateTaskTool           ← Real agent execution (needs queue infra)
  3b: DelegationStatusTool       ← Dispatch polling
  3c: AgentListTool             ← agent discovery as tool
   ↓
Phase 4: Automation & Scheduling
  4a: ScheduleTaskTool           ← Cron-like agent scheduling
  4b: NotificationTool           ← Internal notifications
   ↓
Phase 5: Browser Automation ★ KEY FEATURE
  5a: BrowserTool                ← Headless browser actions (navigate/snapshot/act)
  5b: BrowserPoolManager         ← Infra: pool lifecycle, context isolation
   ↓
Phase 6: Multi-Channel Messaging ★ KEY FEATURE
  6a: Channel adapter infra      ← ChannelAdapter contract, webhook routing, DB tables
  6b: MessageTool                ← send/reply/react/search across channels
  6c: WhatsApp adapter           ← WhatsApp Business Cloud API
  6d: Telegram adapter           ← Telegram Bot API
  6e: LinkedIn adapter           ← Company Pages + Marketing API
  6f: Slack/Email/SMS adapters   ← Lower priority channels
   ↓
Phase 7: Media & Document Analysis
  7a: DocumentAnalysisTool       ← PDF analysis
  7b: ImageAnalysisTool          ← Vision model analysis
   ↓
Phase 8: Enhanced Artisan & Runtime
  8a: ArtisanTool v2             ← Background execution
  8b: WriteJsTool                ← Client-side JS execution
```

Each phase is independently valuable. Phase 1 makes Lara immediately more useful. Phase 2 gives her memory. Phase 3 makes her a true orchestrator. Phases 5–6 are the key differentiators — browser automation and multi-channel messaging are BLB's primary enterprise value propositions, enabling agents to interact with external websites and communicate across WhatsApp, Telegram, LinkedIn, and more.

---

## 8. OpenClaw Patterns Worth Adopting (Adapted for BLB)

### 8.1 Structured Error Types

OpenClaw's `ToolInputError` (400) and `ToolAuthorizationError` (403) hierarchy is clean. BLB should add:

```php
namespace App\Modules\Core\AI\Exceptions;

class ToolInputException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode = 400)
    {
        parent::__construct($message);
    }
}

class ToolAuthorizationException extends ToolInputException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 403);
    }
}

class ToolTimeoutException extends ToolInputException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 408);
    }
}
```

### 8.2 Factory-Based Tool Creation

OpenClaw creates tools conditionally: `createMemorySearchTool(options)` returns `null` when prerequisites aren't met. BLB should adopt this for tools with runtime dependencies:

```php
// In AI ServiceProvider
$webSearchTool = WebSearchTool::createIfConfigured();
if ($webSearchTool !== null) {
    $registry->register($webSearchTool);
}
```

### 8.3 Caching for Repeated Queries

OpenClaw caches web search results (15-minute TTL). BLB should use Laravel's cache:

```php
Cache::remember(
    'lara_tool:web_search:' . md5($query),
    now()->addMinutes(15),
    fn () => $this->executeSearch($query)
);
```

### 8.4 SSRF Protection Pattern

OpenClaw's web_fetch blocks private/internal hostnames. BLB must replicate this for `WebFetchTool`:

```php
private function isSafeUrl(string $url): bool
{
    $parsed = parse_url($url);
    $host = $parsed['host'] ?? '';

    // Block private/internal ranges
    $ip = gethostbyname($host);
    return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}
```

### 8.5 Tool Call Metadata in Runtime Response

OpenClaw tracks `tool_actions` in response metadata. BLB already does this (`AgenticRuntime` records `tool_actions[]` with name, args, result preview). Keep and expand this pattern for observability.

---

## 9. Relationship to Existing Orchestration Services

Several existing Lara services perform work that should eventually be exposed as tools:

| Current Service | Future Tool Equivalent | Migration Path |
|----------------|----------------------|----------------|
| `LaraKnowledgeNavigator` (via `/guide`) | `GuideTool` (Phase 2c) | Wrap service as tool; deprecate slash command |
| `LaraCapabilityMatcher` (via `/delegate`) | `DelegateTaskTool` (Phase 3a) | Wrap matcher + dispatcher as tool |
| `LaraModelCatalogQueryService` (via `/models`) | `ModelQueryTool` (future) | Wrap as tool for autonomous model queries |
| `LaraNavigationRouter` (via `/go`) | `NavigateTool` (already exists) | Already a tool; remove slash command duplication |

**Migration strategy:** Keep slash commands operational during transition. Once tools are stable and the LLM reliably invokes them, remove the slash-command parsing from `LaraOrchestrationService` and let the agentic runtime handle it naturally.

---

## 10. Admin Tool Configuration via Lara

Lara should be able to help admins configure tool settings conversationally. Instead of navigating to config pages, an admin can say "Set up web search with my Parallel API key" and Lara handles the configuration.

### 10.1 Configuration Tool

**`ToolConfigTool`** — Manage tool configuration on behalf of the admin
- **Parameters:** `action` (`get`/`set`/`list`), `tool` (tool name), `key`, `value`
- **Capability:** `ai.tool_config.manage` (admin-only)
- **Scope:** Company-scoped — settings apply to the admin's company
- **Safety:** Values containing secrets (API keys) are stored encrypted. Read-back masks secrets (`par_****...`). Only admins with the capability can modify tool configuration.

**Configurable per tool:**

| Tool | Configurable Settings |
|------|----------------------|
| `web_search` | `provider` (parallel/brave), `{provider}.api_key`, `default_count` |
| `web_fetch` | `max_chars`, `timeout_seconds`, `ssrf_allow_private` |
| `query_data` | `max_rows`, `timeout_seconds`, `allowed_tables` (optional allowlist) |
| `browser` | `enabled`, `max_contexts`, `evaluate_enabled` |
| `message` | Channel-level enable/disable, rate limits |

**Storage:** `ai_tool_configs` table with `company_id`, `tool_name`, `config_json` (encrypted). Tools read config at execution time via a `ToolConfigService` that falls back to `config('ai.tools.*')` defaults.

**Conversational UX examples:**
- "Enable web search" → Lara checks if API key is set, prompts for one if missing
- "Switch web search to Brave" → Updates provider, validates API key exists
- "What tools are configured?" → Lists all tools with current status

---

## 11. Open Questions

1. **Tool timeout per-provider** — Some LLM providers have longer function-calling response times. Should tool timeout awareness extend to the LLM client layer?
2. **Streaming tool progress** — OpenClaw uses SSE/WebSocket for real-time tool execution feedback. BLB's Livewire chat uses broadcasting. Should tool progress events broadcast via Echo?
3. **Tool versioning** — When tool schemas change, how to handle in-flight conversations that reference old schemas?
4. **Per-company tool enablement** — Should tool availability be configurable per company (beyond authz), similar to OpenClaw's `tools.allow/deny`? Or is authz sufficient?
5. **Rate limiting per tool** — OpenClaw defers this. BLB should decide: per-user, per-company, or global rate limits on expensive tools (web search, document analysis)?

---

## Revision History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 0.1 | 2026-03-07 | AI + Kiat | Initial blueprint — full OpenClaw analysis, BLB gap assessment, phased roadmap, design principles |
| 0.2 | 2026-03-07 | AI + Kiat | Added Phase 5 (Browser Automation) and Phase 6 (Multi-Channel Messaging) as key features. Browser: server-side headless Chromium pool with Playwright CLI bridge, SSRF-guarded, company-scoped contexts. Messaging: channel adapter architecture with business + personal relay support. |
| 0.3 | 2026-03-07 | AI + Kiat | Philosophy shift: "build trust, not build fear." Added personal messaging relays (WhatsApp Personal, Telegram Personal, Signal, iMessage) as in-scope — governed by authz, not restricted by design. Added principle §8 "Unleash capability, govern with authz." Added §6g comprehensive messaging AuthZ capability map (19 capabilities, 4 role bundles). Added §5.6 full tool AuthZ capability map across all 8 phases (21 capabilities). Added `messaging_account_grants` table for supervisor-controlled agent access to accounts. Added personal relay safety model. Concrete agent configuration example (Lead Qualifier). Removed personal messaging from exclusions list. |
| 0.4 | 2026-03-07 | AI + Kiat | Added Parallel Search as recommended WebSearchTool provider (§4.2 Phase 1b). Added §10 Admin Tool Configuration via Lara — conversational tool setup with `ToolConfigTool`, encrypted per-company storage, and `ToolConfigService` fallback chain. QueryDataTool implemented in `lara-tools` worktree with 24 tests passing. |
| 0.5 | 2026-03-08 | AI + Kiat | Implementation status: Phases 1–4 complete (15 tools, 271 tests). Phase 5 (Browser Automation) started — BrowserTool, BrowserPoolManager, BrowserContextFactory, BrowserSsrfGuard implemented as stubs with 70 tests (341 total). Config, authz capabilities, and ServiceProvider wired. All in `lara-tools` worktree. |
| 0.6 | 2026-03-08 | AI + Kiat | Phase 5 complete, Phase 6 (Messaging) complete. MessageTool with 8 action-based dispatch (send/reply/react/edit/delete/poll/list_conversations/search), ChannelAdapter contract, ChannelAdapterRegistry, 4 stub adapters (WhatsApp, Telegram, Slack, Email), ChannelCapabilities/ChannelAccount/SendResult/InboundMessage DTOs. 19 messaging authz capabilities + 4 role bundles (messaging_reader/responder/operator/admin). 8 new authz verbs (manage, grant, revoke, send, react, edit, media, poll, search). 51 new tests (392 total). All in `lara-tools` worktree. |
| 0.7 | 2026-03-08 | AI + Kiat | Phase 7 (Media & Documents) complete, Phase 8 (Enhanced Runtime) complete. DocumentAnalysisTool (PDF analysis stub with page filter validation), ImageAnalysisTool (vision model stub with format validation + URL support), WriteJsTool (client-side JS via agent-action blocks, 7 blocked patterns for security), ArtisanTool v2 (background execution via dispatch_id, configurable timeout 1–300s clamped). 2 new authz capabilities (ai.tool_document_analysis.execute, ai.tool_image_analysis.execute) added to agent_operator and agent_power_user roles. 108 new tests (500 total, 994 assertions). All phases complete. All in `lara-tools` worktree. |
| 0.8 | 2026-03-09 | AI + Kiat | Tool abstraction layer: Extracted `Tool` interface, `AbstractTool`, `AbstractActionTool`, `ToolSchemaBuilder`, `ToolResult`, `ToolArgumentException`, `ToolCategory`/`ToolRiskClass` enums into `Base/AI`. All 20 tools migrated: 17 extend `AbstractTool`, 3 extend `AbstractActionTool`. Replaced `AgentTool` contract. Updated §3.1 infrastructure table. See `docs/Base/AI/tool-framework.md`. |

---

## 12. Implementation Status

| Phase | Status | Tools | Tests | Notes |
|-------|--------|-------|-------|-------|
| 1: Foundation | ✅ Complete | QueryDataTool, WebSearchTool, WebFetchTool, SystemInfoTool | 101 | WebSearchTool conditionally registered via `createIfConfigured()` |
| 2: Memory & Knowledge | ✅ Complete | MemoryGetTool, GuideTool, MemorySearchTool | 62 | MemorySearchTool conditionally registered via `createIfAvailable()` |
| 3: Delegation | ✅ Complete | DelegateTaskTool, DelegationStatusTool, AgentListTool | 52 | DelegationStatusTool is a stub pending dispatch persistence |
| 4: Automation | ✅ Complete | ScheduleTaskTool, NotificationTool | 56 | ScheduleTaskTool is a stub pending `scheduled_tasks` table |
| 5: Browser Automation | ✅ Complete | BrowserTool | 70 | Stub responses; BrowserPoolManager/BrowserContextFactory/BrowserSsrfGuard infrastructure scaffolded. Playwright CLI integration pending. |
| 6: Messaging | ✅ Complete | MessageTool | 51 | Stub responses; ChannelAdapter contract + ChannelAdapterRegistry + 4 adapters (WhatsApp, Telegram, Slack, Email). 19 authz capabilities + 4 role bundles. Channel integration pending. |
| 7: Media & Documents | ✅ Complete | DocumentAnalysisTool, ImageAnalysisTool | 55 | Stub responses; DocumentAnalysisTool validates page filter format. ImageAnalysisTool validates image extensions, accepts URLs. 2 new authz capabilities added to operator/power user roles. |
| 8: Enhanced Runtime | ✅ Complete | ArtisanTool v2, WriteJsTool | 53 | ArtisanTool v2 adds background execution (stub dispatch_id), configurable timeout (1–300s). WriteJsTool returns `<agent-action>` blocks with 7 blocked patterns for security. |

**Total: 20 tools, 500 tests (994 assertions)**
