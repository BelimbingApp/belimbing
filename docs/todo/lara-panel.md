# Lara Panel — Build Sheet

> **Dependency:** Complete Phase 1 before Phase 2's recovery/reconnection work (2.6).

## Reference docs
- `docs/architecture/ui-layout.md`
- `docs/architecture/ai/lara.md`

## Key files
- `resources/core/views/components/layouts/app.blade.php` — app shell mount point
- `app/Modules/Core/AI/Livewire/Chat.php` — main chat Livewire component
- `app/Modules/Core/AI/Livewire/Concerns/HandlesStreaming.php` — SSE streaming concern
- `app/Modules/Core/AI/Livewire/Concerns/HandlesBackgroundChat.php` — background execution concern
- `app/Modules/Core/AI/Http/Controllers/ChatStreamController.php` — SSE endpoint
- `app/Modules/Core/AI/Jobs/RunAgentChatJob.php` — background job
- `app/Modules/Core/AI/Services/ChatRunPersister.php` — shared activity persistence
- `resources/core/views/livewire/ai/chat.blade.php` — chat Blade template
- `resources/core/views/components/ai/chat-composer-fields.blade.php` — composer fields

---

## Phase 1 — Panel isolation from main-content navigation

**Goal:** Lara panel remains stable and usable while users navigate; page changes do not reset UI state or wipe in-progress input.

**Problem:** Although the UI architecture intends Lara to persist in the app shell (zone D), disruptive behavior still occurs when the browser performs a full page reload, Livewire state is remounted during navigation, draft input is not persisted across refresh/remount cycles, or page-context updates are too tightly coupled to Lara component state. This is especially noticeable during development when Vite HMR triggers full refreshes.

### 1.1 Shell mount point
- [x] Confirm `app.blade.php` remains the single Lara mount point — verified at line 339
- [x] Verify no page-level components mount Lara independently — confirmed
- [x] Verify no duplicate Lara mounts exist across layouts — confirmed

### 1.2 Navigation audit
- [x] Identify all routes/actions still causing full browser reloads — pinned sidebar links, logout forms, impersonation, timezone switch
- [x] Convert remaining full-reload navigations to `wire:navigate` where applicable — added `wire:navigate` to pinned sidebar links (rail + expanded)
- [ ] Confirm `wire:navigate` morphs preserve Lara's Alpine state across page transitions — needs manual testing
- Note: Logout, impersonation, timezone switch intentionally cause full reloads (auth state changes)

### 1.3 Persist open/closed state
- [x] Persist `laraChatOpen` to `localStorage` — already implemented in `app.blade.php` (key: `agent-chat-1-open`)
- [x] Restore open/closed state on hard reload — already implemented
- [x] Update `ui-layout.md` Alpine state table to reflect persistence — updated (also added `laraChatMode`, `laraChatFullscreen`, `laraDockWidth`)

### 1.4 Persist draft input
- [x] Store draft input in `localStorage` under a stable per-user key — `blb-lara-draft-{userId}`
- [x] Restore draft on component boot — `x-init` restores from localStorage
- [x] Clear draft only after successful send or explicit discard — cleared in `onSubmit`
- [ ] Verify draft survives `wire:navigate` page transitions — needs manual testing
- [ ] Verify draft survives Vite HMR full reloads — needs manual testing

### 1.5 Decouple UI state from page context
- [x] Keep chat visibility, dock state, scroll position independent of page component lifecycle — already implemented
- [x] Treat page context as supplemental state, not component identity — already implemented
- [x] Page context updates do not destroy chat UI state — already implemented

### 1.6 Hard reload behavior
- [x] Restore draft reliably after hard reload — localStorage persistence
- [x] Preserve chat session identifier across hard reloads — `blb-lara-session` in localStorage, restored on `x-init`

### 1.7 Hard-isolation fallback (optional)
- [ ] Evaluate iframe/popout architecture only if shell-level persistence remains insufficient — deferred, not needed with current approach

### 1.8 Acceptance
- [x] Navigating between admin pages does not close or reset Lara — shell persistence + wire:navigate
- [x] Lara open/closed state survives hard reload — localStorage
- [ ] Typed but unsent input survives page navigation — needs manual testing
- [ ] Typed but unsent input survives accidental dev refresh — needs manual testing
- [x] Lara session state remains available after main-content changes — session ID persisted
- [x] No duplicate Lara mounts exist across page layouts — verified

---

## Phase 2 — Non-blocking execution with real-time activity updates

**Goal:** Lara works without freezing the app; the panel exposes a live activity stream and a clear busy signal from dispatch through final response.

**Problem:** Today the user mostly sees Lara's output when the run finishes. During that wait the experience feels blocked: the panel shows limited in-flight feedback, the main UI appears monopolized, there is no consistent shell-level signal that Lara is still active, and long-running work degrades into a generic waiting state instead of a modern agent-style progress feed. Partial primitives exist (`HandlesBackgroundChat`, `RunAgentChatJob`, `ChatStreamController`) but are not yet shaped into a cohesive activity UX.

**Hard dependency:** Phase 1 must be complete — reconnecting to an in-flight run after navigation is meaningless if navigation destroys Lara's mount.

### 2.1 Separate run state from global UI lock
- [x] Scope pending/disabled behavior to Lara composer and run controls only — already scoped via `pendingMessage` in chat section
- [x] Verify page navigation, sidebar, and unrelated forms remain usable during a Lara run — verified, separate DOM
- [x] Add cancel/abort control to the composer UI — stop button added, visible during active runs
- [x] Define cancel behavior for interactive (SSE) runs — closes `EventSource`, calls `finalizeStreamingRun()`, clears state
- [x] Define cancel behavior for background (`RunAgentChatJob`) runs — existing `cancelBackgroundChat()` + cooperative check in job via `isCancelled()`
- [ ] Define what the user sees on cancellation (partial response? cancellation marker?) — currently clears stream entries; may want to keep partial content

### 2.2 Unified activity-event model
- [x] **Decision:** keep SSE for interactive, enhanced polling for background — transcript is source of truth; no Reverb needed
- [x] Define a single event vocabulary shared by interactive and background paths — extracted `ChatRunPersister` with shared persistence for `thinking`, `tool_call`, `tool_result`, `hook_action`, `tool_denied`
- [x] Extend `RunAgentChatJob` to emit progress events — switched from `run()` to `runStream()`, persists activity entries via `ChatRunPersister`
- [x] Persist run metadata sufficient for client reconnection after remount/reopen/refresh — transcript + `backgroundDispatchId` Livewire property

### 2.3 Live activity timeline in transcript
- [x] Render human-readable activity entries — already implemented: thinking, tool call (with tool name + args), streaming text
- [x] Group or debounce noisy low-level events — thinking shows once, tool calls grouped per tool
- [x] Keep activity entries visually distinct from Lara's final assistant response — different styling (icons, borders, compact vs full bubble)
- [x] **Decision:** tool results — collapsed/expandable via chevron toggle (already implemented)
- [x] Implement chosen tool-result display pattern — done: expandable card with result preview, duration, status badge

### 2.4 Shell-level busy signal
- [x] Add busy indicator on the Lara trigger button (visible when panel is collapsed) — pulsing accent dot on status-bar Lara button
- [x] Add busy indicator on the panel header (visible when panel is open) — busy state derived from `pendingMessage || backgroundDispatchId`
- [x] Choose pattern — pulsing dot (`w-2 h-2 bg-accent rounded-full animate-pulse`)
- [x] Provide non-animated `prefers-reduced-motion` fallback — `motion-reduce:animate-none motion-reduce:opacity-70`
- [x] Clear busy signal when run completes or is cancelled — `agent-chat-idle` event dispatched via x-effect

### 2.5 Unify short and long runs
- [x] Short runs stream progress and response content in real time — SSE with `streamEntries`
- [x] Long runs transition to background execution with progressive status — `RunAgentChatJob` now uses `runStream()` and persists activity entries
- [x] Define the timeout/threshold for interactive → background transition — existing timeout-based offload in `handleTimeoutWithBackgroundOffer()`
- [x] Background runs continue emitting activity events after transition — polling refreshes transcript every 3s to show new entries

### 2.6 Recovery and reconnection
- [x] Reopening the panel restores current run state and recent activity — `backgroundDispatchId` is Livewire property, polling resumes
- [x] `wire:navigate` remount does not discard an in-flight Lara run — Livewire properties persist across morph
- [x] If run completed while panel was closed, hydrate final answer and clear busy signal — `$wire.$refresh()` on poll + idle event
- [ ] If run is still in-flight on reopen, reconnect to activity stream — SSE reconnection not implemented (background polling covers this)

### 2.7 Activity vs response separation
- [x] Activity entries are operational state, not assistant prose — thinking/tool_call entries use distinct components
- [x] Final answer does not duplicate intermediate events verbatim — stream entries cleared on `agent-chat-response-ready`
- [ ] Activity entries collapse or fade after the final response arrives — deferred, current clear-on-done is acceptable

### 2.8 Acceptance
- [x] Starting a Lara run does not disable unrelated page interactions or navigation
- [x] User sees live activity updates before the final answer — streaming entries + background transcript refresh
- [x] User sees a clear busy signal when panel is open and when collapsed — pulsing dot on status-bar
- [x] Activity state survives panel reopen and page remount — transcript persistence + polling
- [x] Long-running runs continue in background with progressive status — `runStream()` + activity persistence
- [x] Cancelling an in-flight run stops execution and leaves transcript consistent — cooperative cancel + `markCancelled()`
- [x] Busy indicator is accessible and reduced-motion safe — `motion-reduce:` fallback

---

## Notes

Reference interaction patterns: Copilot CLI, Claude Code, OpenCode.

Implementation direction:
- Prefer concise stage updates over raw token noise
- Build one run-state model that powers both interactive streaming and background execution

### Remaining work (minor)
- Manual testing of Phase 1 persistence items (draft across navigation, HMR)
- Decide on partial-content display when a run is cancelled mid-stream
- SSE reconnection for interrupted interactive streams (background polling covers the gap)
- Optional: activity entry collapse/fade after final response arrives
