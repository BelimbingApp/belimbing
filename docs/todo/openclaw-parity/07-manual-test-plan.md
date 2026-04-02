# Manual Test Plan — Phases 1–6 Browser Walkthrough

**Purpose:** Verify all user-visible features from Phases 1–6 in a live browser session.
**Prerequisite:** A running BLB instance with database migrated and seeded.
**Last Updated:** 2026-04-02

---

## 0. Environment Setup

Before testing, confirm the environment is ready:

```bash
php artisan migrate --seed
php artisan blb:ai:memory:index lara          # Build Lara's memory index
php artisan blb:ai:catalog:sync               # Sync the model catalog
npm run dev                                    # Vite dev server running
```

Log in as an admin user. All pages below are under the `auth` middleware.

---

## 1. Provider & Agent Activation (Foundation)

These steps are prerequisites for everything that follows.

### 1.1 Connect an AI Provider

| Step | Action | Expected |
|------|--------|----------|
| 1 | Navigate to **AI > AI Providers** (`/admin/ai/providers`) | Page loads with two sections: "Connected Providers" (empty initially) and "Provider Catalog" (list of all known providers) |
| 2 | Locate a provider in the catalog (e.g., GitHub Copilot, OpenAI, or Copilot Proxy) and click **Connect** | Redirects to the provider-specific setup wizard (`/admin/ai/providers/setup/{key}`) |
| 3 | Enter credentials (API key, base URL, etc. as required) and submit | Provider is saved; model discovery begins |
| 4 | After model sync, activate at least one model and mark one as **default** | Model toggles persist; a default badge appears |
| 5 | Return to the Providers page | The provider now appears under "Connected Providers" with model count |

### 1.2 Activate Lara

| Step | Action | Expected |
|------|--------|----------|
| 1 | Navigate to **AI > Lara** (`/admin/setup/lara`) | Lara setup page loads |
| 2 | If Lara is not provisioned, click **Provision Lara** | Lara's employee record is created; page refreshes to show activation controls |
| 3 | Select a connected provider and model from the dropdowns | Only providers with active models appear |
| 4 | Click **Activate Lara** | Success flash; Lara is now ready to chat. Workspace config is written |

### 1.3 Activate Kodi (optional)

| Step | Action | Expected |
|------|--------|----------|
| 1 | Navigate to **AI > Kodi** (`/admin/setup/kodi`) | Kodi setup page loads (available once Lara is activated) |
| 2 | Follow the same provision → select provider/model → activate flow | Kodi is activated |

---

## 2. Phase 1 — Workspace-Driven Runtime

Phase 1 replaced ad-hoc prompt builders with a structured workspace pipeline. Verification is indirect — the Playground chat must work and run metadata must include workspace diagnostics.

### 2.1 Basic Chat in the Playground

| Step | Action | Expected |
|------|--------|----------|
| 1 | Navigate to **AI > Agent Playground** (`/admin/ai/playground`) | Playground loads; Lara is auto-selected as the default agent |
| 2 | If no session exists, click **New Session** | A new session ID appears |
| 3 | Type a simple message (e.g., "Hello, what can you help me with?") and send | Lara responds coherently; no errors |
| 4 | Check run metadata displayed after the response | Should include `prompt_package` diagnostics: section count, total size, resolved file list — confirming the workspace pipeline processed the prompt |

### 2.2 Workspace Validation

| Step | Action | Expected |
|------|--------|----------|
| 1 | Send a second message in the same session | Response arrives; session state persists between turns |
| 2 | If workspace files were tampered with (e.g., `system_prompt.md` deleted), attempting a chat should produce a structured error | A clear error message appears, not a raw exception or silent degradation |

---

## 3. Phase 2 — Memory & Recall

### 3.1 Memory Status Tool

| Step | Action | Expected |
|------|--------|----------|
| 1 | Navigate to **AI > Tools** (`/admin/ai/tools`) | Tool Catalog page loads with all registered tools |
| 2 | Search for "memory_status" or scroll to find it | Tool appears with readiness status |
| 3 | Click into the **memory_status** tool workspace (`/admin/ai/tools/memory_status`) | Workspace page shows tool metadata, readiness, setup requirements, and "Try It" section |
| 4 | Run the "Try It" example ("Check status") | Returns a formatted memory status report: indexed (Yes/No), source count, chunk count, stale sources, last indexed time, embedding availability |

### 3.2 Memory Search via Chat

| Step | Action | Expected |
|------|--------|----------|
| 1 | In the Playground, ask Lara: "Search your memory for information about the project architecture" | Lara invokes the `memory_search` tool |
| 2 | Observe the response | Results include citations with source path, heading, snippet, and relevance score |

### 3.3 Memory Get via Chat

| Step | Action | Expected |
|------|--------|----------|
| 1 | Ask Lara: "Read the contents of your MEMORY.md file" | Lara invokes the `memory_get` tool |
| 2 | Observe the response | Returns file contents with scope info footer |

---

## 4. Phase 3 — Browser Operator

### 4.1 Browser Tool Readiness

| Step | Action | Expected |
|------|--------|----------|
| 1 | Navigate to **AI > Tools** and find the **browser** tool | Shows readiness status. If browser infra is not installed, shows UNCONFIGURED with setup requirements |
| 2 | Click into the browser tool workspace (`/admin/ai/tools/browser`) | Displays metadata, health checks, and setup instructions |

### 4.2 Browser Session Lifecycle via Chat

> **Prerequisite:** Browser infrastructure (Playwright/Node runner) must be available.

| Step | Action | Expected |
|------|--------|----------|
| 1 | In the Playground, ask Lara: "Open a browser and navigate to https://example.com" | Lara invokes the browser tool with a `navigate` action. A browser session is created (Opening → Ready → Busy → Ready) |
| 2 | Ask: "Take a snapshot of the current page" | Lara invokes browser tool with `snapshot` action. Returns page content with element refs |
| 3 | Ask: "Now navigate to https://example.org" | Same session is reused (no new session created). URL updates. Previous element refs are invalidated |
| 4 | Ask: "Take a screenshot" | Screenshot artifact is stored durably (disk + DB). Lara reports success |

### 4.3 Browser Status (CLI verification)

```bash
php artisan blb:ai:browser:status --company=1
```

Expected: Lists active browser sessions with session ID, owner, mode, current URL, last activity time.

---

## 5. Phase 4 — Messaging, Scheduling & Background Work

### 5.1 Outbound Messaging

| Step | Action | Expected |
|------|--------|----------|
| 1 | In the Playground, ask Lara: "Send an email to test@example.com with the subject 'Hello from BLB' and body 'This is a test message'" | Lara invokes the `message` tool via the Email channel |
| 2 | Observe the response | Lara reports the message was sent (or queued). Check Laravel's mail log or Mailpit if configured |

### 5.2 Schedule Task

| Step | Action | Expected |
|------|--------|----------|
| 1 | Ask Lara: "Schedule a daily task to check system health every day at 9am" | Lara invokes the `schedule_task` tool |
| 2 | Observe the response | A schedule definition is created. Lara reports the schedule ID |
| 3 | Verify via CLI: `php artisan blb:ai:operations:status {operation_id}` | Shows the schedule record with next run time |

### 5.3 Background Command

| Step | Action | Expected |
|------|--------|----------|
| 1 | Ask Lara to run an allowed Artisan command in the background (e.g., "Run the memory index command for me") | Lara invokes the `artisan` tool in background mode |
| 2 | Observe the response | A real dispatch record is created with a durable operation ID (not a synthetic ID) |

### 5.4 Delegation Status

| Step | Action | Expected |
|------|--------|----------|
| 1 | After performing any of the above operations, ask Lara: "What is the status of operation {id}?" | Lara invokes `delegation_status` |
| 2 | Observe the response | Shows operation type, status, timestamps, and result summary |

---

## 6. Phase 5 — Orchestration & Extension Kernel

### 6.1 Agent List with Structured Capabilities

| Step | Action | Expected |
|------|--------|----------|
| 1 | In the Playground, ask Lara: "List all available agents" | Lara invokes the `agent_list` tool |
| 2 | Observe the response | Each agent shows structured capability information: domains, task types, specialties — not just a raw job description |

### 6.2 Task Delegation

> **Prerequisite:** Kodi (or another agent) must be activated.

| Step | Action | Expected |
|------|--------|----------|
| 1 | Ask Lara: "Delegate a code review task to Kodi" (or use `/delegate`) | Lara invokes `delegate_task`; the `TaskRoutingService` routes based on structured capabilities |
| 2 | Observe the response | Delegation is dispatched. A child session is spawned with explicit parent lineage |
| 3 | Check status: ask "What is the status of the delegation?" | `delegation_status` returns the dispatch state |

### 6.3 Skill Packs (CLI verification)

```bash
php artisan blb:ai:skills:list
php artisan blb:ai:skills:verify knowledge
```

Expected: `KnowledgeSkillPack` appears as a registered skill. Verification reports `ready` status with prompt resources and tool bindings.

---

## 7. Phase 6 — Operator Control Plane

### 7.1 Control Plane Page Load

| Step | Action | Expected |
|------|--------|----------|
| 1 | Navigate to **AI > Control Plane** (`/admin/ai/control-plane`) | Page loads with three tabs: "Run Inspector", "Health & Presence", "Lifecycle Controls" |
| 2 | Verify sidebar | "Control Plane" menu item appears under the AI section |

### 7.2 Run Inspector Tab

> **Prerequisite:** At least one chat interaction must have been completed in the Playground (from earlier test steps).

| Step | Action | Expected |
|------|--------|----------|
| 1 | Tab defaults to "Run Inspector" | Input fields for Employee ID, Run ID, and Session ID are visible |
| 2 | Enter an Employee ID (Lara = 1) and a valid Session ID from an earlier chat, then click **Inspect Session** | A list of runs for that session appears, each showing: provider, model, timing, tool actions, retry/fallback history, outcome status |
| 3 | Copy a Run ID from the results, enter it with the Employee ID, and click **Inspect Run** | A single run detail view appears with the full normalized run inspection |
| 4 | Enter an invalid Run ID and click Inspect Run | An error message: "Run not found for the given employee and run ID" |

### 7.3 Health & Presence Tab

| Step | Action | Expected |
|------|--------|----------|
| 1 | Click the **Health & Presence** tab | Tab content loads |
| 2 | Click **Load Tool Snapshots** (or equivalent trigger) | A table of all tools appears, each with three distinct indicators: **readiness** (configured/unconfigured/etc.), **health** (healthy/degraded/failing/unknown), and **presence** (active/idle/offline) — plus an explanation string |
| 3 | Enter an Agent ID (1 for Lara) and load agent snapshot | Shows Lara's readiness, health (based on recent run success/failure ratio), and presence (active/idle/offline based on session recency) |

### 7.4 Lifecycle Controls Tab

| Step | Action | Expected |
|------|--------|----------|
| 1 | Click the **Lifecycle Controls** tab | Shows a form with lifecycle action selector, scope inputs, and recent request history |
| 2 | Select **"Prune Sessions"** from the action dropdown. Enter an Employee ID and retention days (e.g., 30). Click **Preview** | A preview card appears showing: affected count, summary description, and whether the action is destructive |
| 3 | Without executing, select **"Sweep Browser Sessions"** and click **Preview** | Preview updates for the new action scope |
| 4 | Select **"Compact Memory"**, enter an Employee ID, click **Preview**, then click **Execute** | The action runs. A result card appears with outcome summary. The "Recent Requests" list updates with the new entry showing action, status, and timestamp |
| 5 | Repeat with **"Sweep Operations"** (set stale minutes, e.g., 60) | Preview shows stale dispatch count; execution sweeps them. Audit trail updates |

---

## 8. Tool Catalog Cross-Check

This section verifies the Tool Catalog page reflects all phases' contributions.

| Step | Action | Expected |
|------|--------|----------|
| 1 | Navigate to **AI > Tools** (`/admin/ai/tools`) | Full catalog loads |
| 2 | Count total tools | Should show **24 tools** (22 always-available + 2 conditional) |
| 3 | Search for "memory" | Finds: `memory_get`, `memory_search`, `memory_status` (Phase 2) |
| 4 | Search for "browser" | Finds: `browser` (Phase 3) |
| 5 | Search for "schedule" | Finds: `schedule_task` (Phase 4) |
| 6 | Search for "message" | Finds: `message` (Phase 4) |
| 7 | Search for "delegate" | Finds: `delegate_task`, `delegation_status` (Phase 5) |
| 8 | Filter by category | Each category filter narrows results correctly; sort toggles work on name, category, risk |
| 9 | Click into any tool workspace | Workspace page shows: display name, summary, explanation, readiness, setup requirements, health checks, limits, test examples |

---

## 9. Artisan Commands Spot Check

Run from the terminal to verify CLI commands from all phases are registered:

```bash
# Phase 2 — Memory
php artisan blb:ai:memory:index lara --force
php artisan blb:ai:memory:compact lara

# Phase 3 — Browser
php artisan blb:ai:browser:sweep
php artisan blb:ai:browser:status --company=1

# Phase 4 — Messaging & Scheduling
php artisan blb:ai:operations:sweep
php artisan blb:ai:schedules:tick

# Phase 5 — Orchestration
php artisan blb:ai:skills:list
php artisan blb:ai:skills:verify knowledge

# Phase 6 — Control Plane
php artisan blb:ai:health:snapshot
php artisan blb:ai:lifecycle:preview
```

Each command should execute without errors. Commands that query data return structured output or "no results" messages.

---

## Quick Reference: All Browser Routes

| URL | Menu Location | Phase |
|-----|---------------|-------|
| `/admin/setup/lara` | AI > Lara | Pre-req |
| `/admin/setup/kodi` | AI > Kodi | Pre-req |
| `/admin/ai/providers` | AI > AI Providers | Pre-req |
| `/admin/ai/playground` | AI > Agent Playground | 1–5 |
| `/admin/ai/tools` | AI > Tools | 2–4 |
| `/admin/ai/tools/{toolName}` | (via catalog click) | 2–4 |
| `/admin/ai/control-plane` | AI > Control Plane | 6 |
