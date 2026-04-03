# TODO: 
1. Lara panel isolation from main-content navigation
2. Non-blocking Lara execution with real-time activity updates

## 1. Lara panel isolation from main-content navigation

### Goal
Ensure the Lara panel remains stable and usable while users navigate the main application, so page changes do not reset Lara UI state or wipe in-progress input.

### Problem
Although BLB's UI architecture intends the Lara panel to persist outside the main content area, disruptive behavior can still occur when:

- the browser performs a full page reload
- Livewire state is remounted during navigation
- draft input is not persisted across refresh/remount cycles
- page-context updates are too tightly coupled to Lara component state

This is especially noticeable during development when Vite-triggered refreshes occur.

### Desired behavior
- Lara remains mounted independently from page-specific content.
- Main-content navigation should not reset Lara open/closed state.
- Draft input in Lara should survive navigation and accidental refreshes.
- Lara conversation/session state should remain isolated from page component lifecycle.
- Page context may update, but should not destroy chat UI state.

### Proposed implementation
1. Keep Lara mounted once in the persistent app shell.
   - Confirm `resources/core/views/components/layouts/app.blade.php` remains the single mount point.
   - Avoid mounting Lara from page-level components.

2. Audit navigation paths.
   - Prefer SPA-style navigation where applicable.
   - Identify routes/actions still causing full browser reloads.

3. Persist Lara draft state.
   - Store draft input in `localStorage` under a stable per-user/session key.
   - Restore draft on component boot.
   - Clear draft only after successful send or explicit discard.

4. Decouple Lara UI state from page context updates.
   - Keep chat visibility, dock state, scroll position, and draft text independent.
   - Treat page context as supplemental state, not component identity.

5. Define behavior for hard reloads.
   - Restore draft reliably.
   - Preserve chat session identifier when possible.

6. Optional hard-isolation fallback.
   - Evaluate iframe/popout architecture only if shell-level persistence remains insufficient.

### Suggested files to inspect
- `resources/core/views/components/layouts/app.blade.php`
- `app/Modules/Core/AI/Livewire/Chat.php`
- `resources/core/views/livewire/ai/chat.blade.php`
- any Lara Alpine/local-storage helpers
- navigation entry points using Livewire/Blade links

### Acceptance criteria
- Navigating between admin pages does not close or reset Lara.
- Typed but unsent Lara input survives page navigation.
- Typed but unsent Lara input survives accidental dev refresh.
- Lara session state remains available after main-content changes.
- No duplicate Lara mounts exist across page layouts.

### Notes
Reference architecture:
- `docs/architecture/ui-layout.md`
- `docs/architecture/ai/lara.md`

## 2. Non-blocking Lara execution with real-time activity updates

### Goal
Ensure Lara can continue working without freezing the rest of the application, while the panel exposes a live, user-readable activity stream and a clear busy signal from dispatch through final response.

### Problem
Today the user mostly sees Lara's output when the run finishes. During that wait, the experience feels blocked:

- the panel shows limited in-flight feedback
- the main UI should remain usable, but the current experience suggests Lara work is monopolizing the surface
- there is no consistent shell-level signal showing that Lara is still active
- long-running work degrades into a generic waiting state instead of a modern agent-style progress feed

The codebase already contains partial primitives for this (`HandlesBackgroundChat`, `RunAgentChatJob`, `ChatStreamController`), but they are not yet shaped into a cohesive Lara activity UX.

### Desired behavior
- Sending a message to Lara does not block unrelated page interactions or main-content navigation.
- The Lara composer may enter a pending state, but the rest of the app remains usable.
- Lara shows live progress updates while preparing context, choosing tools, running actions, waiting on results, and composing the final answer.
- A compact busy signal is visible even when the panel is collapsed.
- Long-running runs can continue in background without losing visibility into what Lara is doing.
- Motion and status affordances remain accessible, including reduced-motion behavior.

### Proposed implementation
1. Separate Lara run state from global UI lock state.
   - Keep pending/disabled behavior scoped to the Lara composer and current run controls.
   - Do not freeze page navigation, sidebar interactions, or unrelated forms while Lara is working.
   - Provide cancel or dismiss controls where technically feasible.

2. Normalize one activity-event model for both streamed and background execution.
   - Treat runtime status/tool events as first-class UI events, not just internal telemetry.
   - Reuse streaming paths for interactive runs and extend background execution to expose more than `Queued` / `Running` / `Completed`.
   - Persist enough run metadata to reconnect after remount, reopen, or refresh.

3. Introduce a transcript-level live activity timeline.
   - Render short, human-readable activity entries such as `Preparing context`, `Selecting tool`, `Running command`, `Inspecting page`, and `Summarizing result`.
   - Group or debounce noisy low-level events so the transcript stays readable.
   - Keep operational activity visually distinct from Lara's final assistant response.

4. Design a shell-level busy signal.
   - Add a subtle but obvious indicator on the Lara trigger and panel header.
   - Candidate patterns: flashing dot, pulsing badge, or rotating ring.
   - Provide a non-animated fallback for reduced-motion users.

5. Unify short and long runs.
   - Short runs should stream progress and response content in real time.
   - Long runs should transition into background execution without collapsing into a dead-end waiting message.
   - Avoid a UX cliff where the user only sees `Running in background...` and nothing else.

6. Define recovery and reconnection behavior.
   - Reopening the panel should restore the current run state and recent activity.
   - Navigation or Livewire remount should not discard an in-flight Lara run.
   - If the run already completed, hydrate the final answer and clear the busy signal.

7. Keep activity semantics separate from the final response.
   - Busy/status entries are operational state, not assistant prose.
   - The final answer should remain clean and should not duplicate every intermediate event verbatim.

### Suggested files to inspect
- `app/Modules/Core/AI/Livewire/Chat.php`
- `app/Modules/Core/AI/Livewire/Concerns/HandlesStreaming.php`
- `app/Modules/Core/AI/Livewire/Concerns/HandlesBackgroundChat.php`
- `app/Modules/Core/AI/Http/Controllers/ChatStreamController.php`
- `app/Modules/Core/AI/Jobs/RunAgentChatJob.php`
- `resources/core/views/livewire/ai/chat.blade.php`
- `resources/core/views/components/ai/chat-composer-fields.blade.php`
- Lara trigger/mount points in the persistent app shell

### Acceptance criteria
- Starting a Lara run does not disable unrelated page interactions or main-content navigation.
- The user sees live activity updates before the final Lara answer is available.
- The user sees a clear busy signal when the Lara panel is open and when it is collapsed.
- Activity state survives panel reopen and page remount where feasible.
- Long-running runs can continue in background with progressive status instead of only final completion.
- Busy indicator behavior is accessible and reduced-motion safe.

### Notes
Reference interaction patterns:
- Copilot CLI
- Claude Code
- OpenCode

Implementation direction:
- Prefer concise stage updates over raw token noise.
- Build one run-state model that can power both interactive streaming and background execution.
- This work should align with item 1 so session persistence and activity recovery use the same state boundaries.