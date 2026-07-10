# ai-lara-resident-coding-agent-gap.md

**Status:** In Progress
**Last Updated:** 2026-07-09
**Sources:** `docs/architecture/ai/lara.md`, `docs/architecture/ai/current-state.md`, `app/Modules/Core/AI/ServiceProvider.php`, `app/Modules/Core/AI/Services/AgentToolRegistry.php`, `app/Modules/Core/AI/Services/ChatTurnRunner.php`, `app/Modules/Core/AI/Services/SessionManager.php`, `app/Modules/Core/AI/Tools/EditTool.php`, `app/Modules/Core/AI/Tools/BrowserTool.php`, `resources/core/views/components/layouts/app.blade.php`
**Agents:** Codex/gpt-5.5-medium, Codex/GPT-5, Grok/Cursor

## Problem Essence

BLB already has a serious agent platform: Lara chat, per-user sessions, streaming turns, run ledger, control plane, browser automation, memory, orchestration, and many authz-gated tools. The gap is narrower now: make Lara's default operating model match the new architecture of a resident system coding agent with first-class repository work, user-defined skills, and authority equal to the logged-in user.

## Desired Outcome

Lara can answer from source, operate BLB, use computer/browser interfaces, and change the repository when authorized, with minimal harness code and clear auditability. Users without authority remain naturally gated by the same authz path that gates their own actions.

## Top-Level Components

| Component | Current State | Gap |
|---|---|---|
| Lara shell | Global chat exists in app layout/status bar with shortcuts, dock/overlay/fullscreen, direct streaming, persisted replay. | Mostly built; needs operator visibility for the default tool allowlist and denied tools. |
| Session isolation | `SessionManager` isolates Lara sessions by authenticated user path. | Built. Keep as invariant. |
| Tool registry | `AgentToolRegistry` filters tools by current user capabilities and the caller's allowed tool list. | Built. Lara now uses one minimal shell/browser default allowlist plus registered repo-specific capabilities. |
| Repository tools | Broad `read`, `search`, and target-surface aware `edit` exist. | Need before/after metadata, remote/origin enforcement, and stronger cross-surface workflow policy. |
| Browser/computer use | `browser`, `navigate`, `write_js`, active page context, screenshots/snapshots exist. | Need make computer-use path visible as part of Lara's normal capability set where enabled. |
| Skills | Orchestration kernel has `SkillPackRegistry`, hooks, `KnowledgeSkillPack`, and filesystem loading from core and extension `.agents/skills/`. | Need task-time filtering, validation UX, and admin/workspace lifecycle. |
| Coding surfaces | BLB has core code and extension code with different remotes and ownership; repo tools now accept `core` or `extension:<slug>`. | Need git remote/origin enforcement and cross-surface split workflow. |
| Runtime/audit | Run ledger, turn events, activity stream, hook actions, control plane exist. | Need repo tool traces to include before/after state and completion evidence. |

## Design Decisions

1. **Keep Lara's harness thin.** Reuse the existing runtime, tool registry, skill pack hooks, run ledger, browser subsystem, and chat shell. Do not add a Lara-only orchestration framework.
2. **Make repository work shell-first.** Use `bash` as Lara's default coding surface; keep `read`, `search`, and `edit` registered for explicit profiles and future agents that need structured repo/data work.
3. **Treat authz as the core boundary.** Lara acts as the logged-in user. Tool availability is the intersection of user capabilities, Lara's minimal allowlist, environment policy, and tool guardrails.
4. **Avoid chat profile sprawl.** Lara uses one minimal default allowlist in `ChatTurnRunner`; additional named chat profiles are YAGNI until a concrete product surface needs them.
5. **Use ownership-scoped skills.** BLB core skills live in `.agents/skills/`; extension skills live in each extension's own `.agents/skills/`.
6. **Do not rely on model inference for repository ownership.** Coding tasks must resolve a target surface (`core` or `extension:<slug>`), and repo tools must enforce the corresponding root and git remote.

## Public Contract

- Lara sessions stay per-user isolated.
- Lara never gets application access the logged-in user lacks.
- Authorized users can give Lara repository work without leaving chat.
- Repository writes produce auditable before/after evidence.
- Ownership-scoped skills can extend Lara's behavior without changing the core harness.
- Core changes target upstream BLB; extension changes target the owning extension origin.
- Browser/computer-use tools are available where configured, authorized, and model/tool support exists.

## Phases

### Phase 1 — Align Lara's Default Tool Surface

Goal: Lara's normal chat path exposes only the essential tools for her resident coding-agent role.

- [x] Add a minimal Lara default tool allowlist with shell and browser tools, filtered by current user authz. {Codex/GPT-5}
- [x] Remove the static chat profile ladder and the `ChatToolProfileRegistry` abstraction. {Codex/GPT-5}
- [x] Record selected tool allowlist/effective tools in runtime wire logs. {Codex/GPT-5}
- [ ] Add tests proving unauthorized users do not see or execute tools outside their capabilities even when the Lara profile includes them.
- [ ] Update tool catalog/workspace copy so operators can see which tools Lara can use and why denied tools are hidden or blocked.

### Phase 2 — First-Class Repository Tools

Goal: Replace "use bash for repo work" with auditable, structured tools.

- [x] Define a coding target-surface contract: `core` or `extension:<slug>`. {Codex/GPT-5}
- [x] Resolve target surface before repository reads and writes. {Codex/GPT-5}
- [ ] Add explicit user-visible ambiguity handling when the request crosses ownership boundaries.
- [x] Add `read` for safe project-root reads, guarded read-only data queries, denied paths, and size limits. {Codex/GPT-5}
- [x] Add `search` for path/content search with generated/vendor/secret exclusions. {Codex/GPT-5}
- [x] Keep repository diff inspection in `bash`/git instead of a separate `diff` tool. {Codex/GPT-5}
- [x] Add `edit` for file write/append/exact replacement and guarded data writes. {Codex/GPT-5}
- [x] Enforce target roots for repo tools. {Codex/GPT-5}
- [ ] Enforce git repository/origin routing for all repo tools: `core` uses upstream BLB; `extension:<slug>` uses that extension's own repository/origin.
- [ ] Split cross-surface changes into separate core and extension work items.
- [ ] Record before/after metadata for repo writes in tool results, run calls, and transcript activity.

### Phase 3 — User-Defined Skills

Goal: Let licensees and admins teach Lara durable procedures without expanding the core harness.

- [x] Use `.agents/skills/*/SKILL.md` as the initial minimal filesystem skill contract. {Codex/GPT-5}
- [x] Load BLB core skills from `.agents/skills/`. {Codex/GPT-5}
- [x] Discover extension skills from each active extension's `.agents/skills/`. {Codex/GPT-5}
- [x] Scope skill application by target surface so extension-specific skills do not bleed into core work. {Grok/Cursor} — progressive disclosure: catalog always; bodies only via page suggestions, skill-intent match, or `load_skill`
- [ ] Validate skills for readable errors: bad manifest, missing references, unsupported tool names, disabled tools.
- [x] Inject relevant skill guidance through existing `SkillContextResolver` / runtime hook flow. {Grok/Cursor} — `SkillSelectionService` + `SkillContextInjectionHook` + `load_skill`
- [ ] Add admin/operator visibility for installed skills and why a skill was or was not applied.

### Phase 4 — Computer-Use Readiness

Goal: Make the existing browser/UI substrate a normal Lara capability where configured.

- [x] Ensure Lara's resident profile can expose `browser`, `navigate`, active page snapshot, and `write_js` when authorized. {Codex/GPT-5}
- [ ] Surface browser readiness and missing setup directly in Lara chat/tool results.
- [ ] Confirm headful browser sessions can be watched by a user and linked from run/control-plane surfaces.
- [ ] Add tests for browser tool visibility under profile + authz + config combinations.

### Phase 5 — Production And Review Boundaries

Goal: Keep broad capability while making high-impact environments honest.

- [ ] Add environment policy for repository writes, shell, browser evaluation, and data writes in production.
- [ ] Add confirmation gates for high-impact repo or runtime actions when policy requires them.
- [ ] Make denied/prevented actions appear as first-class transcript/run events, reusing existing hook-action visibility.
- [ ] Add focused tests for production denial, confirmation-required flows, and audit records.

### Phase 6 — Documentation And Current-State Sync

Goal: Keep docs honest after implementation.

- [x] Update `docs/architecture/ai/current-state.md` with delivered repo tools, Lara profile, user skills, and remaining readiness gaps. {Codex/GPT-5}
- [ ] Update `docs/architecture/ai/lara.md` only if an implementation decision changes the architecture.
- [ ] Add operator-facing notes for enabling/disabling repo tools, browser tools, and user-defined skills.
