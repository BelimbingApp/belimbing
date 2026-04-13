# TODO: Browser Session Identity Resolution

**Status:** Identified
**Priority:** High
**Related Area:** AI browser tooling / runtime actor context

## Problem Essence

BLB's browser session creation path currently risks attributing browser sessions to the wrong employee context. In particular, falling back to employee `#1` (for example, Lara's employee record) when the initiating user has no linked employee is not a correct general-purpose solution.

## Desired Outcome

BLB should create `ai_browser_sessions` only with an explicitly valid actor model that preserves foreign-key integrity **and** semantic correctness.

That means:

- no synthetic `0` IDs
- no silent fallback to an unrelated employee record
- no hidden impersonation of Lara or another employee just to satisfy database constraints
- a clear, documented policy for how browser sessions are owned when the initiating principal is a user, an employee, Lara, or another delegated agent/runtime

## Public Contract

Browser session creation should follow an explicit ownership contract:

1. If a real employee actor is available, attribute the session to that employee.
2. If the action is being performed by Lara or another system agent, attribute it through an agent-safe model that is explicitly represented in data and policy.
3. If no valid ownership model is available, fail with a clear error instead of inserting misleading foreign keys.
4. Company scoping must also be resolved explicitly rather than guessed.

## Top-Level Components

### 1. Runtime Actor Resolution

Define how BLB resolves the initiating principal for browser actions:

- authenticated user
- linked employee
- Lara/system agent
- delegated/background agent context
- company scope

### 2. Browser Session Ownership Model

Define what `ai_browser_sessions.employee_id` and `company_id` are intended to mean, and whether they are sufficient for all browser-session use cases.

### 3. Failure Policy

Define when browser session creation should fail fast versus when it may proceed with a trusted explicit system-owned context.

### 4. Schema/Architecture Follow-up

If the current schema cannot represent valid ownership for non-employee actors, adjust the model rather than forcing bad fallback behavior.

## Design Decisions

### Recommended Direction

We should **not** keep the current fallback to employee `#1`.

That fallback preserves database validity at the cost of data correctness, auditability, and future authorization clarity.

The better path is to treat this as an actor-model problem, not a browser-tool workaround.

### Working Assumption

The current schema likely reflects an earlier assumption that browser sessions are always employee-owned. That assumption may be too narrow now that BLB has Lara, delegated agents, and tool execution outside a simple employee session model.

### Likely Directions To Evaluate

- Keep `employee_id` required, but ensure tool/runtime context always carries a real valid employee actor.
- Allow browser sessions to be owned by a broader actor abstraction instead of only `employees`.
- Introduce an explicit system-agent ownership path for Lara/runtime operations.
- Make `employee_id` nullable if session ownership can be represented another way without weakening audit trails.

The recommendation is to evaluate these in architectural terms, not patch locally around FK constraints.

## Phases

### Phase 1: Clarify Current Contract

- [ ] Inspect `ai_browser_sessions` schema and document what ownership semantics it currently assumes.
- [ ] Trace how `_employee_id` and `_company_id` are supposed to be injected into tool calls.
- [ ] Document all current browser-session call paths: UI run, chat/tool execution, delegated runs, queued/background execution.

### Phase 2: Choose Correct Ownership Model

- [ ] Recommend one canonical ownership design for browser sessions.
- [ ] Define how Lara/system-agent runs should be represented.
- [ ] Define how user-without-employee cases should behave.
- [ ] Define failure behavior for missing actor/company context.

### Phase 3: Implement and Verify

- [ ] Remove the employee `#1` fallback.
- [ ] Implement the chosen actor-resolution path.
- [ ] Verify FK integrity and audit correctness across all browser entry points.
- [ ] Update docs for browser runtime and tool execution context.

## Why This Matters

This is not just a local browser bug.

If BLB allows tools to silently adopt unrelated employee identities in order to satisfy schema constraints, we will corrupt attribution and make later authorization/debugging work much harder.

The fix should preserve both:

- database correctness
- actor correctness

## Related Files

- `app/Modules/Core/AI/Tools/BrowserTool.php`
- `app/Modules/Core/AI/Services/Browser/BrowserContextFactory.php`
- `app/Modules/Core/AI/Models/AiBrowserSession.php`
- migration(s) for `ai_browser_sessions`
- tool/runtime context injection path