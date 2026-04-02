# Phase 5 - Orchestration & Extension Kernel Build Sheet

**Parent:** `docs/todo/openclaw-parity/00-capability-gap-audit.md`  
**Scope:** Turn BLB's current delegation and Lara-specific shortcuts into a general multi-agent orchestration and extension kernel  
**Status:** Complete  
**Phase Owner:** Core AI / Base AI  
**Last Updated:** 2026-04-02

---

## 1. Problem Essence

Phase 5 should not be implemented as "make `delegate_task` smarter"; it should be implemented as an **orchestration and extension kernel** that lets BLB route work across agents, spawn bounded child sessions, package reusable skills, and extend runtime behavior through stable hook points.

---

## 2. Why the Current Phase 5 Description Is Too Thin

The current Phase 5 in `00-capability-gap-audit.md` says:

1. routing rules
2. subagent/session-spawn primitives
3. skill packs
4. runtime hooks

That is directionally correct, but it is still a feature checklist.

If implemented too literally, the likely outcome is:

- routing logic gets bolted onto `LaraCapabilityMatcher`
- child-session behavior gets buried inside `DelegateTaskTool` or `RunAgentTaskJob`
- skill packs become prompt snippets or tool groups with no contract
- runtime hooks become scattered callback points inside `AgenticRuntime`
- Lara remains special while every other agent stays second-class

That would increase surface area without giving BLB a real orchestration substrate.

BLB should instead build a deep module with clear boundaries:

- routing and selection
- child-session lifecycle
- capability and skill packaging
- runtime extension hooks
- policy and lineage

---

## 3. Current Code Snapshot

The current implementation has real delegation foundations, but not yet a multi-agent kernel.

### What exists now

- `AgentListTool` lists delegable agents visible to the current user, using a concise capability summary built from employee designation and job description: `app/Modules/Core/AI/Tools/AgentListTool.php`, `app/Modules/Core/AI/Services/LaraCapabilityMatcher.php`
- `DelegateTaskTool`, `LaraTaskDispatcher`, `AgentTaskDispatch`, and `RunAgentTaskJob` provide a durable asynchronous delegation path to another agent through Laravel queues: `app/Modules/Core/AI/Tools/DelegateTaskTool.php`, `app/Modules/Core/AI/Services/LaraTaskDispatcher.php`, `app/Modules/Core/AI/Models/AgentTaskDispatch.php`, `app/Modules/Core/AI/Jobs/RunAgentTaskJob.php`
- `LaraCapabilityMatcher` can auto-select an agent, but matching is currently based on simple keyword overlap against a free-text capability summary rather than explicit task contracts or routing policy: `app/Modules/Core/AI/Services/LaraCapabilityMatcher.php`
- `LaraOrchestrationService` provides four Lara-only slash commands (`/go`, `/models`, `/guide`, `/delegate`) as a deterministic shortcut path outside the main agent runtime: `app/Modules/Core/AI/Services/LaraOrchestrationService.php`
- `LaraPromptFactory` injects available-agent context and supports an append-only external prompt extension file, which is a useful seam but not yet a full skill-pack or plugin model: `app/Modules/Core/AI/Services/LaraPromptFactory.php`
- `AgenticRuntime` already has a strong tool-calling loop, but it has no formal hook stages, no child-run/session protocol, and no first-class orchestration contracts beyond tool execution: `app/Modules/Core/AI/Services/AgenticRuntime.php`
- `ServiceProvider` manually registers a fixed tool list, which is reliable for core tools but not yet an extension mechanism for skill packs or orchestration plugins: `app/Modules/Core/AI/ServiceProvider.php`
- `SessionManager` and `MessageManager` provide per-agent chat sessions and transcripts, but they do not model parent/child session lineage, spawn provenance, or cross-agent orchestration state: `app/Modules/Core/AI/Services/SessionManager.php`, `app/Modules/Core/AI/Services/MessageManager.php`
- `GuideTool` and `KnowledgeNavigator` provide one curated reference surface, showing BLB already has the beginnings of reusable contextual augmentation even though it is not yet packaged as a general skill system: `app/Modules/Core/AI/Tools/GuideTool.php`

### What is missing or still too narrow

1. **Orchestration is mostly Lara-specific rather than runtime-generic.**
2. **Routing is heuristic and summary-based, not contract-based.**
3. **Delegation creates background tasks, not true child sessions with lineage.**
4. **Extension points are limited to prompt appendages and hardcoded registrations.**
5. **There is no runtime hook system for pre-run, pre-tool, post-tool, or post-run augmentation.**

---

## 4. From-Scratch Design: What BLB Should Build Instead

### 4.1 Public interface first

Phase 5 should expose these stable operations:

1. `routeTask(request, policy)` — choose the right target agent, skill, or local execution path
2. `spawnAgentSession(agentId, envelope, options)` — create a bounded child session with explicit parent lineage
3. `resumeAgentSession(sessionId, message)` — continue a spawned child session or hand back follow-up work
4. `registerSkillPack(pack)` — make a reusable skill package available to the orchestration layer
5. `resolveSkillPacks(agentId, context)` — compute which skill packs apply for an agent and task
6. `runRuntimeHooks(stage, payload)` — execute registered extension hooks at defined lifecycle stages
7. `getOrchestrationStatus(operationId)` — inspect routing, child-session, and delegation progress coherently

Tools and slash commands should wrap these operations. They should not be the primary owners of orchestration behavior.

### 4.2 Architectural decomposition

#### A. Task Routing Engine

Responsibility:

- decides whether work stays local, routes to another agent, or uses a specialized skill
- evaluates capability contracts, policy, and explicit constraints
- returns a deterministic routing decision with reasons

Key invariant:

- routing is a first-class service, not a side effect of a tool call

#### B. Session Spawn Manager

Responsibility:

- creates child sessions with explicit parent run/session lineage
- sets scope, prompt context, and execution boundaries for child work
- supports one-shot or interactive follow-up continuation

Key invariant:

- child work is represented as a sessionful execution unit, not only as a queue receipt

#### C. Capability Catalog

Responsibility:

- describes agent capabilities in structured form
- separates discoverability data from free-text bios
- feeds routing, UI discovery, and supervision policy

Key invariant:

- routing should depend on explicit capability contracts before it depends on keyword coincidence

#### D. Skill Pack Registry

Responsibility:

- packages reusable prompts, tools, references, and policies into named bundles
- resolves which bundles apply for which agents or tasks
- keeps extension content structured and auditable

Key invariant:

- a skill pack is a contract, not just a prompt fragment

#### E. Runtime Hook Bus

Responsibility:

- provides stable hook stages around runtime execution
- lets extensions augment context, tools, or post-run processing without patching the core loop
- constrains side effects and ordering

Key invariant:

- runtime extension happens through declared stages, not hidden conditionals

#### F. Orchestration Policy Service

Responsibility:

- enforces who may supervise whom
- limits which agents may spawn which child sessions
- governs skill-pack applicability and hook safety

Key invariant:

- orchestration freedom is policy-bounded, not implicit

---

## 5. Core Design Decisions

### 5.1 Make orchestration runtime-generic, not Lara-only

`LaraOrchestrationService` is useful, but it is a user-facing veneer over a few hardcoded commands.

From scratch, BLB should make orchestration capabilities available at the runtime layer first, then optionally expose Lara-specific shortcuts on top.

### 5.2 Route on structured capabilities, not biographies

Today, delegation matching is mostly based on designation/job-description summaries and keyword overlap.

That is a workable bootstrap, but it is not a strong long-term routing model.

Phase 5 should move toward structured capability descriptors such as:

- domains
- supported task types
- tool access profile
- human-review requirements
- latency or trust constraints

### 5.3 Separate delegation receipt from child-session lifecycle

An async dispatch record is useful, but it is not the same thing as a child session.

BLB should distinguish between:

- **dispatch** — queued/running async work record
- **child session** — a bounded conversation or execution context owned by another agent

Sometimes they will map one-to-one. Sometimes they will not. The model should leave room for both.

### 5.4 Skill packs should package more than prompt text

The existing prompt extension seam is valuable, but a real skill system should be able to bundle:

- prompt sections
- tools or tool policies
- reference corpora
- validation or readiness checks
- optional hook registrations

That gives BLB a reusable extension contract instead of more prompt files.

### 5.5 Hooks must be phase-bounded and boring

Runtime hooks are powerful, so they should be explicit and small.

Good stages might include:

- pre-context-build
- pre-tool-registry
- pre-llm-call
- post-tool-result
- post-run

What BLB should avoid is a free-form callback system where any extension can mutate everything at any time.

### 5.6 Preserve lineage explicitly

Once Phase 5 introduces child sessions, BLB should be able to answer:

- which parent run spawned this child
- which agent owns the child session
- what scope/instructions the child was given
- how the result flowed back

Lineage should be durable, not reconstructed from logs later.

### 5.7 Keep explicit commands as a veneer, not the substrate

Slash commands like `/guide` and `/delegate` are useful operator ergonomics.

They should remain thin entrypoints over general orchestration services rather than the only place where orchestration exists.

---

## 6. Proposed Module Shape

Recommended service set:

- `TaskRoutingService`
- `AgentCapabilityCatalog`
- `SessionSpawnManager`
- `OrchestrationSessionRepository`
- `SkillPackRegistry`
- `SkillContextResolver`
- `RuntimeHookRegistry`
- `RuntimeHookRunner`
- `OrchestrationPolicyService`

Recommended job/command set:

- `SpawnAgentSessionJob`
- `ResumeAgentSessionJob`
- `blb:ai:orchestration:status {id}`
- `blb:ai:skills:list`
- `blb:ai:skills:verify {pack}`

Recommended tool and UI evolution:

- `AgentListTool` becomes a thin view over the capability catalog
- `DelegateTaskTool` becomes one orchestration entrypoint, not the orchestration model
- Lara slash commands continue as convenience syntax over runtime services
- future orchestration UI can show routed work, child sessions, and skill applicability explicitly

---

## 7. Storage and Persistence Model

### 7.1 Capability records

BLB needs more than a free-text capability summary.

Suggested capability shape:

- employee ID
- capability domains
- supported task types
- declared specialties
- supervision constraints
- status/availability
- optional confidence or maturity metadata

### 7.2 Orchestration session records

If child sessions exist, they need durable lineage and scope.

Suggested fields:

- orchestration session ID
- parent session ID
- parent run or dispatch ID
- owning employee ID
- spawned employee ID
- spawn reason / task envelope
- current status
- result handoff metadata
- created/updated timestamps

### 7.3 Skill pack manifests

Skill packs should be declared explicitly.

Suggested manifest fields:

- pack ID
- version
- owner/module
- applicable agents or roles
- prompt resources
- tool bindings
- references
- readiness checks
- hook registrations

### 7.4 Hook registrations

Hooks need stable metadata even if registration is code-driven.

Suggested fields or manifest data:

- hook ID
- stage
- provider/module
- priority
- side-effect policy
- enabled state

---

## 8. Main Execution Flows

### 8.1 Auto-routing flow

1. caller submits a task request
2. `TaskRoutingService` loads structured capabilities and policy
3. router decides local execution, delegated execution, or skill-mediated execution
4. routing decision is persisted with reasons
5. resulting child session or dispatch is created

### 8.2 Child-session spawn flow

1. parent run decides to offload work
2. `SessionSpawnManager` creates child session scope and lineage
3. child agent receives bounded instructions and context
4. child session executes independently
5. result is attached back to the parent orchestration record

### 8.3 Skill-pack resolution flow

1. runtime begins building context for an agent/task
2. `SkillPackRegistry` resolves applicable packs
3. prompt/resources/tools are merged through a defined contract
4. readiness and policy checks run
5. runtime proceeds with a clear record of which packs were active

### 8.4 Runtime-hook flow

1. runtime enters a declared stage
2. `RuntimeHookRunner` loads hooks for that stage
3. hooks execute in deterministic order with a bounded payload
4. returned augmentations are merged under explicit rules
5. runtime continues without hidden global mutations

---

## 9. Build Plan

### Phase 5 status board

| Workstream | Goal | Status | Notes |
|---|---|---|---|
| 5.1 | Define orchestration core contracts | **Complete** | Enums, DTOs, RuntimeHook contract, OrchestrationSession model+migration, OrchestrationPolicyService, OperationType.ChildSession |
| 5.2 | Replace summary-only routing with capability catalog | **Complete** | AgentCapabilityCatalog, TaskRoutingService, OrchestrationPolicyService created and tested (36 tests) |
| 5.3 | Add child-session lineage and spawn mechanics | **Complete** | SessionSpawnManager, SpawnAgentSessionJob, AgentExecutionContext lineage fields, domain exceptions, 10 tests |
| 5.4 | Introduce skill-pack registry | **Complete** | SkillPackRegistry, SkillContextResolver, SkillResolution DTO, KnowledgeSkillPack proof, 32 tests |
| 5.5 | Add runtime hook stages | **Complete** | RuntimeHookRegistry, RuntimeHookRunner, HookRunResult created. Integrated at all 5 stages in AgenticRuntime (sync+streaming). 22 tests |
| 5.6 | Move Lara shortcuts onto the new substrate | **Complete** | DelegateTaskTool, AgentListTool, LaraOrchestrationService, LaraTaskDispatcher, LaraPromptFactory rebased on orchestration services. All existing tests updated. After-coding alignment review verified no stale LaraCapabilityMatcher direct dependencies remain. 1176 tests pass (3168 assertions) |

### 9.1 Stage A - Core contracts and policy

Sub-todos:

1. define canonical routing request/decision contracts
2. define child-session and lineage records
3. define skill-pack manifest format
4. define allowed runtime hook stages and payload shapes
5. write policy boundaries before implementing extension power

Exit condition:

- Phase 5 has stable core contracts that tools and UI can build on

### 9.2 Stage B - Capability catalog

Sub-todos:

1. model structured agent capabilities
2. migrate `AgentListTool` and routing inputs to the catalog
3. keep free-text summaries as display fields, not routing truth
4. define fallback behavior while some agents still have sparse capability data

### 9.3 Stage C - Child-session primitives

Sub-todos:

1. create orchestration session storage and lineage links
2. define spawn envelope and bounded context rules
3. allow a parent run to spawn a child session explicitly
4. support result handoff back to the parent
5. decide whether first release is one-shot or interactive continuation

### 9.4 Stage D - Skill-pack system

Sub-todos:

1. define pack manifest and loading rules
2. support prompt/resource/tool bundling
3. add readiness verification for packs
4. make pack resolution visible in runtime metadata
5. prove the abstraction with one real pack before widening

### 9.5 Stage E - Runtime hook bus

Sub-todos:

1. add small, named hook stages around runtime execution
2. define merge semantics and ordering
3. enforce side-effect boundaries
4. make hook participation visible in run metadata
5. keep the core runtime readable after hook integration

### 9.6 Stage F - Lara and tool integration

Sub-todos:

1. rebase `DelegateTaskTool` on the orchestration core — **Done**: constructor takes `LaraTaskDispatcher`, `TaskRoutingService`, `AgentExecutionContext`; uses `routeTask()` via `TaskRoutingService::route()` returning `RoutingDecision`
2. rebase `AgentListTool` on `AgentCapabilityCatalog` — **Done**: uses `delegableDescriptorsForCurrentUser()` returning `AgentCapabilityDescriptor` objects; richer output with domains and task types
3. rebase `LaraOrchestrationService` `/delegate` on `TaskRoutingService` — **Done**: `routeAndDispatchDelegation()` creates `RoutingRequest` and uses `TaskRoutingService::route()`
4. rebase `LaraTaskDispatcher` on `AgentCapabilityCatalog` — **Done**: uses `catalog->descriptorFor()` for agent validation instead of `findAccessibleAgentById()`
5. update existing tests — **Done**: `DelegateTaskToolTest.php` (14 tests), `AgentListToolTest.php` (9 tests), `ToolCallingTest.php` (6 tests updated to new constructor), `AgenticRuntimeTest.php` (7 tests fixed — added `RuntimeHookRunner` 8th arg), `LaraPromptAndOrchestrationTest.php` (2 tests fixed — updated assertion keys)
6. fix missing imports — **Done**: `AgenticRuntime.php` (added `RuntimeHookRunner`, `HookStage`), `LaraOrchestrationService.php` (added `RoutingRequest`, `Employee`, `RoutingTarget`)
7. `LaraCapabilityMatcher` is now a leaf dependency used ONLY by `AgentCapabilityCatalog` for access-control checks — no longer directly used by tools or orchestration services
8. rebase `LaraPromptFactory` on `AgentCapabilityCatalog` — **Done** (alignment review): constructor takes `AgentCapabilityCatalog` instead of `LaraCapabilityMatcher`; `LaraPromptFactoryExceptionTest` updated to mock `AgentCapabilityCatalog`

---

## 10. Scope-Sharpening Notes

These are the decisions most likely to sharpen during implementation:

### 10.1 Should BLB refactor `LaraCapabilityMatcher` early?

My bias: yes.

It is a good bootstrap helper, but Phase 5 should not grow a full orchestration system around keyword overlap on free-text summaries. Promote it into a temporary input to a richer `AgentCapabilityCatalog`.

### 10.2 What is the first child-session shape?

The simplest first step is:

- spawn child
- child executes one bounded task
- parent receives a result

That is enough to establish lineage and lifecycle before supporting fully interactive multi-turn child sessions.

### 10.3 What counts as a skill pack in BLB?

My recommendation:

- at least one prompt/resource bundle
- optional tool bindings
- optional references
- optional hooks
- readiness checks

If a pack is only a prompt fragment, it is not yet pulling its weight as a first-class abstraction.

### 10.4 How much hook power is acceptable?

Hooks are useful only if they stay understandable.

Prefer:

- small number of stages
- immutable input plus explicit returned augmentations
- deterministic ordering

Avoid:

- arbitrary mutation of runtime globals
- hooks that can silently suppress core behavior

### 10.5 Should slash commands remain Lara-only?

As UX affordances, yes for now.

As the underlying orchestration mechanism, no. The substrate should be agent-generic even if Lara is the first UI to expose it.

---

## 11. Exit Criteria

Phase 5 is complete when:

- routing is based on structured capability contracts and policy, not only keyword summaries
- BLB can spawn a child agent execution context with durable lineage
- at least one real skill pack is packaged and resolved through a manifest-backed contract
- runtime extension hooks exist at defined stages without making `AgenticRuntime` opaque
- Lara shortcuts and tools use the shared orchestration substrate rather than bespoke logic

---

## 12. Anti-Patterns to Avoid

- do not bury routing policy inside `DelegateTaskTool` or `LaraOrchestrationService`
- do not treat queue dispatch receipts as a substitute for child-session modeling
- do not build a skill-pack system that is just "more prompt text files"
- do not add generic hooks everywhere until the runtime becomes impossible to reason about
- do not keep orchestration as a Lara-only feature if Phase 5 is meant to be framework capability
- do not let capability matching remain permanently dependent on designation/job-description keyword overlap

