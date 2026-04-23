# TODO: Browser Session Identity Resolution

**Status:** Complete
**Priority:** High
**Related Area:** AI browser tooling / runtime actor context

## Problem Essence

BLB's browser session creation path currently risks attributing browser sessions to the wrong employee context. In particular, falling back to employee `#1` (for example, Lara's employee record) when the initiating user has no linked employee is not a correct general-purpose solution.

The deeper issue is that `ai_browser_sessions` currently assumes browser ownership is always an employee concern, while the rest of the AI runtime already distinguishes between:

- the agent/runtime identity that executes work
- the authenticated user the work is acting for
- the company scope in which the work runs

## Desired Outcome

BLB should create `ai_browser_sessions` only with an explicitly valid actor model that preserves foreign-key integrity **and** semantic correctness.

That means:

- no synthetic `0` IDs
- no silent fallback to an unrelated employee record
- no hidden impersonation of Lara or another employee just to satisfy database constraints
- a clear, documented policy for how browser sessions are attributed when the initiating principal is a user, an employee, Lara, or another delegated agent/runtime

## Public Contract

Browser session creation should follow an explicit identity contract:

1. Every browser session must record the runtime agent that owns the automation context.
2. If the run is user-attached, the browser session must also record the authenticated user the agent is acting for.
3. A user without an employee record is still a valid initiating principal; that must not force fake employee attribution.
4. Lara must remain a system agent identity, not a synthetic authenticated user account created just to satisfy browser-session schema.
5. Company scoping must also be resolved explicitly rather than guessed.
6. If the required agent or company context is missing, creation must fail with a clear error instead of inserting misleading foreign keys.

## Top-Level Components

### 1. Runtime Actor Resolution

Define how BLB resolves the initiating principal for browser actions:

- authenticated user
- linked employee
- Lara/system agent
- delegated/background agent context
- company scope

### 2. Browser Session Identity Model

Define what `ai_browser_sessions` is supposed to say about:

- the agent/runtime that owns the automation session
- the user the agent is acting for, when present
- the company scope

The current `employee_id` + `company_id` pair is not sufficient for all browser-session use cases.

### 3. Failure Policy

Define when browser session creation should fail fast versus when it may proceed with a trusted explicit system-owned context.

### 4. Schema/Architecture Follow-up

If the current schema cannot represent valid ownership for non-employee actors, adjust the model rather than forcing bad fallback behavior.

## Design Decisions

### Recommended Direction

We should **not** keep the current fallback to employee `#1`.

That fallback preserves database validity at the cost of data correctness, auditability, and future authorization clarity.

The better path is to treat this as an identity-model problem, not a browser-tool workaround.

We also should **not** give Lara a `users` row just to patch over this mismatch.

Lara already behaves as a system agent with user-attached execution captured separately elsewhere in the AI runtime (`acting_for_user_id` on runs, turns, dispatches, and orchestration records). Giving Lara a user account would conflate:

- authenticated principal
- runtime agent
- session presence

That would make audits and authorization less honest, not more correct.

### Working Assumption

The current schema reflects an earlier assumption that browser sessions are always employee-owned. That assumption is too narrow now that BLB has Lara, delegated agents, and authenticated users who may have company scope without any employee record.

### Canonical Model

Browser sessions should match the identity split already present in the rest of the AI runtime:

- `agent_employee_id` — the agent/runtime identity that owns the browser automation context
- `acting_for_user_id` — the authenticated user whose session/presence the run is attached to, when applicable
- `company_id` — the resolved company scope for policy and isolation

For Lara-driven user sessions, the correct attribution is:

- `agent_employee_id = Lara`
- `acting_for_user_id = current authenticated user`
- `company_id = resolved user company`

For delegated non-Lara agent runs on behalf of a user:

- `agent_employee_id = delegated agent`
- `acting_for_user_id = current authenticated user`
- `company_id = resolved company`

For autonomous background/system work with no user presence:

- `agent_employee_id = owning agent`
- `acting_for_user_id = null`
- `company_id = explicit system/company scope`

### Recommendation on Lara User Identity

Do not create a Lara user as the primary fix.

Reasons:

- `users` represent authenticated accounts and access-control principals, not runtime agents.
- Lara already exists as a durable system-agent identity through `employees.id = 1`.
- The runtime already models "agent acting for user" explicitly; browser sessions should align with that instead of inventing a fake login principal for Lara.
- A Lara user would still not solve the general case cleanly because non-employee external users would remain distinct from Lara's own synthetic account.

### Likely Directions To Evaluate

- Rename `employee_id` to `agent_employee_id` to make its meaning honest.
- Add nullable `acting_for_user_id` to `ai_browser_sessions`.
- Keep `company_id` required.
- Fail fast when agent or company context is missing.
- Reuse the same identity tuple across browser sessions, runs, chat turns, and orchestration records.

The recommendation is to implement this directly rather than keep exploring fallback variants. We do not need backward compatibility here.

## Phases

### Phase 1: Clarify Current Contract

- [x] Inspect `ai_browser_sessions` schema and document what ownership semantics it currently assumes.
- [x] Trace how `_employee_id` and `_company_id` are supposed to be injected into tool calls.
- [x] Document the current mismatch: browser sessions assume employee-only ownership, while other AI runtime records already carry `acting_for_user_id`.
- [x] Document the current browser-session call paths:
  - Browser tool synthetic runtime context (`_employee_id`, `_acting_for_user_id`, `_company_id`)
  - Authenticated user with linked employee
  - Authenticated user without linked employee, resolved to Lara as agent plus `acting_for_user_id`
  - Session reuse and persistence through the browser session repository/manager stack

### Phase 2: Choose Correct Ownership Model

- [x] Recommend one canonical ownership design for browser sessions.
- [x] Define how Lara/system-agent runs should be represented.
- [x] Define how user-without-employee cases should behave.
- [x] Define failure behavior for missing actor/company context.
- [x] Decide that Lara should remain an agent identity, not gain a synthetic user identity as a browser-session workaround.

### Phase 3: Implement and Verify

- [x] Replace `ai_browser_sessions.employee_id` with `agent_employee_id`.
- [x] Add nullable `acting_for_user_id` to `ai_browser_sessions`.
- [x] Replace misleading Lara employee attribution with explicit Lara-agent plus `acting_for_user_id` attribution.
- [x] Implement explicit browser-session identity resolution for agent, acting user, and company.
- [x] Verify the browser stack locally with focused unit coverage across migration-facing repository behavior, session reuse, operator DTOs, artifact ownership, and browser tool execution.
- [x] Update this todo doc to reflect the implemented identity model and browser-session behavior.

**Evidence**

- Implemented browser-session schema and runtime identity changes in:
  - `app/Modules/Core/AI/Database/Migrations/0200_02_01_000003_create_ai_browser_sessions_table.php`
  - `app/Modules/Core/AI/Models/BrowserSession.php`
  - `app/Modules/Core/AI/Services/Browser/BrowserSessionRepository.php`
  - `app/Modules/Core/AI/Services/Browser/BrowserSessionManager.php`
  - `app/Modules/Core/AI/DTO/BrowserSessionState.php`
  - `app/Modules/Core/AI/Tools/BrowserTool.php`
- Cleaned up adjacent Lara user-scope resolution in:
  - `app/Modules/Core/AI/Services/LaraContextProvider.php`
- Verified with focused tests:
  - `tests/Unit/Modules/Core/AI/DTO/BrowserSessionStateTest.php`
  - `tests/Unit/Modules/Core/AI/Console/Commands/BrowserStatusCommandTest.php`
  - `tests/Unit/Modules/Core/AI/Services/Browser/BrowserSessionRepositoryTest.php`
  - `tests/Unit/Modules/Core/AI/Services/Browser/BrowserSessionManagerTest.php`
  - `tests/Unit/Modules/Core/AI/Services/Browser/BrowserRuntimeAdapterTest.php`
  - `tests/Unit/Modules/Core/AI/Services/Browser/BrowserArtifactStoreTest.php`
  - `tests/Unit/Modules/Core/AI/Tools/BrowserToolTest.php`
  - `tests/Unit/Modules/Core/AI/Services/LaraContextProviderTest.php`

## Why This Matters

This is not just a local browser bug.

If BLB allows tools to silently adopt unrelated employee identities in order to satisfy schema constraints, we will corrupt attribution and make later authorization/debugging work much harder.

If BLB instead creates synthetic user accounts for runtime agents, we will blur the boundary between authentication and runtime execution and make the domain model less truthful.

The fix should preserve both:

- database correctness
- actor correctness

## Related Files

- `app/Modules/Core/AI/Tools/BrowserTool.php`
- `app/Modules/Core/AI/Services/Browser/BrowserContextFactory.php`
- `app/Modules/Core/AI/Models/BrowserSession.php`
- migration(s) for `ai_browser_sessions`
- tool/runtime context injection path
