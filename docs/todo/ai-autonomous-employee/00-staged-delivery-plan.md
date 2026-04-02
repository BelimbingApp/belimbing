# AI Agent - Staged Delivery Plan

**Status:** Active — Stage 0 implemented
**Source Context:** `docs/architecture/ai/agent-model.md`
**Last Updated:** 2026-03-04
**Prerequisite:** `docs/architecture/authorization.md`, `docs/todo/authorization/00-prd.md`

## 1. Problem Essence
Deliver BLB Agent incrementally so every phase ships a UI that users can see, interact with, and test before deeper backend complexity is added.

## 2. Public Interface First
At every stage, the user-facing interface must expose these stable operations:

1. Send a message to Agent
2. View Agent response and action status
3. Approve or reject sensitive actions (when required)
4. View conversation and action history

Non-goals for early stages:

1. Full multi-channel rollout (WhatsApp/Telegram/Slack all at once)
2. Autonomous execution without visibility/audit
3. Cross-company Agent collaboration

## 3. Stage Plan

## Stage -1 - Authorization Foundation (Prerequisite)

**Goal**
Ship shared AuthZ (human user + Agent delegated actor) before sensitive Agent operations.

**UI You Can Use**
1. Basic role/capability assignment screen (or admin console workflow)
2. Menu visibility reflecting capabilities
3. Denied action feedback with reason code

**Exit Criteria**
1. Deny-by-default enforced across web/API
2. Agent delegation evaluation available in the same policy engine — this means AuthZ Stage D (`docs/todo/authorization/00-prd.md`): `PrincipalType::AGENT` actor and same RBAC as human are operational. Assignment-time validation and cascade revocation (Stage D remaining items) are not required for Stage 0 since it is a read-only playground with no sensitive write tools.
3. Decision logs available for allow/deny traces

---

## Stage 0 - Agent Playground (Web Only, No Business Actions)

**Goal**
Establish a safe, testable end-to-end loop: web chat UI -> Agent runtime -> response UI.

**UI You Can Use**
1. `Agent Playground` page (per authenticated user)
2. Chat panel with message input + loading indicator + final response rendering
3. Session sidebar (create/switch session)
4. Debug panel (latency, token usage, model, run id)

**What Is Implemented**
1. Agent as employee: `employees` with `employee_type` and `job_description`; file-based sessions (JSONL per session + `.meta.json`) in per-Agent workspace directories (see [01-stage-0 §3.2](01-stage-0-agent-playground.md))
2. Per-agent LLM configuration: company-level provider credentials (`ai_providers` table, encrypted keys), per-agent model selection via workspace `config.json` (multi-model with ordered fallback), config resolution cascade (agent → company provider → global defaults). See `docs/architecture/ai/agent-model.md` §15.
3. Basic runtime orchestration with no high-risk tools (per-agent model-aware) and **fallback attempt trace metadata** (OpenClaw-style: provider, model, error, error_type, latency_ms per attempt). See `docs/architecture/ai/agent-model.md` §15.5.
4. Persistence for all user/assistant messages (append-only JSONL files with `LOCK_EX`)
5. Provider management page (`LLM Providers`) with catalog/manual add, model sync, default model, and provider priority controls.
6. Debug panel in playground surfaces runtime metadata including collapsible fallback attempt trace.

**Manual Test Script**
1. Open playground page as a user
2. Create a new session and send 5 messages
3. Refresh page and confirm history is preserved
4. Open a second session and verify isolation

**Exit Criteria**
1. No message loss after refresh/session switch
2. Session-level isolation verified
3. All criteria in [Stage 0 implementation checklist](01-stage-0-agent-playground.md) §8 met

---

## Stage 1 - Tool-Backed Task Cards (Read-Only + Low-Risk Actions)

**Goal**
Move from “chat only” to “chat + useful operations” with explicit UI feedback.

**UI You Can Use**
1. Chat page enhanced with `Action Cards` in timeline
2. Each card shows:
   - proposed operation
   - input parameters
   - status (`pending`, `running`, `success`, `failed`)
3. “Dry run” toggle to preview operations before execution
4. Simple result visualizations (table/list for lookups)

**Target Deliverables**
1. Tool registry v1 with 5-10 low/medium-risk tools (lookup, reporting, leave draft)
2. Structured tool call logging (`ae_tool_calls`)
3. Policy layer v1 (allow/deny by role/company)

**Manual Test Script**
1. Ask Agent for employee/customer lookup
2. Confirm card displays tool name + params + result
3. Trigger dry-run and verify no write occurs
4. Force tool failure (invalid input) and verify error shown in UI

**Exit Criteria**
1. Every tool call visible in UI and persisted
2. Failed tool calls provide actionable error messages
3. Policy denial surfaces clear reason to end user

---

## Stage 2 - Approval Inbox (Human-in-the-Loop for Sensitive Actions)

**Goal**
Add safe write operations with explicit approval UX.

**UI You Can Use**
1. `Approval Inbox` page for managers/approvers
2. Approval request detail page with context and diff-style summary
3. One-click actions: `Approve`, `Reject`, `Request Clarification`
4. Chat timeline shows approval state transitions in real time

**Target Deliverables**
1. Approval workflow engine for designated tools/actions
2. Request state machine: `draft -> pending_approval -> approved/rejected -> executed`
3. Audit log entries for every decision

**Manual Test Script**
1. Employee asks Agent to submit leave/expense
2. System routes request to manager inbox
3. Manager approves request
4. Employee chat receives execution confirmation
5. Repeat with rejection path

**Exit Criteria**
1. No sensitive action executes without required approval
2. Approval decision SLA is measurable from UI and logs
3. End-to-end audit trail complete for compliance review

---

## Stage 3 - Multi-Channel UX (Web + 1 External Channel)

**Goal**
Prove channel abstraction with a production-relevant messaging channel.

**UI You Can Use**
1. `Channel Settings` page (bind/unbind account, preferred channel)
2. Unified conversation viewer showing source channel tags
3. Channel delivery status indicators (`sent`, `delivered`, `failed`)

**Target Deliverables**
1. Channel adapter framework
2. First external adapter (recommend start with Telegram or WhatsApp)
3. Channel routing + retry policy + dead-letter queue for failures

**Manual Test Script**
1. Send message from external channel
2. Continue same conversation in web UI
3. Trigger approval flow and confirm both users notified in chosen channels
4. Simulate delivery failure and verify fallback/alert in UI

**Exit Criteria**
1. Conversation continuity across web + external channel
2. Delivery failures visible and recoverable
3. Per-user channel preference respected

---

## Stage 4 - Agent-to-Agent Workflow UI (Cross-Role Collaboration)

**Goal**
Enable visible orchestration between agents (employee/manager/finance/etc.).

**UI You Can Use**
1. `Workflow Trace` page showing multi-Agent hops as a timeline/graph
2. Node-level detail: sender Agent, recipient Agent, payload summary, status
3. Replay view for debugging failed orchestrations

**Target Deliverables**
1. Agent-to-Agent messaging contract
2. Queue-based orchestration for multi-step workflows
3. Correlation IDs across all agent/tool/approval events

**Manual Test Script**
1. Start a multi-department scenario (example: month-end close task)
2. Verify trace captures every Agent hop
3. Inject one failed hop and verify retry/error handling in trace

**Exit Criteria**
1. Full observability for cross-Agent workflows
2. No silent failures in orchestration path
3. Deterministic traceability with correlation IDs

---

## Stage 5 - Work History & Performance Console

**Goal**
Convert interaction data into operational insight and measurable business value.

**UI You Can Use**
1. `Agent Analytics` dashboard:
   - tasks completed
   - average response time
   - approval turnaround
   - failure rates by tool
2. `Work History` report UI (per user/team/date range)
3. Export actions for audit/performance review

**Target Deliverables**
1. Aggregation pipeline for interaction + action history
2. Report API endpoints for user/team/company scopes
3. Data retention and redaction rules

**Manual Test Script**
1. Run normal Agent usage for several days (or seed test data)
2. Generate period-based report
3. Validate report values against raw event logs

**Exit Criteria**
1. Management can request progress reports for any period
2. Metrics align with raw logs (verified sample checks)
3. Sensitive data redaction policy enforced

## 4. Component Responsibilities (Top-Level)

1. **Agent Runtime Module**
   - Contract: accept message + context, produce response + optional actions
   - Invariants: session isolation, deterministic logging, policy checks before execution

2. **Interaction Surfaces (Web + Channels)**
   - Contract: consistent user experience for sending/receiving/approving
   - Invariants: delivery status visible, no hidden state transitions

3. **Policy + Approval Module**
   - Contract: gate sensitive actions based on role/company policy
   - Invariants: deny by default for high-risk paths, all decisions auditable

4. **History + Analytics Module**
   - Contract: queryable activity history and performance insights
   - Invariants: trace completeness, privacy controls, time-range filtering

## 5. Module-Level Error Policy

1. Runtime errors: capture and show user-safe summaries in UI, keep technical details in logs
2. Tool errors: do not auto-retry non-idempotent writes; retry idempotent reads with capped attempts
3. Channel delivery failures: retry with backoff, then dead-letter and surface in UI
4. Approval timeouts: auto-expire with explicit status and notify requester

## 6. Complexity Hotspots to Address Early

1. Session identity across channels (same user, multiple entry points)
2. Approval race conditions (double approval/replay)
3. Tool idempotency for retries
4. Data model choice for message/action logs — **decided:** file-based (JSONL per session, OpenClaw pattern); see Stage 0 checklist §3.2
5. Access controls for multi-company and supervisor/subordinate Agent interactions
6. **Memory/recall architecture:** Transcript (messages table) vs semantic memory (MemSearch-style). Decision: PHP-native, markdown source of truth, SQLite per Agent for vectors. See `docs/architecture/ai/agent-model.md` §14.
7. **Per-agent LLM config resolution:** Provider credentials (company-level, encrypted DB) vs model selection (per-agent workspace). Config cascade must handle missing provider, inactive provider, and missing config.json gracefully. See `docs/architecture/ai/agent-model.md` §15.

## 7. Suggested Delivery Rhythm

1. One stage per milestone branch
2. Each stage includes:
   - UI walkthrough demo
   - manual test script execution
   - automated happy-path tests + failure-path tests
3. Do not start next stage until current stage exit criteria are met

## 8. Immediate Next Steps (Post Stage 0)

Stage 0 core is operational. The following features should be built next, in priority order:

### 8.1 Stage 0 Hardening (Before Stage 1)

1. **Automated Pest tests for Stage 0 feature paths** — Auth user access, session CRUD, message persistence, session isolation. Currently only unit tests for `AgentRuntime` exist.
2. **Streaming response support** — Replace synchronous LLM call with SSE/chunked streaming. Improves perceived latency for long responses. Requires `LlmClient::chatStream()` and Livewire wire:stream integration.
3. **Session export / clear** — Allow users to export session JSONL and clear old sessions. Workspace hygiene.

### 8.2 Stage 0→1 Bridge Features

4. **System prompt workspace files** — Load system prompt from workspace `IDENTITY.md` / `SOUL.md` instead of `job_description` field. Aligns with OpenClaw workspace pattern (§4.5, §13).
5. **Runtime cost tracking** — Track per-run token costs using the models.dev catalog cost data. Surface in debug panel and store in session metadata for future analytics (Stage 5).
6. **Retry with backoff for transient failures** — Currently fallback tries next model immediately. Add configurable delay between fallback attempts for rate-limit recovery.

### 8.3 Stage 1 Readiness

7. **Tool registry v1** — Define tool interface, register 3–5 read-only tools (employee lookup, company info, leave balance). Required for Stage 1 entry.
8. **Structured tool call logging** — Persist tool calls alongside messages in session JSONL for audit trail.
