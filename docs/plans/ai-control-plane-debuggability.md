# Control Plane Debuggability

**Agent:** Amp  
**Status:** Identified  
**Last Updated:** 2025-04-19  
**Sources:** `app/Modules/Core/AI/Livewire/ControlPlane.php`, `app/Modules/Core/AI/Services/AgenticRuntime.php`, `app/Base/AI/Services/LlmClient.php`, `app/Modules/Core/AI/Services/TurnStreamBridge.php`, `app/Modules/Core/AI/Services/ControlPlane/RunRecorder.php`

## Problem Essence

The control plane exists but is unusable for debugging real operator problems — stuck turns, silent failures, missing live-feed, and unresponsive Stop buttons. It requires knowing opaque IDs upfront, shows no raw API traffic, and several tabs are buggy or vacant. When Lara misbehaves, an operator currently has no systematic path from "something is wrong" to "here's what happened at the wire level." Even when a run is inspected, the user's prompt that triggered it is invisible — the transcript filter excludes it because the message predates the run ID assignment.

## Desired Outcome

An operator can open the control plane, immediately see recent activity across all agents, drill into any stuck or failed turn, and trace the full lifecycle from user message → turn creation → LLM request → raw API response → tool execution → final output. Raw, unfiltered API traffic is captured and viewable so unknown/new response keys are never silently dropped. The page works without memorizing IDs, defaults to Lara, and every tab renders correctly.

## Top-Level Components

1. **Recent Activity Dashboard** — replaces the blank Run Inspector landing with an auto-loaded table of recent turns/runs, clickable to drill down. Eliminates "I need to know the ID" problem.

2. **Raw API Traffic Capture** — new opt-in wire logging layer in `LlmClient` that persists full request payloads and raw response bodies (or raw SSE lines) so operators can see exactly what was sent and what came back, including unknown keys that the normalized event pipeline currently discards.

3. **Turn Timeline View** — a chronological, event-by-event timeline for a turn showing every phase transition with timestamps and durations. Makes it immediately visible where a turn got stuck (e.g., "stuck in `running` for 47 minutes, last event was `tool_started` for `browser_navigate`").

4. **Agent Selector** — every Employee ID input becomes a dropdown populated from `Employee::where('employee_type', 'agent')`, defaulting to Lara (`Employee::LARA_ID`). Applies across Run Inspector, Health tab, Lifecycle tab.

5. **Health Tab Fix + Auto-load** — fix the rendering bug (Health tab currently shows Lifecycle content), auto-load turn queue health and tool snapshots on tab activation, add provider health (service already supports it, UI never calls it).

## Design Decisions

### Triggering prompt: include the preceding user message

The user message that triggers a run is appended to the transcript *before* the run ID is assigned, so `loadRunTranscript()`'s `runId` filter excludes it. The fix: when loading a run's transcript, also fetch the last user-role message in the session whose timestamp precedes the run's `started_at`. Display it as "Triggering Prompt" above the run's activity transcript.

### Macro view: separate surface, separate plan

This plan covers the *diagnostic drill-down* (micro). The macro operational dashboard is scoped separately in `docs/plans/ai-operations-dashboard.md`. The recent-activity table in Phase 1 here is a navigation aid into drill-down, not the macro view.

(File renamed from `control-plane-debuggability.md` → `ai-control-plane-debuggability.md` per module prefix convention.)

### Raw traffic capture: file-based, not database

Raw API payloads can be large (full conversation context, tool definitions, streaming response bodies). Storing them in the database would bloat `ai_runs` and make queries slow. Instead, write per-run wire log files to `storage/app/workspace/{employee_id}/wire-logs/{run_id}.jsonl` — one JSONL file per run containing timestamped request/response entries. The control plane reads these on demand when drilling into a run. A lifecycle action (`PruneWireLogs`) handles cleanup by age.

The wire log captures:
- **Outbound**: full `ChatRequest` DTO as JSON (messages, tools, system prompt, model, provider, execution controls) — with API keys redacted
- **Inbound (sync)**: the complete parsed response array before normalization
- **Inbound (streaming)**: raw SSE lines as received, before protocol-specific decoding strips unknown keys
- **Timing**: timestamps at request-start, first-byte, and completion

This is opt-in via a config toggle (`ai.wire_logging.enabled`, default `true` in non-production, `false` in production) so it can be disabled when storage is a concern.

### Recent activity: query-driven, not new storage

The `ai_chat_turn_events` and `ai_runs` tables already contain everything needed. The dashboard is a paginated query joining `chat_turns` → `ai_runs` with filters (agent, status, time range). No new tables.

### Turn timeline: rendered from existing turn events

`ai_chat_turn_events` already stores every phase change, tool start/finish, thinking delta, output delta, and terminal event with timestamps. The timeline view is a presentation layer over this existing data — a vertical timeline with computed durations between events and visual indicators for phases. No new persistence needed.

### Agent selector: shared Livewire concern

A small trait or inline query (`Employee::where('employee_type', 'agent')`) used by the ControlPlane component. The list is tiny (one agent today) so a simple `x-ui.select` with `wire:model.live` is sufficient. No combobox needed.

## Phases

### Phase 1 — Fix what's broken, make it navigable

Goal: The control plane renders correctly and is usable without knowing IDs in advance.

- [ ] Fix Health & Presence tab rendering bug (likely tab `id` mismatch or duplicate panel content)
- [ ] Replace all Employee ID free-text inputs with agent dropdown (`x-ui.select`), default to Lara
- [ ] Add recent activity table to Run Inspector: auto-load last 20 turns on mount, show status/agent/session/timing/outcome, each row clickable to populate run detail (navigation aid, not the macro view — see Design Decisions)
- [ ] Show triggering prompt: when displaying a run, fetch the preceding user message from the transcript and display it as "Triggering Prompt" above the activity transcript
- [ ] Auto-load turn queue health + tool snapshots when Health tab activates (instead of requiring manual Refresh)
- [ ] Add provider health cards to Health tab (call existing `providerSnapshot()`)
- [ ] Add inline descriptions to each Lifecycle action explaining when/why to use it

### Phase 2 — Turn timeline and diagnostic drill-down

Goal: An operator can trace the full lifecycle of any stuck or failed turn without reading raw event logs.

- [ ] Build turn timeline component: vertical timeline rendering all `ai_chat_turn_events` for a turn, showing event type, payload summary, timestamp, and computed duration since previous event
- [ ] Highlight anomalies: events with > 30s gap get a warning indicator, stuck phases get a danger indicator
- [ ] Link turns ↔ runs bidirectionally: from turn timeline, click run ID to see run detail; from run detail, link back to the turn timeline
- [ ] Add "Recent Turns" quick-nav to Turn Inspector tab (same pattern as Phase 1's recent activity)
- [ ] Surface stop/cancel diagnostics: show `cancel_requested_at` vs actual terminal event timestamp, and whether the turn was force-stopped or cooperatively cancelled

### Phase 3 — Raw API wire logging

Goal: Full unfiltered request/response data is captured per run and viewable in the control plane.

- [ ] Add `ai.wire_logging.enabled` config toggle (default `true` for dev, `false` for production)
- [ ] Create `WireLogger` service: writes per-run JSONL files to `storage/app/workspace/{employee_id}/wire-logs/{run_id}.jsonl`
- [ ] Instrument `LlmClient::chat()` — log the mapped request payload (API key redacted) and full response body before normalization
- [ ] Instrument `LlmClient::chatStream*()` — log the mapped request payload, then append each raw SSE line as received (before protocol-specific parsing)
- [ ] Instrument `AgenticRuntime` — log tool call arguments (full, not truncated) and tool result bodies (full, not just preview + length)
- [ ] Add "Wire Log" tab/section to the run detail view in the control plane: renders the JSONL entries with syntax-highlighted JSON, collapsible sections for large payloads
- [ ] Add `PruneWireLogs` lifecycle action: delete wire log files older than N days (default 7)

### Phase 4 — Cross-surface navigation

Goal: The control plane is the drill-down destination from other surfaces, not a standalone island. The deep-link contract here is a dependency for the operations dashboard (`docs/plans/ai-operations-dashboard.md`), which needs to link aggregates (failure spikes, degraded providers, tool errors) directly into the right control plane tab with context pre-filled.

- [ ] Deep-link support: `/admin/ai/control-plane?tab=turns&turnId=xxx` auto-opens the Turn Inspector and loads that turn; similarly for `?tab=inspector&runId=xxx` and `?tab=health`
- [ ] Make run IDs in the chat activity stream clickable links to the control plane run detail
- [ ] Make turn IDs in session views clickable links to the turn timeline
- [ ] Add "Open in Control Plane" action to the chat console for the current/last turn (visible to admins)
- [ ] Add breadcrumb back to AI > Operations when arriving via drill-down from the operations dashboard
