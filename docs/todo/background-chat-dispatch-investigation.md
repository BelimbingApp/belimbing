# Background Chat Dispatch Investigation

## Problem Essence

Background chat offload currently depends on a queue worker that is outside the chat request path, but the UI and ledger do not make that dependency or its failure modes explicit enough. When that worker does not consume `ai-agent-tasks`, a background chat can remain `queued` indefinitely, produce no `ai_run`, and never fulfill the user-facing promise that the response will appear later.

## Status

Complete — investigation finished, recommendations ready.

## Desired Outcome

Background chat should have a durable, traceable contract:

1. The UI creates a background dispatch and clearly transitions from interactive to async mode.
2. A queue worker picks up the job promptly, creates a linked `ai_run`, and persists progress as the run advances.
3. The chat UI shows trustworthy progress and completion, and surfaces when the queue is stalled instead of waiting forever.

## Public Contract

For a background chat offload, the system should guarantee:

1. `OperationDispatch` is the async envelope and starts at `queued`.
2. A queued job exists for the dispatch and is consumed from `ai-agent-tasks`.
3. When execution actually starts, an `ai_run` row is created and linked back to the dispatch.
4. Progress updates are visible to the user while work is running.
5. Completion, failure, or staleness is reflected in both the dispatch ledger and the chat transcript.

## Top-Level Components

### Chat UI / Livewire request

- `Chat::sendMessage()` detects interactive timeout and calls `handleTimeoutWithBackgroundOffer()`.
- `HandlesBackgroundChat::dispatchBackgroundChat()` creates the `OperationDispatch` row and dispatches `RunAgentChatJob`.
- The Blade view polls `pollBackgroundChat()` every 3 seconds and refreshes the transcript with `$wire.$refresh()`.

### Queue / dispatch envelope

- `RunAgentChatJob` is queued onto `ai-agent-tasks`.
- The dispatch only leaves `queued` when the job `handle()` method starts and calls `markRunning()`.

### Runtime / run ledger

- `AgenticRuntime::runStream()` creates the `run_id` and writes `ai_runs` through `RunRecorder::start()`.
- Transcript activity entries are persisted through `ChatRunPersister`.
- Final assistant content is appended only after the runtime emits `done`.

### Transcript / UI completion

- The background progress banner is driven by `OperationDispatch` polling, not SSE or broadcasting.
- The finished assistant response appears because the polling widget forces repeated Livewire refreshes until the assistant message has been persisted.

## Findings

### 1. Actual background chat flow: UI → dispatch → queue → run

1. `Chat::sendMessage()` runs the interactive path first. On timeout it calls `handleTimeoutWithBackgroundOffer()` and appends the notice: “Running it in the background — you'll see the response when it's ready.”  
   Evidence: `app/Modules/Core/AI/Livewire/Chat.php`, `app/Modules/Core/AI/Livewire/Concerns/HandlesBackgroundChat.php`
2. `dispatchBackgroundChat()` creates `ai_operation_dispatches` with `operation_type = background_chat`, `status = queued`, and session/page context in `meta`. It then dispatches `RunAgentChatJob`.  
   Evidence: `app/Modules/Core/AI/Livewire/Concerns/HandlesBackgroundChat.php`
3. `RunAgentChatJob` is queued on `ai-agent-tasks`. Nothing updates the dispatch row until a queue worker reserves and starts the job.  
   Evidence: `app/Modules/Core/AI/Jobs/RunAgentChatJob.php`
4. When the job starts, it loads the dispatch, marks it `running`, hydrates page context, reads the session transcript, and calls `AgenticRuntime::runStream(..., ExecutionPolicy::background(), ...)`.  
   Evidence: `app/Modules/Core/AI/Jobs/RunAgentChatJob.php`
5. `AgenticRuntime::runStream()` creates the `run_id` and inserts the `ai_runs` row through `RunRecorder::start()`. This is the first point where an `ai_run` can exist.  
   Evidence: `app/Modules/Core/AI/Services/AgenticRuntime.php`, `app/Modules/Core/AI/Services/ControlPlane/RunRecorder.php`
6. As streamed status events arrive, `ChatRunPersister` appends thinking/tool/hook entries to the transcript. On `done`, the job appends the final assistant message and marks the dispatch `succeeded`.  
   Evidence: `app/Modules/Core/AI/Jobs/RunAgentChatJob.php`, `app/Modules/Core/AI/Services/ChatRunPersister.php`

### 2. Why `op_dZl3yVVj6Y37LUtR8pQ4` stayed `queued` and never created an `ai_run`

This dispatch never reached job execution.

- Live data shows the dispatch row is still `queued`, with `started_at = null`, `run_id = null`, and `finished_at = null`.
- Live data also shows a matching `jobs` row for `RunAgentChat[op_dZl3yVVj6Y37LUtR8pQ4]` on queue `ai-agent-tasks` with `attempts = 0` and `reserved_at = null`.
- `failed_jobs` is empty for this path.
- A process listing found no active `php artisan queue:work` or Horizon worker consuming jobs in this environment.

Conclusion: the dispatch is blocked before `RunAgentChatJob::handle()` begins. Because `ai_runs` is only created inside `AgenticRuntime::runStream()` after the job starts, no `ai_run` row could have been created for this dispatch.

### 3. How the “you'll see the response when it's ready” promise is fulfilled today

The promise is fulfilled by polling and transcript refresh, not by push delivery.

1. The chat UI shows a background status banner whenever `$backgroundDispatchId` is set.
2. Alpine polls `pollBackgroundChat()` every 3 seconds.
3. After each poll, it calls `$wire.$refresh()` to reload transcript entries from storage.
4. Once the background job appends the final assistant message, the next refresh renders it in the chat history.
5. When `pollBackgroundChat()` sees a terminal dispatch state, it clears `$backgroundDispatchId`, the banner disappears, and polling stops.

This works only if the queued job actually runs. There is no separate completion signal, websocket push, or stale-queued detection for the promise itself.

### 4. `docs/todo/ai-run-ledger.md` design vs implementation

The document is directionally correct, but several important details no longer match the code.

#### Matches

- Background chat is offloaded on interactive timeout.
- A background job exists and persists transcript activity progressively.
- The chat UI does poll `OperationDispatch` and refresh the transcript.

#### Mismatches

1. The doc says background chat job flow is `markRunning()` → `AgenticRuntime::run()` → persist assistant message → `markSucceeded()`.  
   Actual implementation uses `AgenticRuntime::runStream()` inside `RunAgentChatJob`, not `run()`.
2. The doc says `ChatStreamController` directly calls `RunRecorder::start()/complete()/fail()`.  
   Actual implementation delegates run-ledger writes to `AgenticRuntime::runStream()`, which owns `RunRecorder`.
3. The doc says `dispatch_id` is set by `RunRecorder::attachDispatch()` or at `start()` time if known.  
   Actual code defines `attachDispatch()`, but no current caller uses it. Background chat runs therefore are not linked back into `ai_runs.dispatch_id`.
4. The doc frames `OperationDispatch` as linking to `ai_runs` for run-level detail.  
   Actual background chat mostly links in the other direction by writing `OperationDispatch.run_id`; the `ai_runs.dispatch_id` side is currently missing.

### 5. Current response rendering: one-go vs streaming

#### Interactive chat

- Uses SSE via `ChatStreamController`.
- Tool/thinking activity is rendered live in Alpine.
- Assistant text streams as token deltas in the browser.
- The transcript only receives the final assistant message at the end of the stream.

#### Background chat

- Does not stream deltas to the browser.
- Persists thinking/tool/hook entries progressively into the transcript.
- The final assistant response is still written in one go after runtime completion.
- The UI sees progress only through periodic transcript refreshes and a coarse dispatch-status banner.

### 6. Real-time transparency gaps

1. **No worker-health contract:** the UI promises eventual completion, but there is no explicit check that `ai-agent-tasks` is actually being consumed.
2. **No stale queued detection:** only `running` dispatches can be swept as stale. A dispatch can stay `queued` forever.
3. **No `dispatch_id` on `ai_runs`:** admin and user tooling cannot reliably pivot from a background dispatch to its run ledger row.
4. **No formal background progress model:** the doc proposes `ai_runs.meta.progress`, but current background progress is just transcript side effects plus dispatch status.
5. **No push path for background execution:** progress requires polling and full Livewire refreshes.
6. **Final answer is not streamed for background runs:** users see intermediate tool activity, then the final response appears all at once.

## Design Decisions

- We should keep `OperationDispatch` as the async envelope and `ai_runs` as the LLM execution ledger. The separation is sound.
- We should make background-chat durability explicit instead of assuming queue infrastructure is present.
- We should treat `queued forever` as a first-class failure mode, not an operator-only mystery.
- We should align the ledger doc to the real `runStream()`-based implementation before building more features on top of it.

## Phases

## Phase 1 — Investigation Evidence

- [x] Trace the background chat execution path from Livewire to queued job to runtime.
- [x] Inspect dispatch `op_dZl3yVVj6Y37LUtR8pQ4` in live data.
- [x] Confirm the matching queued job still exists and has never been reserved.
- [x] Confirm no failed job record exists for that dispatch.
- [x] Confirm no queue worker is currently consuming the `ai-agent-tasks` queue in this environment.
- [x] Compare `docs/todo/ai-run-ledger.md` against the shipped implementation.

### Evidence

- Live dispatch: `op_dZl3yVVj6Y37LUtR8pQ4`, status `queued`, `started_at = null`, `run_id = null`
- Live queued job: `RunAgentChat[op_dZl3yVVj6Y37LUtR8pQ4]` on `ai-agent-tasks`, `attempts = 0`, `reserved_at = null`
- Live failed job backlog: none for this path

## Phase 2 — Recommended Follow-up Work

- [ ] Link background runs to dispatches by calling `RunRecorder::attachDispatch()` as soon as `run_id` is known, or extend `RunRecorder::start()` to accept `dispatch_id`.
- [ ] Add queued-dispatch staleness handling, not just running-dispatch sweeps. A `queued` dispatch older than a threshold should fail with an explicit queue-stalled error.
- [ ] Add an operator-visible health signal for `ai-agent-tasks` consumption so missing workers are obvious before a user waits indefinitely.
- [ ] Update `docs/todo/ai-run-ledger.md` to reflect the real `runStream()` ownership model and the current gaps.
- [ ] Formalize background progress into a stable contract, preferably on `ai_runs.meta.progress`, instead of inferring progress only from transcript side effects.
- [ ] Decide whether background final responses should stay one-go or gain push/stream semantics. If transparency is the goal, polling transcript snapshots is only a partial answer.
- [ ] Consider push delivery for background progress and completion so the chat does not depend on repeated `$wire.$refresh()` polling.
