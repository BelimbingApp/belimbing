# ai/lara-coding-agent-harness.md

**Status:** In Progress
**Last Updated:** 2026-06-07
**Sources:** `docs/architecture/ai/lara.md`, `docs/plans/ai-lara-resident-coding-agent-gap.md`, `docs/plans/lara-concurrent-runs.md`, recent Lara runs `01ktgea1bvcrgc8bw1e0zbmjmv` and `01ktgefwcs7qmc7828bwxfekv6`, Pi coding-agent architecture review from GitHub `earendil-works/pi` (`packages/agent/src/agent-loop.ts`, `packages/agent/src/agent.ts`, `packages/agent/src/harness/agent-harness.ts`, `packages/agent/src/harness/messages.ts`, `packages/agent/src/harness/compaction/compaction.ts`, `packages/agent/src/harness/skills.ts`, `packages/coding-agent/src/core/system-prompt.ts`, `packages/coding-agent/src/core/tools/{read,bash,edit,write}.ts`)
**Agents:** Amp/GPT-5

## Problem Essence

Lara has BLB-native product infrastructure — identity, authz, sessions, page context, run ledger, queue-owned execution, replay, and control-plane visibility — but she does not yet have a strong general-purpose coding-agent harness. The result is that simple code tasks can turn into broad repository exploration, high token burn, and high-blast-radius edits.

## Desired Outcome

Lara remains BLB's resident in-product agent, but the first-class design target is a general-purpose coding-agent harness: explicit loop state, bounded structured tools, context transformation, exact edits, narrow verification, resumable task state, and checkpoints before wasteful exploration. BLB proficiency should emerge from applying that harness to the codebase and its instructions, not from one-off task heuristics.

## Top-Level Components

**Minimal coding loop** — A harness-level loop for repository work: localize source, read exact files, patch minimal diff, verify, summarize. The model can still reason freely, but the harness should make this sequence the default path.

**Structured repository tools** — Search, read, edit, and verification tools become the default coding surface. Shell and browser remain available, and tool ordering plus schemas make each tool's role clear without prompt-level nagging.

**Context and tool budgets** — Per-run budgets cap broad search, read volume, shell use, repeated failed edits, and token growth. Budget pressure produces a checkpoint with the current findings and recommended narrowing path rather than silent over-exploration.

**BLB source map** — A general page/module/source map translates the current route or task surface into likely owning files, generated source-of-truth files, shared renderers, and high-blast-radius shared components. This is not a case-specific rule; it is BLB's framework topology exposed to Lara.

**Blast-radius classifier** — Files are classified by edit scope: field/config, module view, module class, shared renderer, shared component, framework infrastructure, generated/vendor. Lara should prefer the lowest sufficient blast radius and justify shared/core edits.

**Resumable task state** — Continuation uses compact persisted work state, not just transcript inference. The state records the task, current phase, candidate files, ruled-out paths, selected edit plan, diff summary, verification state, and budget pressure.

**Lazy skills and rules** — Lara sees available skills, guides, and project rules by name and description, then reads the full content only when relevant. Stable repo conventions live in versioned skill/rule files instead of hardcoded prompt branches.

**Evaluation harness** — Lara is measured against representative BLB coding tasks and compared with external agents such as Pi, OpenCode, Codex, and Copilot on target-file accuracy, token use, tool calls, diff size, verification, and recovery after interruption.

## Pi Harness Reference

Pi is useful because it is small enough to study as a construction pattern. The relevant lessons are structural, not product-specific.

**Pure agent loop plus stateful wrapper** — Pi separates a raw loop (`agent-loop.ts`) from the stateful `Agent` and higher `AgentHarness`. Lara should mirror the separation: the loop owns turn mechanics, the stateful layer owns transcript/run state, and the product layer owns BLB authz, persistence, UI, and policy.

**Turn-boundary hooks** — Pi refreshes system prompt, tools, context, and model settings at turn boundaries through harness hooks. Lara should make per-turn preparation explicit so context, permissions, selected tools, budget state, and page/task state are recalculated predictably.

**Context transformation before provider calls** — Pi keeps rich internal messages but converts them to provider messages through `transformContext` and `convertToLlm`. Lara needs the same shape so run ledger events, tool traces, compaction summaries, and task-state records can be durable without being dumped raw into every LLM call.

**Tool definitions carry behavior and prompt guidance** — Pi tools define schema, output limits, prompt snippets, guidelines, rendering, execution mode, and argument repair in one place. Lara's tools should similarly be self-describing, bounded, and testable.

**Structured read/edit/write tools first** — Pi's read tool supports offsets/limits and truncation; edit applies exact unique text replacements and returns diffs; write is explicit. Lara should have equivalent first-class tools so normal coding work does not depend on shell transcripts.

**Shell is a managed execution backend** — Pi keeps shell powerful but bounded: timeout, process-tree kill, output truncation, full-output escape hatch, optional context exclusion. Lara should treat shell as a managed backend for commands and verification, not as the primary file API.

**Lazy skills and project rules** — Pi advertises skills by name, description, and path, then loads the full content only when relevant. Lara should use this pattern for BLB coding rules, modules, and agent guides to avoid prompt bloat.

**Compaction is explicit and structured** — Pi compacts near context limits, preserves recent turns, never cuts tool-call/result pairs, and summarizes goal, constraints, progress, decisions, next steps, and files touched. Lara should use this as the default continuation and overflow strategy.

**Budgets are enforced at tool/context boundaries** — Pi's practical limits are mostly tool-output and context-window limits. Lara should add those plus run-level counters for search/read/shell/edit attempts, because in-product runs need visible cost and control-plane accountability.

## Design Decisions

**Build the coding harness first.** Lara's next architectural milestone is not BLB-specific knowledge; it is a general coding-agent harness with a clean loop, tool protocol, context pipeline, edit model, budgets, compaction, and continuation state. BLB-specific proficiency follows because Lara runs inside this repo with BLB's instructions, source tree, route context, and tests.

**Keep Lara; do not replace her with Pi.** Pi and similar open-source agents solve a narrower problem: a disciplined coding loop in a development workspace. Lara must also preserve BLB authz, page context, auditability, control-plane visibility, target-surface policy, and in-product user experience. Replacing Lara would recreate those boundaries around another agent.

**Borrow minimal harness mechanics aggressively.** Pi's useful lessons are not product-specific: pure loop plus stateful harness, turn-boundary hooks, bounded read/edit/write tools, exact-text editing, context transformation before LLM calls, structured compaction, tool-level prompt guidance, lazy skills, and explicit limits. Lara should adopt these mechanics where they improve the resident-agent harness.

**Prefer general harness policy over task heuristics.** The eBay settings miss is evidence that Lara lacks source localization, edit-surface discipline, and low-blast-radius defaults. The correction belongs in the harness and tool policy, not in page-specific prompt rules.

**Expose structured file APIs before shell.** Shell remains necessary for tests, git inspection, generated output, and unusual repository work. The harness should not rely on prose telling the model what is “best”; it should expose bounded, typed, resumable, diff-producing file operations first and let the model choose from clear capabilities.

**Use soft checkpoints before hard stops.** Budgets should prevent runaway exploration without making Lara brittle. When a budget is crossed, Lara should summarize what is known, name the uncertainty, and ask for or choose a narrower next step depending on risk.

**Benchmark before considering external execution.** External agents may remain valuable as references, benchmarks, or optional patch-producing backends. They should not bypass BLB's authz, audit, target-surface, and verification boundaries.

## Public Contract

- Lara remains the in-product resident agent for BLB coding and operation work.
- Repository work exposes structured, bounded tools first rather than relying on prompt advice to avoid shell exploration.
- Browser testing is possible from any page where Lara is present; expected outcomes are observable through Lara chat progress, run status, control-plane run details, and the affected product page when a task changes UI.
- Lara localizes source ownership before editing and prefers the lowest sufficient blast-radius file.
- Shared framework component edits require an explicit reason and should not be the default response to a module-specific request.
- Continuation resumes from durable task state, including candidate files, current phase, and next step.
- Budget pressure is visible and produces a checkpoint instead of uncontrolled token burn.
- External coding agents are allowed as comparison or future backends only behind Lara's BLB-owned policy, audit, and verification boundary.

## Phases

### Phase 1 — Make structured repository tools the default

Affected pages: Any BLB page with Lara chat available; Control Plane run detail/status pages.
Goal: ordinary coding tasks start with bounded search/read/edit primitives instead of shell output streams.
Evidence: A browser-started simple coding prompt shows structured repo tools in the run trace before shell; shell appears only for verification or justified escalation.

- [x] Audit Lara's current default tool allowlist and prompt snippets for repository work. {Amp/GPT-5}
- [x] Promote structured search, read, and edit tools into Lara's default coding surface, preserve profile ordering when exposing tools to the model, and include symbolic repository-root context in Lara runtime context. {Amp/GPT-5}
- [x] Expose structured file APIs before shell/browser and keep tool descriptions neutral rather than prescriptive. {Amp/GPT-5}
- [x] Add model-facing tool descriptions that include hard output limits and exact continuation instructions for bounded repository reads. {Amp/GPT-5}
- [x] Add tests proving Lara's default interactive surface includes structured repo tools before shell/browser and that authz still filters unauthorized users. {Amp/GPT-5}

### Phase 2 — Add the minimal coding loop

Affected pages: Any BLB page with Lara chat available; Control Plane run detail/status pages.
Goal: Lara follows a general localize → read → patch → verify → summarize loop for repository changes.
Evidence: Run traces expose the current phase and show source localization before edits unless the user supplied an exact file.

- [ ] Define the repository-work phase model: localization, focused read, edit plan, patch, verification, summary.
- [ ] Add harness guidance or runtime state so Lara records the current phase and expected next action.
- [ ] Require source localization before broad file reads or edits unless the user named an exact file.
- [ ] Require an edit plan that names target files and why they are the source of truth before applying changes.
- [ ] Add post-edit diff validation that compares touched files against the edit plan and flags unexpected shared/core files.

### Phase 3 — Add context and tool budgets

Affected pages: Any BLB page with Lara chat available; Control Plane run detail/status pages.
Goal: Lara checkpoints before runaway exploration or excessive token burn.
Evidence: A deliberately ambiguous prompt crosses a soft budget and produces a visible checkpoint with known facts, candidate files, uncertainty, and next action instead of continuing silent exploration.

- [ ] Track per-run counts for search calls, files read, read lines/bytes, shell calls, edit attempts, and estimated context tokens.
- [ ] Set initial soft budgets for simple coding tasks, with higher budgets available for explicitly large tasks.
- [ ] Emit budget-pressure events into the run ledger/control plane when a threshold is crossed.
- [ ] Add a checkpoint response shape: known facts, candidate files, ruled-out paths, uncertainty, recommended next step.
- [ ] Add tests for budget-triggered checkpoint behavior without cancelling the run.

### Phase 4 — Build the BLB source map

Affected pages: Any routed BLB page with Lara chat available; settings pages such as `commerce/marketplace/ebay/settings`; Control Plane run detail/status pages.
Goal: Lara can map product/page context to likely source-of-truth files before exploring broadly.
Evidence: From a browser page, Lara's run trace lists ordered source-map candidates with confidence and ownership before opening files.

- [ ] Map routes to controllers, Livewire components, view names, module ownership, and route names.
- [ ] Map Livewire components to class files, Blade views, module roots, and config providers.
- [ ] Map settings pages/groups to their declarative settings config files and shared renderer paths.
- [ ] Map current browser page context into a compact source-map payload for Lara's prompt/runtime context.
- [ ] Include confidence and candidate ordering so Lara can verify rather than blindly trust the map.

### Phase 5 — Add blast-radius classification

Affected pages: Any BLB page with Lara chat available; module pages with local settings/config; Control Plane run detail/status pages.
Goal: Lara chooses the smallest sufficient edit surface and treats shared framework edits as high-impact.
Evidence: A module-specific browser prompt prefers local/config/module files when sufficient, and shared/core edits appear only with explicit justification in the run trace.

- [ ] Define file-scope classes: local config, module view, module class, shared renderer, shared component, framework infrastructure, generated/vendor.
- [ ] Add a classifier service for repo paths based on module roots, resources/core, app/Base, app/Modules/Core, extension roots, vendor/generated paths, and config ownership.
- [ ] Inject classifier results into source-map candidates and edit-plan validation.
- [ ] Require explicit justification before editing shared components or framework infrastructure for a module-specific task.
- [ ] Add regression tasks proving local/config edits are preferred over shared-component edits when both are plausible.

### Phase 6 — Persist resumable task state

Affected pages: Any BLB page with Lara chat available across multiple browser sessions; Control Plane run detail/status pages.
Goal: “Continue” resumes the task's cognitive state, not just the transcript.
Evidence: Start a task in one browser, interrupt after localization or patching, continue from another browser, and observe the same phase/candidate/edit/verification state before Lara proceeds.

- [ ] Define a compact task-state payload on or alongside `AiRun` / session metadata.
- [ ] Persist current task, phase, target surface, candidate files, ruled-out files, selected plan, diff summary, verification status, and budget counters.
- [ ] Update continuation prompts to load and summarize this task state before taking new action.
- [ ] Ensure browser handoff and run replay surface the same task-state summary in the control plane.
- [ ] Add tests simulating interruption after localization and after patching, then resuming from the persisted next step.

### Phase 7 — Add compaction and context transformation

Affected pages: Any BLB page with Lara chat available during long runs; Control Plane run detail/status pages.
Goal: Lara preserves useful task knowledge without repeatedly replaying massive raw tool output.
Evidence: A long run shows compaction summaries in replay/continuation, preserves recent turns and tool-call/result pairs, and resumes with the same goal, constraints, decisions, files, and next step.

- [ ] Split context preparation into transform-context and convert-to-LLM stages.
- [ ] Add structured compaction when context approaches the model window: goal, progress, critical context, decisions, touched files, and next steps.
- [ ] Keep recent messages and never cut between a tool call and its result.
- [ ] Cap compaction output so the summary itself cannot overflow the reserved context window.
- [ ] Store compaction summaries as durable synthetic session/task messages for replay and continuation.

### Phase 8 — Evaluate against external coding agents

Affected pages: Benchmark-selected BLB pages, including at least one page-only browser task and one settings/config task; Control Plane benchmark/run detail pages if available.
Goal: know whether Lara's harness is competitive and where external agents still outperform it.
Evidence: Benchmark records compare Lara with selected external agents on target-file accuracy, token use, tool calls, shell calls, diff size, verification, elapsed time, and interruption recovery.

- [ ] Build a small benchmark suite of BLB coding tasks: config field edit, Livewire label change, validation rule, Blade tweak, module route update, tiny test addition, and browser-observed UI change.
- [ ] Record metrics: target-file accuracy, token use, tool calls, shell calls, diff size, verification, elapsed time, and interruption recovery.
- [ ] Run the same tasks through Lara and selected external agents in sandbox branches or throwaway worktrees.
- [ ] Decide whether external agents should remain references only or become optional scoped patch-producing backends behind Lara.
- [ ] Update this plan and the Lara architecture docs only after measured evidence changes the recommendation.

## Open Questions

- Where should durable task state live: `ai_runs.runtime_meta`, session metadata, a dedicated table, or a compact event stream extension?
- Should budget thresholds vary by task profile, model, page context, or user-selected mode?
- Which structured repo tools are mature enough to replace shell as the default immediately, and which need output-limit/pagination improvements first?
- How should Lara ask for confirmation when a shared/core edit is justified but the user requested a module-local change?
