# Lara Concurrent Runs

**Status:** In Progress
**Last Updated:** 2026-04-14
**Sources:** `run_qLd8OtMmZbtY`, `docs/todo/ai/ai-chat-coding-agent-console.md`, `docs/todo/ai/lara-realtime-console.md`, `resources/core/views/livewire/ai/chat.blade.php`, `app/Modules/Core/AI/Livewire/Chat.php`, `app/Modules/Core/AI/Livewire/Concerns/HandlesStreaming.php`

## Problem Essence

Lara's backend can currently create multiple active chat turns, but the UI only tracks one live turn at a time. Starting another turn resets the active stream state, so earlier live work becomes invisible, hard to stop, and easy to orphan.

## Desired Outcome

Lara should support concurrent work without losing observability or control. A user can run Lara in multiple sessions at once, see every active turn, stop the exact turn they intend, and reload the page without losing track of live work.

## Public Contract

- The user-visible unit remains the **chat turn**. `AiRun` stays operator telemetry; the UI manages concurrent **turns**.
- BLB supports **multiple active turns across different sessions** for the same user.
- BLB does **not** support multiple active turns in the **same** session. If a session already has an active turn, submitting another message in that session is rejected with a clear resume-focused response.
- The selected session shows the full live activity rail for its active turn.
- Other active turns are primarily surfaced in the existing session panel with active-state metadata and direct actions. A compact global indicator is only a fallback when the panel is collapsed or unavailable on narrow screens.
- Phase labels and elapsed timers are derived from durable turn state (`current_phase`, `current_label`, `created_at`, `started_at`, and persisted turn events), not from when a browser tab first noticed the turn.
- `Stop` always targets a specific `turn_id`, never an implicit global "current turn".
- Page reload resumes all active turns for the current user. The selected session gets the detailed rail; other sessions resume as compact active cards.
- A turn reaching `completed`, `failed`, or `cancelled` only clears that turn's live state. No other active turn is reset as collateral damage.

## Top-Level Components

**Server-side active-turn index** — Livewire `Chat` should expose the current user's active turns as a list keyed by `turn_id`, including `session_id`, status, phase, label, and start time. The current single `activeTurnId` render contract becomes a selected-session convenience, not the whole truth.

**Session concurrency policy** — `prepareStreamingRun()` must enforce one active turn per session. This keeps transcript ordering coherent and prevents same-session assistant replies from racing each other into one conversation lane.

**Client turn registry** — Alpine chat state should replace the singleton `activeTurnId` and single fetch/poller with a registry keyed by `turn_id`. Each entry owns its own replay cursor, timing state, transport handle, and terminal cleanup.

**Selected-session live rail** — The existing detailed console remains, but it binds to the selected session's active turn entry. Switching sessions swaps which turn is rendered in full detail without discarding other active entries.

**Session-panel active turn list** — The existing session panel should become the primary concurrency surface. Each session row can show whether it has an active turn, plus phase, elapsed time, and direct session navigation. This keeps concurrency aligned with the session model instead of introducing a second primary navigation area.

**Collapsed/mobile fallback indicator** — When the session panel is hidden, a compact active-turn indicator can summarize how many other sessions are busy and reopen the panel. This is support UI, not the main interaction model.

**Lifecycle repair guardrails** — Stale-turn and orphan-run repair must remain aligned with concurrent UI state. A stalled turn must disappear from the session-panel active state (and collapsed/mobile fallback indicator) only after its durable terminal event lands.

## Design Decisions

**Support concurrency across sessions, not within a session.** A chat session is still a single conversation lane. Allowing two active turns in one session would make transcript ordering nondeterministic, confuse title/search/session usage semantics, and require a lane model the product does not have. If the user wants parallel branches, the right primitive is a new session.

**Promote active-turn state to a first-class UI model.** The current frontend assumes one live turn: one `activeTurnId`, one `streamEntries`, one fetch, one timer. That model must be replaced rather than patched. Concurrent runs are not a small conditional; they require explicit per-turn state ownership.

**Use the session panel as the primary concurrent-turn navigator.** Active work is already session-scoped, and the product already gives the user a session list. Putting concurrency in that panel keeps the mental model intact: "which conversation is busy?" rather than "which abstract turn is busy?" A second global tray should not become a competing primary surface.

**Separate detailed rendering from global tracking.** Trying to render every concurrent turn as a full console inside the main transcript would produce noise and accidental context switching. The selected session should keep the rich rail; the rest should be summarized in the session panel until the user opens them.

**Keep turn events as the sole live source of truth.** The concurrent UI should not invent a second live state channel. Every card, timer, and stop action should derive from `ai_chat_turns` plus `ai_chat_turn_events`, preserving the existing replay and resume contract.

**Timers and phase labels must be replay-stable.** If elapsed time or phase is anchored to "when this browser attached," reloads and multiple tabs will drift immediately. The UI should compute timers from `started_at` with `created_at` as fallback, and should render labels from durable phase/label fields plus terminal turn events. Reattachment is a replay of existing truth, not a new local lifecycle.

**Return structured session-busy responses.** When the user submits into a session that already has an active turn, the server should not silently queue or overwrite. It should return a structured payload that points the UI back to the existing active turn with a resume URL and session label.

**Completion must be per-turn, not global.** The current `agent-chat-response-ready` event resets all live state. That is incompatible with concurrency. Terminal handling must clear only the completed turn entry and refresh only the affected session transcript.

**Schedule stale-turn sweeping, not just orphan-run reaping.** Concurrent usage raises the chance that abandoned or wedged turns remain visible. The stale-turn sweep command must be part of normal scheduling so the UI converges back to reality without manual cleanup.

## Phases

### Phase 1 — Freeze the concurrency contract

Goal: define the supported concurrency model and make the backend reject unsupported cases explicitly.

- [x] Add a plan-backed policy note to the Lara chat contract: many active sessions per user, one active turn per session.
- [x] Update `prepareStreamingRun()` to detect an existing non-terminal turn for the current user and selected session before creating a new turn.
- [x] Return a structured "session already busy" payload that includes the existing `turn_id`, `session_id`, phase, label, and resume route instead of creating a duplicate turn.
- [x] Add backend tests for duplicate submit in the same session, including ownership boundaries.
- [x] Audit message/title/session-usage assumptions and document that they remain session-sequential by design.

### Phase 2 — Replace the singleton live-turn frontend model

Goal: make the client capable of tracking multiple active turns safely.

- [ ] Replace singleton Alpine fields (`activeTurnId`, `streamEntries`, `_lastSeq`, `_abortController`, single timer) with a per-turn registry keyed by `turn_id`.
- [ ] Split shared UI state into:
  - selected session detailed turn state
  - global active-turn summaries for all other sessions
- [ ] Make stop actions target an explicit `turn_id` from the registry, not a global current turn.
- [ ] Remove global reset behavior that clears every live stream when one turn finishes.
- [ ] Keep transcript rendering scoped to the selected session only; non-selected sessions stay compact.

### Phase 3 — Add active-turn navigation and recovery UX

Goal: make concurrent work understandable and resumable for humans.

- [x] Extend the session panel so each session row can show active-turn state: phase label, elapsed time, and a clear busy indicator.
- [x] Add row-level actions for active sessions: open session and stop turn.
- [x] Add a compact fallback indicator for collapsed/mobile states that reopens the session panel and summarizes busy-session count.
- [x] On page load, fetch all active turns for the current user, not only the latest turn in the selected session.
- [x] Resume the selected session's active turn into the detailed rail.
- [x] Resume non-selected active turns into session-panel summaries with phase and timer updates driven by replay/polling.
- [x] When the user switches sessions, bind the detailed rail to that session's active turn if present; otherwise show the persisted transcript only.
- [x] When a session-busy submit is rejected, shift focus back to the existing live turn instead of failing silently.

### Phase 4 — Align terminal-state cleanup and operational repair

Goal: ensure concurrency does not leave stranded UI or server state behind.

- [x] Make terminal handling remove only the affected turn entry from the client registry.
- [x] Refresh only the transcript/session usage for the session whose turn just terminated.
- [x] Schedule `blb:ai:turns:sweep-stale` alongside orphan-run reaping in the app scheduler.
- [x] Ensure stale-turn sweeping converges through the same terminal event path the UI already reads. The sweep must emit the durable terminal event itself, or write the terminal state into the same turn/event store the client consumes, so there is no separate "sweep-only" UI pathway.
- [x] Verify stale-turn and forced-cancel paths emit the same per-turn terminal signals the concurrent UI expects.
- [ ] Add a repair rule for abandoned selected-session state so switching away from a wedged session does not strand the UI.

### Phase 5 — Verification

Goal: prove the supported concurrency model behaves coherently.

- [x] Feature test: two sessions owned by the same user can run active turns concurrently and both remain discoverable.
- [x] Feature test: same-session second submit is rejected and points to the existing active turn.
- [x] Feature test: stopping one active turn leaves another active turn untouched.
- [x] Feature test: terminal completion of one turn does not clear another session row's active state or timer.
- [ ] Manual verification: start turns in two sessions, switch between them, reload the page, and confirm both turns remain observable and controllable.
- [ ] Manual verification: trigger stale-turn cleanup and confirm session-panel active state converges to the durable terminal state.
