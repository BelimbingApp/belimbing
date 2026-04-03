# Claw Code Runtime Parity Research for `ai-run-ledger`

## Executive Summary

The closest reading of claw-code's Rust runtime shows that its core runtime is **session-centric**, not ledger-centric: the canonical execution history is a structured session file that stores assistant text, `tool_use` blocks, `tool_result` blocks, and per-turn token usage inside one conversation object.[^1][^2] Belimbing today is more fragmented: final assistant messages live in JSONL, run metadata lives in `meta.json`, failures are duplicated into a dedicated AI log, and async lifecycle lives in `OperationDispatch`.[^3][^4][^5][^6] `docs/todo/ai-run-ledger.md` correctly identifies that fragmentation, but parity with claw-code requires one important refinement: **`ai_runs` should become a query/index layer over a structured transcript, not the sole source of conversational truth**.[^7][^1]

The highest-priority parity gaps are: missing structured transcript blocks for tool calls/results, a still-active hard iteration cap in production code, thin streaming status events that do not persist the actual runtime timeline, and no first-class approval/sandbox observability comparable to claw-code's runtime surfaces.[^8][^9][^10][^11] The strongest parts of `ai-run-ledger.md` for parity are Phase 1's activity-stream direction and Phase 0's transcript versioning work; the parts that go beyond claw-code are the global `ai_runs` table, orphan reaper, and control-plane routing, which are still good BLB-specific upgrades if they do not replace the transcript as the execution record.[^7][^12]

## Architecture / System Overview

```text
claw-code runtime (mirror commit 460bc273)
-----------------------------------------
system prompt + config + CLAW.md
              |
              v
    ConversationRuntime::run_turn()
              |
              v
        ApiClient stream
              |
      assistant text/tool_use
              |
      PermissionPolicy + HookRunner
              |
              v
          ToolExecutor
              |
          tool_result
              |
              v
  Session { messages[], blocks[], usage }


Belimbing today
---------------
messages + prompt factories
              |
              v
        AgenticRuntime
              |
      tool actions summarized
              |
   +----------+-----------+-------------+
   |                      |             |
   v                      v             v
JSONL transcript      meta.json runs   ai.log
final messages        run metadata     failures/retries
                                       
              +-------------+
              |             |
              v             v
      RunInspection     OperationDispatch


Parity-safe BLB target
----------------------
AgenticRuntime
    |
    v
typed activity transcript
(message/tool_call/tool_result/thinking + usage)
    |
    +--> ai_runs projection for querying / routing / UI
    |
    +--> OperationDispatch link for async lifecycle
```

claw-code's runtime crate explicitly owns sessions, config, permissions, prompts, runtime loop, hooks, sandbox support, SSE parsing, and usage accounting inside one Rust workspace surface.[^13][^14] Belimbing's `ai-run-ledger.md` is trying to solve a real BLB problem, but claw parity depends more on **how the runtime history is modeled** than on whether the metadata ends up in SQL.[^7][^12]

## 1. What claw-code's runtime actually treats as the source of truth

claw-code persists runtime history as a `Session` with ordered `ConversationMessage` values, where each message holds typed `ContentBlock`s: `Text`, `ToolUse`, or `ToolResult`. Assistant messages may also carry `TokenUsage` inline, which lets the runtime reconstruct usage directly from the session file instead of consulting a separate run ledger.[^1][^15] The runtime loop appends the assistant tool-call message to the session, executes each tool, appends a `tool_result` message for each tool call, and only then continues the loop; that means the session file is already a replayable execution trace.[^2]

That shape matters for BLB. `ai-run-ledger.md` already says the transcript should remain "content, not metadata", and that the new v2 transcript needs typed entries like `tool_call`, `tool_result`, and `thinking`.[^12] That direction matches claw-code parity. What would *not* match claw-code is making `ai_runs` the only canonical store for tool chronology while leaving the transcript as plain assistant/user text. If BLB wants claw-like runtime parity, the structured transcript must remain the primary history, and `ai_runs` should be a projection for indexing, inspection, and correlation.[^7][^12]

## 2. What claw-code has in the runtime loop that BLB still lacks

### 2.1 Structured tool-call persistence

In claw-code, tool calls and tool results are first-class conversation blocks, not just summaries.[^1][^2] In BLB today, `MessageManager::appendAssistantMessage()` writes only the final assistant message to JSONL and stores run metadata separately through `SessionManager::storeRunMeta()`, while `MessageManager::read()` later re-hydrates metadata by joining transcript lines to the `runs` map in `meta.json`.[^3][^4] That means BLB preserves the **fact** that a run happened, but not the exact tool-call/result structure inside the transcript itself.[^3][^4]

`ai-run-ledger.md` Phase 1 is therefore parity-critical, not just UX polish: replacing chat bubbles with an activity stream and extending transcript entries to `message`, `tool_call`, `tool_result`, and `thinking` is the change that moves BLB's storage model toward claw-code's runtime model.[^12]

### 2.2 Natural-bounds looping instead of a hard iteration cap

The claw-code mirror's `ConversationRuntime::new_with_features()` defaults `max_iterations` to `usize::MAX`, even though a setter still exists for tests and opt-in overrides.[^2] BLB's TODO doc says the hard cap has already been removed and explicitly argues that tool-calling loops should run until final text, error, cancellation, or context exhaustion.[^8] The production code does not match that plan yet: `AgenticRuntime` still defines `DEFAULT_MAX_ITERATIONS = 25`, still reads `config('ai.llm.max_iterations')`, and still emits `AiErrorType::MaxIterations` on both sync and streaming paths; the config file still exposes `ai.llm.max_iterations = 25`.[^9]

This is the clearest verified parity bug: **BLB is still materially behind claw-code on loop termination semantics, despite the TODO saying the opposite**.[^8][^9]

### 2.3 Runtime-integrated usage accounting

claw-code's session messages can carry `TokenUsage`, and `UsageTracker::from_session()` reconstructs cumulative usage directly from the restored session. The same module can compute model-specific cost estimates from that usage record.[^15] BLB today stores prompt/completion tokens inside per-run metadata and exposes them through `RunInspection`, but that view is per-run and assembled from session metadata plus dispatch lookup rather than reconstructed from a structured transcript.[^5][^6]

`ai-run-ledger.md` covers run-level token visibility and later cost attribution, but it does not yet say that the transcript itself should preserve enough structure to rebuild usage and execution history the way claw-code does.[^12] For parity, BLB should treat transcript v2 entries as sufficient to replay the run timeline, while `ai_runs` handles the query workload.[^1][^12]

## 3. Policy, approval, and sandbox surfaces claw-code exposes in-runtime

claw-code's runtime has a dedicated `PermissionPolicy` with explicit modes (`read-only`, `workspace-write`, `danger-full-access`, `prompt`, `allow`) and can escalate via a `PermissionPrompter` when a tool needs more power than the current mode grants.[^10] Hook execution is also runtime-integrated: `HookRunner` runs `PreToolUse` and `PostToolUse` commands, can deny a tool by returning exit code `2`, and merges hook feedback back into the tool result visible to the conversation loop.[^11]

The bash/sandbox path is similarly first-class. `BashCommandInput` carries sandbox, namespace, network, and filesystem overrides; `execute_bash()` resolves sandbox status from runtime config; and the returned `BashCommandOutput` can include a detailed `sandboxStatus` object describing what was requested, supported, and actually active.[^16][^17] The runtime config loader also parses hooks, plugins, MCP, OAuth, permission mode, model choice, and sandbox config from discovered settings files, while the prompt builder discovers `CLAW.md`/instruction files as runtime context.[^18][^19]

Belimbing has some analogous policy surfaces, but they are not parity-equivalent yet. Tool use is capability-gated in `AgentToolRegistry`, and `AgenticRuntime` has a hook coordinator around pre-context, pre-tool-registry, post-tool-result, and post-run stages; however, the current streaming/run metadata surfaces do not expose approval escalations or sandbox outcomes the way claw-code does.[^20][^21] `ai-run-ledger.md` currently says little about approval prompts or sandbox reporting, so those parity gaps are mostly **out of scope in the doc even though they are in scope for runtime equality**.[^12]

## 4. What BLB's current runtime and inspection path actually do

BLB currently splits runtime state across four paths:

1. `SessionManager` persists session identity plus a `runs` map inside `*.meta.json`.[^4]
2. `MessageManager` appends assistant/user text lines to `*.jsonl`, then re-enriches messages by joining transcript lines to the `runs` map on read.[^3]
3. `AiRuntimeLogger` duplicates runtime failures/retries into a dedicated `ai` log channel.[^5]
4. `OperationDispatch` tracks async lifecycle, result summaries, and linked `run_id` values for queued work.[^6]

`RunInspectionService` then reconstructs a normalized operator view by reading session run metadata and optionally joining `OperationDispatch`; inspection by dispatch even scans the agent's sessions to find the matching `run_id`.[^22] This is precisely the fragmentation `ai-run-ledger.md` is trying to remove, and the doc's argument for a queryable `ai_runs` table is well-supported by the current BLB code.[^7][^22]

The streaming path also shows why Phase 1 is necessary. `AgenticRuntime::runStreamingToolLoop()` emits only `thinking`, `tool_started`, `tool_finished`, `delta`, `done`, and `error` SSE events; the tool status events include the tool name but not argument summaries or result previews.[^23] `ChatStreamController` forwards those SSE events, but only persists the final assistant message on `done` or a structured error message on `error`, so the live activity timeline is not saved as the canonical transcript.[^24] That is materially weaker than claw-code's session model, where tool calls/results already live inside the session history itself.[^1][^2]

## 5. Gap matrix: claw parity vs. `ai-run-ledger.md`

| Capability | claw-code runtime | BLB today | `ai-run-ledger.md` | Parity read |
|---|---|---|---|---|
| Structured conversation history | Session stores `Text`, `ToolUse`, `ToolResult`, plus optional usage inline.[^1] | JSONL stores user/assistant messages; tool history is summarized into separate metadata.[^3][^4] | Transcript v2 proposes typed entries for `tool_call`, `tool_result`, `thinking`.[^12] | **Required for parity.** |
| Global run lookup | Session-centric; no separate SQL run ledger in runtime crate.[^1][^2] | Run inspection needs employee/session context and sometimes scans sessions.[^22] | `ai_runs` becomes canonical query surface.[^7] | **Useful BLB enhancement, not required for claw parity.** |
| Activity stream | Runtime loop is inherently reconstructable from session blocks; SSE support exists in runtime crate.[^1][^2][^25] | SSE only surfaces coarse phases and final text; timeline is not persisted.[^23][^24] | Phase 1 replaces bubbles with persistent activity stream and richer status events.[^12] | **Required for parity.** |
| Iteration bound | Default runtime loop is effectively unbounded (`usize::MAX`).[^2] | Hard cap remains at 25 in code/config; `MaxIterations` errors still exist.[^9] | Doc says cap is already removed.[^8] | **Required and currently broken.** |
| Approval policy | Explicit permission modes + escalation prompt path.[^10] | Tool capability gating exists, but approval outcomes are not first-class runtime timeline data.[^20][^21] | Not substantively covered.[^12] | **Missing parity work outside the doc.** |
| Hooked tool lifecycle | Pre/post tool hooks can deny or annotate execution inside the loop.[^11] | Hook coordinator exists, but the transcript/activity UI does not expose per-tool hook outcomes as first-class entries.[^21][^23] | Hooks only appear as metadata ideas, not transcript-first semantics.[^12] | **Partial parity; transcript still weak.** |
| Sandbox observability | Bash output can return detailed `sandboxStatus` describing request vs. active state.[^16][^17] | No equivalent runtime-wide sandbox status surface in run timeline or ledger doc.[^12][^20] | Not covered beyond broad execution policy.[^12] | **Missing parity work outside the doc.** |
| Usage reconstruction | UsageTracker rebuilds cumulative usage from session messages and can estimate cost.[^15] | RunInspection exposes per-run tokens only.[^6][^22] | Token popover and future cost attribution are planned.[^12] | **Partially addressed; needs transcript-backed reconstruction.** |
| Async lifecycle | Runtime itself is session-centric; background/CLI orchestration is separate.[^2][^13] | `OperationDispatch` already owns async lifecycle cleanly.[^6] | Design decision explicitly keeps `ai_runs` separate from `OperationDispatch`.[^12] | **BLB is on a good path here.** |

## 6. Recommended interpretation of `ai-run-ledger.md` for true claw parity

### 6.1 Keep `ai_runs`, but demote it from "sole truth" to "indexed truth"

The doc should keep the `ai_runs` table because BLB genuinely needs routable, queryable inspections that claw-code's local CLI runtime does not need.[^7] But the parity-safe implementation is:

1. **Transcript/session file:** canonical execution history (`message`, `tool_call`, `tool_result`, `thinking`, usage-enriched assistant turns).[^1][^12]
2. **`ai_runs`:** indexed metadata, correlation, routing, analytics, retention, admin search.[^7][^12]
3. **`OperationDispatch`:** async lifecycle envelope only.[^6][^12]

That preserves claw-code's runtime semantics while still solving BLB's operator-control-plane needs.[^1][^7]

### 6.2 Treat Phase 1 as the real parity milestone

Phase 1 is the parity heart of the doc because it is the phase that moves BLB from "chat transcript + side-channel metadata" to "runtime timeline with durable structure".[^12] Specifically, parity requires:

1. transcript v2 entry types,
2. persisted tool call/result entries,
3. richer stream events that include tool arguments and previews,
4. final-response persistence *plus* intermediate-runtime persistence.[^12][^23][^24]

Without that, BLB may gain a better admin ledger but still remain below claw-code's runtime fidelity.[^1][^24]

### 6.3 Fix the iteration-cap drift before anything else

Because the TODO already states the cap is gone while the code still enforces it, removing `DEFAULT_MAX_ITERATIONS`, `ai.llm.max_iterations`, and `AiErrorType::MaxIterations` should be treated as a parity bug fix, not a future enhancement.[^8][^9]

### 6.4 Expand the doc to cover approval and sandbox observability

If the explicit goal is "equal to claw-code's runtime", `ai-run-ledger.md` should gain a small runtime-parity appendix covering:

1. permission escalation / approval outcome capture,
2. sandbox request vs. active status capture,
3. per-tool hook outcomes in the activity stream,
4. cumulative usage reconstruction from transcript/session history.[^10][^11][^16][^17]

Those are runtime features in claw-code's source, not just CLI polish.[^2][^10][^16]

## 7. Suggested implementation order

1. **Remove the live iteration cap and stale config/error paths.** This is a verified code/doc mismatch and the fastest hard-parity win.[^8][^9]
2. **Implement transcript v2 as the canonical runtime history.** Persist `tool_call`, `tool_result`, `thinking`, and usage-bearing assistant entries before building higher-level inspection features.[^1][^12]
3. **Rebuild streaming around persistent activity entries.** The SSE event stream already has the right skeleton; it needs richer payloads and durable transcript writes, not a separate temporary UI-only timeline.[^23][^24]
4. **Add `ai_runs` as a projection over that transcript.** Use it for queryability, routing, run detail pages, and correlation with `OperationDispatch`, but do not let it absorb the actual conversation graph.[^7][^12]
5. **Add approval/sandbox observability.** That closes the biggest claw runtime gaps still missing from the ledger doc.[^10][^16]

## Confidence Assessment

**Certain**

- The mirror snapshot at commit `460bc273cffe247a3d56ea012862214d107b0039` exposes a structured, session-centric runtime with conversation blocks, permission policy, hooks, sandbox status, usage tracking, config loading, and prompt/instruction discovery.[^1][^2][^10][^11][^15][^18][^19]
- BLB currently fragments runtime facts across JSONL transcript lines, session `runs` metadata, dedicated AI logs, and `OperationDispatch`, and `ai-run-ledger.md` is explicitly aimed at consolidating that state.[^3][^4][^5][^6][^7]
- BLB still has an active max-iterations cap in code/config even though the TODO says it was removed.[^8][^9]

**Inferred but strong**

- The `kjuyoung/claw-code` repository is an accurate enough runtime snapshot for parity research because the mirrored tree contains the full `rust/crates/runtime/src` surface plus project README and parity notes, and the commit itself is labeled "full snapshot".[^13][^26]
- claw-code's higher-level CLI UX likely reconstructs its timeline from the session/runtime model rather than a separate run ledger, because the runtime crate already persists tool-use and tool-result structure in-session and exposes SSE parsing/utilities inside the same crate.[^1][^2][^25]

**Uncertain / out of scope**

- I did not verify every non-runtime crate in claw-code (for example full CLI rendering code outside `runtime`) because the request was scoped to `rust/crates/runtime/src`; some UX details may be implemented one layer up even when the runtime primitives clearly exist.[^13][^25]
- `ai-run-ledger.md` also includes BLB-specific product goals like deep-linkable run pages and operator control-plane ergonomics that go beyond claw-code parity; those are architectural choices, not parity regressions.[^7][^12]

## Footnotes

[^1]: [kjuyoung/claw-code](https://github.com/kjuyoung/claw-code) `rust/crates/runtime/src/session.rs:20-50, 83-139, 177-193, 214-246` (commit `460bc273cffe247a3d56ea012862214d107b0039`).
[^2]: [kjuyoung/claw-code](https://github.com/kjuyoung/claw-code) `rust/crates/runtime/src/conversation.rs:91-145, 153-199, 201-263` (commit `460bc273cffe247a3d56ea012862214d107b0039`).
[^3]: `/home/kiat/repo/laravel/blb/app/Modules/Core/AI/Services/MessageManager.php:61-100, 194-256`.
[^4]: `/home/kiat/repo/laravel/blb/app/Modules/Core/AI/DTO/Session.php:12-23, 30-69`; `/home/kiat/repo/laravel/blb/app/Modules/Core/AI/Services/SessionManager.php:30-59, 176-225, 289-315`.
[^5]: `/home/kiat/repo/laravel/blb/app/Base/AI/Services/AiRuntimeLogger.php:11-149`.
[^6]: `/home/kiat/repo/laravel/blb/app/Modules/Core/AI/Models/OperationDispatch.php:17-26, 145-207`; `/home/kiat/repo/laravel/blb/app/Modules/Core/AI/Jobs/RunAgentTaskJob.php:23-32, 76-166`.
[^7]: `/home/kiat/repo/laravel/blb/docs/todo/ai-run-ledger.md:9-57, 88-152, 428-437`.
[^8]: `/home/kiat/repo/laravel/blb/docs/todo/ai-run-ledger.md:295-318`.
[^9]: `/home/kiat/repo/laravel/blb/app/Modules/Core/AI/Services/AgenticRuntime.php:30-45, 234-316, 489-510, 563-704`; `/home/kiat/repo/laravel/blb/app/Base/AI/Config/ai.php:34-39`; `/home/kiat/repo/laravel/blb/app/Base/AI/Enums/AiErrorType.php:1-26`.
[^10]: [kjuyoung/claw-code](https://github.com/kjuyoung/claw-code) `rust/crates/runtime/src/permissions.rs:3-21, 25-135` (commit `460bc273cffe247a3d56ea012862214d107b0039`).
[^11]: [kjuyoung/claw-code](https://github.com/kjuyoung/claw-code) `rust/crates/runtime/src/hooks.rs:8-47, 49-162, 164-237` (commit `460bc273cffe247a3d56ea012862214d107b0039`).
[^12]: `/home/kiat/repo/laravel/blb/docs/todo/ai-run-ledger.md:153-197, 221-346, 430-437`.
[^13]: [kjuyoung/claw-code](https://github.com/kjuyoung/claw-code) `rust/README.md:1-48, 73-93` (commit `460bc273cffe247a3d56ea012862214d107b0039`); [kjuyoung/claw-code](https://github.com/kjuyoung/claw-code) `rust/crates/runtime/Cargo.toml:1-16` (commit `460bc273cffe247a3d56ea012862214d107b0039`); [kjuyoung/claw-code](https://github.com/kjuyoung/claw-code) `rust/crates/runtime/src/lib.rs:1-86` (commit `460bc273cffe247a3d56ea012862214d107b0039`).
[^14]: [kjuyoung/claw-code](https://github.com/kjuyoung/claw-code) `rust/crates/runtime/src/config.rs:32-56, 226-258, 333-379` (commit `460bc273cffe247a3d56ea012862214d107b0039`).
[^15]: [kjuyoung/claw-code](https://github.com/kjuyoung/claw-code) `rust/crates/runtime/src/usage.rs:29-35, 80-121, 163-210` (commit `460bc273cffe247a3d56ea012862214d107b0039`).
[^16]: [kjuyoung/claw-code](https://github.com/kjuyoung/claw-code) `rust/crates/runtime/src/bash.rs:17-65, 67-100, 167-239` (commit `460bc273cffe247a3d56ea012862214d107b0039`).
[^17]: [kjuyoung/claw-code](https://github.com/kjuyoung/claw-code) `rust/crates/runtime/src/sandbox.rs:27-68, 155-207, 210-262` (commit `460bc273cffe247a3d56ea012862214d107b0039`).
[^18]: [kjuyoung/claw-code](https://github.com/kjuyoung/claw-code) `rust/crates/runtime/src/config.rs:38-63, 197-258, 382-459` (commit `460bc273cffe247a3d56ea012862214d107b0039`).
[^19]: [kjuyoung/claw-code](https://github.com/kjuyoung/claw-code) `rust/crates/runtime/src/prompt.rs:49-82, 202-223` (commit `460bc273cffe247a3d56ea012862214d107b0039`).
[^20]: `/home/kiat/repo/laravel/blb/app/Modules/Core/AI/Services/AgentToolRegistry.php:48-50, 120-135`.
[^21]: `/home/kiat/repo/laravel/blb/app/Modules/Core/AI/Services/RuntimeHookCoordinator.php:18-49, 49-77, 106-144`; `/home/kiat/repo/laravel/blb/app/Modules/Core/AI/Services/AgenticRuntime.php:244-252, 304-305, 573-580, 667-668`.
[^22]: `/home/kiat/repo/laravel/blb/app/Modules/Core/AI/Services/ControlPlane/RunInspectionService.php:12-17, 32-57, 69-109, 118-139`; `/home/kiat/repo/laravel/blb/app/Modules/Core/AI/DTO/ControlPlane/RunInspection.php:8-14, 52-92, 118-140`.
[^23]: `/home/kiat/repo/laravel/blb/app/Modules/Core/AI/Services/AgenticRuntime.php:587-704, 722-804`.
[^24]: `/home/kiat/repo/laravel/blb/app/Modules/Core/AI/Http/Controllers/ChatStreamController.php:67-86, 99-131, 184-219`.
[^25]: [kjuyoung/claw-code](https://github.com/kjuyoung/claw-code) `rust/crates/runtime/src/sse.rs:3-97` (commit `460bc273cffe247a3d56ea012862214d107b0039`).
[^26]: [kjuyoung/claw-code](https://github.com/kjuyoung/claw-code/commit/460bc273cffe247a3d56ea012862214d107b0039) commit `460bc273cffe247a3d56ea012862214d107b0039`; [kjuyoung/claw-code](https://github.com/kjuyoung/claw-code) `PARITY.md:1-29` (commit `460bc273cffe247a3d56ea012862214d107b0039`).
