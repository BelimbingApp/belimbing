# Selective Runtime Parity Follow-up TODO

**Source:** `docs/todo/clawcode-parity/00-runtime-parity-gap-audit.md`
**Parent:** `docs/todo/ai-run-ledger.md` §Runtime Parity Appendix
**Scope:** Close the remaining **high-ROI** Claw Code runtime parity gaps that strengthen BLB's transcript-first model without forcing BLB to copy Claw Code's full product model.

---

## Problem Essence

The main Claw Code parity gaps are now narrow: BLB still lacks **canonical transcript entries for hook outcomes** and **first-class approval visibility** in the runtime timeline. These are worth bridging because they improve replayability, operator trust, and transcript-first correctness. By contrast, sandbox observability and full interactive permission escalation should remain deferred until BLB adopts those product capabilities for real.

---

## Scope Decision

### In Scope

1. Persist hook outcomes as first-class transcript history.
2. Surface approval and denial decisions as first-class runtime timeline data.
3. Keep the transcript as the canonical execution history; `ai_runs` remains a projection.

### Out of Scope

1. No Claw Code-style interactive permission escalation flow in this phase.
2. No `sandboxStatus`-style reporting until BLB has a real sandbox boundary to report.
3. No attempt to make BLB mimic Claw Code where BLB's authz-first architecture is intentionally different.

---

## 1. Canonical Transcript Entries for Hook Outcomes — P1

The runtime currently emits `hook_action` and `tool_denied` visibility in streaming/meta paths, but the canonical transcript types remain `message`, `tool_call`, `tool_result`, and `thinking`. That leaves hook behavior partly outside the authoritative execution history.

### 1.1 Add a transcript entry type for hook actions

**Files:** `app/Modules/Core/AI/DTO/Message.php`, `app/Modules/Core/AI/Services/MessageManager.php`

- [x] Add a new transcript entry type for hook events, e.g. `hook_action`
- [x] Extend the read/write path so hook events are first-class persisted entries, not just SSE-only annotations
- [x] Keep backward compatibility for older transcripts

### 1.2 Persist hook actions from the runtime

**Files:** `app/Modules/Core/AI/Services/AgenticRuntime.php`, `app/Modules/Core/AI/Services/RuntimeHookCoordinator.php`, streaming persistence path

- [x] Persist `PreToolRegistry` tool removals as transcript entries with stage name and removed tool list
- [x] Persist `PreToolUse` denials as transcript-visible hook events in addition to denied `tool_result` output
- [x] Persist post-tool hook summaries where they materially change tool interpretation — _Deferred: PostToolResult hooks currently store outcomes in hookMetadata; transcript entry added only when denial/removal is the action. PostToolResult summaries will be added when a concrete use case materializes._

### 1.3 Render hook actions in the activity stream

**Files:** `resources/core/views/livewire/ai/chat.blade.php`, `resources/core/views/components/ai/activity/`

- [x] Add a low-emphasis activity row for hook actions
- [x] Show stage, short reason, and affected tool(s) without overwhelming the main assistant/tool flow
- [x] Reuse the same renderer for persisted transcript entries and streaming entries

---

## 2. Approval Visibility Without Full Interactive Escalation — P1

BLB intentionally uses authz-first capability gating rather than Claw Code's prompt-based permission escalation. That product decision can stay. What should improve now is visibility: users and operators should be able to see when a tool was denied, why, and whether the denial came from authz or hooks.

### 2.1 Normalize approval/denial outcome data

**Files:** runtime/tool execution path, transcript persistence path, run metadata projection

- [x] Define a consistent denial payload shape covering:
  - [x] source (`authorization` vs `hook`)
  - [x] tool name
  - [x] reason / capability / code
  - [x] whether execution was skipped before tool invocation
- [x] Ensure both sync and streaming paths produce the same structure

### 2.2 Make approval outcomes first-class in the timeline

**Files:** transcript persistence path, activity stream rendering, run detail views

- [x] Render denied/prevented actions as visible timeline events, not only buried in error payloads
- [x] Distinguish:
  - [x] denied before execution
  - [x] executed but failed
  - [x] tool removed before model selection
- [x] Ensure standalone run detail shows the same approval story as the chat transcript

### 2.3 Document the product boundary

**Files:** `docs/todo/ai-run-ledger.md`, `docs/architecture/ai/current-state.md` if needed

- [x] Document that BLB currently supports **approval visibility**, not **interactive permission granting**
- [x] Keep the distinction explicit so future readers do not misread this work as full Claw Code permission parity

---

## 3. Explicitly Deferred Gaps

These gaps remain real, but they should not block the current parity follow-up.

### 3.1 Sandbox observability — Deferred

- [ ] Revisit only when BLB introduces a real sandbox/isolation boundary for tool execution
- [ ] At that point, report requested vs active isolation status as part of tool execution metadata

### 3.2 Interactive permission escalation — Deferred pending product decision

- [ ] Revisit only if BLB decides to support mid-run human approval prompts
- [ ] If adopted later, design it as a BLB-native authz workflow rather than a direct Claw Code clone

---

## Success Criteria

This follow-up is complete when:

1. Hook outcomes are part of the canonical transcript, not just streaming/meta side channels.
2. Approval and denial outcomes are clearly visible in chat, run detail, and transcript replay.
3. The docs clearly state that sandbox observability and interactive permission escalation are intentionally deferred.
