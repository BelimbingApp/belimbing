# Lara — Belimbing System Coding Agent

**Document Type:** Architecture Statement
**Status:** Active
**Last Updated:** 2026-05-06
**Related:** `docs/architecture/ai/agent-model.md`, `docs/architecture/ai/current-state.md`, `docs/Base/AI/tool-framework.md`, `docs/architecture/user-employee-company.md`

---

## 1. Essence

Belimbing is designed to become software that can help build, operate, and extend itself. Lara is the resident AI that makes that direction concrete.

Lara is Belimbing's system coding agent. She answers from the current system, operates through the product, uses the repository as source truth, and changes the repository when the logged-in user's authority allows it.

Her job is to help the user read, reason, act, verify, and explain. Every meaningful action remains governed by Belimbing's authorization, audit, and environment rules.

---

## 2. Position

Lara is a fixed system agent, not a user-created employee. She exists in every Belimbing installation as part of the framework identity.

She is represented as an `Employee` so she participates in the same identity, permission, audit, and delegation model as humans and other agents. Her role, visual identity, and base prompt are framework-managed.

Core properties:

- globally available to authenticated users,
- isolated session per user,
- acts for the current logged-in user,
- broad capabilities assigned through normal authz and environment policy,
- shared agent-generic tool framework.

---

## 3. Authority

Lara's application authority is the same as the logged-in user she is helping.

If the user cannot access Sales, approve a workflow transition, read a record, edit a page, run an operation, or view a report, Lara cannot do it for that user.

Effective authority is the intersection of:

- current user's permissions,
- Lara's assigned agent capabilities,
- environment policy,
- tool guardrails,
- audit and confirmation requirements.

Capability boundaries must separate file read/write, shell, Artisan, data read/write, and production access. Explicit delegation, if introduced, must be modeled in authz.

---

## 4. Capability Model

Lara is open-ended. Her scope is limited by authz, environment policy, available tools and skills, and model capability, not by task category.

Core capabilities:

- **Source understanding** — inspect docs and source; answer from current evidence.
- **Repository change** — edit code, docs, tests, config, and fixtures; inspect diffs; run focused verification.
- **Runtime operation** — inspect permitted state, diagnose setup, run safe commands, and help with daily workflows.
- **Computer use** — when the model supports it, operate user interfaces by viewing, clicking, typing, and navigating like a human.
- **Delegation** — use another agent, skill, or task profile when materially better than direct execution.

Repository read/write is first-class and separately governable. It is not merely shell access.

Coding work has an explicit target surface:

- `core` — BLB framework code, changed through the upstream BLB repository.
- `extension:<slug>` — licensee or third-party extension code, changed through that extension's own repository and origin.

Lara may reason about both, but repository tools must enforce the chosen surface. A core task must not write into an extension by accident, and an extension task must not leak licensee code into upstream BLB. Cross-surface work should be split into separate changes.

There is no architectural ceiling beyond authz, environment policy, tool availability, skill availability, and model capability.

---

## 5. Harness

Lara's harness should stay small. It connects a capable model to Belimbing safely; it should not become a second reasoning engine.

The harness owns:

- **Identity** — role, base prompt, visual identity, system status.
- **Context** — current user, active page, session, modules, docs/source retrieval, runtime facts, safe memory.
- **Tools and skills** — core tools plus user-defined procedures and workflows.
- **Policy** — authz checks, environment restrictions, confirmations, secret redaction, audit logs.
- **Persistence** — sessions, transcripts, tool traces, task outputs, useful memory.

Avoid hardcoded planning trees, brittle intent classifiers, scripted ordinary coding workflows, and large Lara-specific orchestration layers. Durable structure belongs in product behavior, security boundaries, domain contracts, or reusable tooling.

---

## 6. Tools And Knowledge

Lara uses the shared agent tool framework. Tools are agent-generic; access is policy-driven.

Core tool categories:

| Category | Purpose |
|---|---|
| Repository | read, search, edit |
| Verification | tests, linters, formatters, diagnostics |
| Application | Artisan, config, permitted data |
| Shell | shell or equivalent command-line tools |
| Browser/UI | Belimbing and other software interfaces |
| Knowledge | docs, plans, compiled knowledge, licensee overlay |
| Communication | explain, file follow-ups, delegate |

Tool execution must provide schemas, capability-filtered availability, defense-in-depth checks, structured results, auditable traces, and safe failures.

Repository tools are rooted at the project root, exclude secrets and generated noise by default, record before/after state for writes, and rely on the configured shell backend and git for repository inspection such as diffs.

Skills are ownership-scoped:

- BLB core skills live in `.agents/skills/` and version with upstream BLB.
- Extension skills live in the extension's own `.agents/skills/` and version with that extension.

Knowledge should prefer current source and runtime evidence over free-floating memory. Licensee overlay may add local terminology and policy, but must not replace core Lara rules.

---

## 7. Invariants

1. Lara is Belimbing's framework-managed system coding agent.
2. Lara acts with the same application authority as the logged-in user.
3. Lara can answer from and act on the current Belimbing system, including the repository where authorized.
4. Lara's harness stays thin: identity, context, tools, skills, policy, persistence.
5. Repository read/write is first-class and separately governable.
6. Coding tasks declare and enforce a target surface: `core` or `extension:<slug>`.
7. Skills are resolved from ownership-scoped roots.
8. The same tool infrastructure remains available to other agents.
9. Belimbing remains usable when Lara is unavailable.
