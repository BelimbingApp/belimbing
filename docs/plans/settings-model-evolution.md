# settings-model-evolution

**Status:** In progress; definition registry plus AI, Performance, and timezone slices implemented
**Last Updated:** 2026-07-23
**Sources:** `docs/architecture/settings.md`; `docs/architecture/module-system.md`; `.env.example`; `config/app.php`; `config/mail.php`; `config/session.php`; `app/Base/AI/Config/ai.php`; `app/Base/Settings/`; `app/Base/DateTime/`; `app/Modules/Core/User/Models/User.php`; user discussion on environment ownership, runtime settings, and user preferences
**Agents:** Codex/GPT-5

## Problem Essence

Belimbing currently mixes database overrides, Laravel config, `.env`, repeated caller defaults, employee scope, user preference blobs, and browser storage in one nominal settings model. The result is difficult to explain, lets defaults drift, and assigns authenticated-user preferences to the wrong identity.

## Desired Outcome

Belimbing has two honest configuration lanes: environment-owned inputs for bootstrap and external tooling, and declared runtime parameters resolved from scoped `base_settings` to one code default. Durable account preferences use user scope, every runtime parameter has one definition and authorized UI, and repository checks prevent the old mixed fallback model from returning.

## Top-Level Components

- Canonical setting definitions own keys, types, allowed scopes, defaults, validation, encryption, UI metadata, and module ownership.
- `SettingsService` is the sole runtime-parameter resolver and no longer falls back to Laravel config.
- User, company, and global scopes replace employee, company, and global scopes.
- User preference migration moves durable account choices from employee scope, company scope, `users.prefs`, and browser-only storage where appropriate.
- Environment classification keeps application bootstrap and external tooling values in `.env` while moving post-boot runtime parameters behind UI.
- Architecture and enforcement tests keep declarations, consumers, UI, and environment templates aligned.

## Design Decisions

### Runtime parameters do not fall back to config

Retaining the existing database-to-config cascade minimizes migration work but preserves two mutable operator surfaces and keeps `.env` large. Persisting every default as a database row removes code fallback but creates seed-state drift and makes a fresh installation depend on data creation for safe behavior.

The recommended contract is sparse overrides in `base_settings` followed by a definition-owned code default. It keeps the UI authoritative without materializing defaults as rows and lets “restore default” remain a deletion.

### Durable preferences use user scope

Employee scope is the wrong identity because authenticated users may exist without employees and preferences belong to the login account. Keeping `users.prefs` as a parallel preference system would preserve the split mental model. Moving every piece of user data to settings would overreach into relational and high-volume data.

The recommended boundary is user-scoped settings for small durable preferences read by key, while relational collections and independently queryable user data keep purpose-built storage. Existing `users.prefs` values that meet the setting definition contract migrate deliberately.

### Scope fallback is definition-specific

A universal user-to-company-to-global cascade is simple to implement but makes unrelated company values silently influence personal preferences. No cascade at all prevents useful company and installation defaults.

The recommended design lets each definition declare its allowed scope chain. Personal theme can resolve from user to its code default, while a setting designed for organizational inheritance may resolve user to company to global to default.

### Machine-specific values remain installation settings

Keeping machine paths in `.env` would reopen a second runtime settings surface. Treating a path as global is honest only when one installation database has one compatible runtime environment.

The recommended initial contract stores machine-specific post-boot parameters in global `base_settings`, validates them through their UI, and documents the single-compatible-runtime invariant. A future heterogeneous multi-node deployment must add node scope or shared portable paths before claiming support.

### Mixed-purpose environment keys are split

Reclassifying a mixed-purpose variable wholesale would either leave product behavior in `.env` or make bootstrap infrastructure depend on the settings database. The recommended approach separates the meanings. For example, the user-facing application name becomes a global setting, while cache, Redis, session, deployment, and process namespaces use explicit environment-owned instance or prefix values.

## Public Contract

- Every runtime parameter has one exact definition and declared default.
- Runtime consumers read parameters only through `SettingsService` or typed wrappers delegating to it.
- `base_settings` supports user, company, and global scopes; employee is not a settings scope.
- Resolution visits only the scopes allowed by the definition, then returns its declared default.
- Runtime parameters are not sourced from `.env` or Laravel config.
- `.env` contains environment-owned application inputs and values consumed by external tooling.
- Every operator-editable runtime parameter has an authorized UI; restoring its default deletes the explicit row.
- Durable authenticated preferences use user scope. Browser storage may be an anonymous/device store or synchronized rendering cache, not the authenticated source of truth.

## Phases

### Phase 1 — Canonical documentation

Goal: the approved target, terminology, preference ownership, and current implementation gaps are explicit before code migration begins.

- [x] Rewrite `docs/architecture/settings.md` around environment ownership, declared defaults, and user scope {Codex/GPT-5}
- [x] Align the configuration contract in `docs/architecture/module-system.md` {Codex/GPT-5}
- [x] Add concise configuration ownership guidance to `.env.example` {Codex/GPT-5}
- [x] Align the AI resolution architecture and extension configuration guide with the target contract {Codex/GPT-5}
- [x] Reconcile the dashboard preference plan with the approved user-scope target {Codex/GPT-5}

### Phase 2 — Definition registry and resolver

Goal: one module-owned definition controls every runtime parameter and `SettingsService` resolves database rows to that definition’s default without config fallback.

Evidence: focused settings, AI runtime, extraction, and residue suites pass (100 tests, 350 assertions); the Performance evidence is recorded with its Phase 4 slice; Pint, UI detector, and diff checks pass.

- [x] Land the first vertical slice: introduce definition discovery and validation, make migrated keys use definition-owned fallback instead of config/caller fallbacks, distinguish definitions from runtime-state claims, and migrate AI tool rounds plus `pdftotext` {Codex/GPT-5}
- [ ] Extend discovered module settings declarations with exact definitions, types, scopes, defaults, validation, encryption, ownership, and editability
- [ ] Make editable UI fields refer to definitions rather than repeat defaults or rules
- [ ] Change the settings read contract so callers do not supply or duplicate parameter defaults
- [ ] Separate runtime-state namespace claims from runtime parameter definitions
- [ ] Add contract tests for missing definitions, invalid scopes, nullable defaults, encryption, caching, and restore-default behavior

### Phase 3 — User scope and durable preferences

Affected pages: profile appearance, application top bar, localization settings, dashboard, profile settings
Goal: authenticated preferences follow the user account across sessions and devices without depending on an employee record.

Evidence for the timezone slice: focused definition, resolver, migration, display,
endpoint, company, Localization UI, residue, and migration-discovery tests pass
(118 tests, 259 assertions); Pint and diff checks pass.

- [ ] Replace employee settings scope with user scope and migrate any employee-scoped setting rows that represent account preferences
- [x] Store `ui.timezone.mode` in user scope and migrate legacy effective preferences; rename the company IANA setting to `localization.timezone` with declared `UTC` default {Codex/GPT-5}
- [ ] Store authenticated theme preference in user scope with system as the declared default; retain browser storage only for anonymous state or synchronized pre-paint
- [ ] Classify and migrate appropriate `users.prefs` values, including landing page, dashboard layout, and AI model hints
- [ ] Replace employee settings authorization with self-owned user preference authorization and explicit support override capability
- [ ] Verify users without employee records receive complete preference behavior

### Phase 4 — Environment and runtime-parameter migration

Goal: every post-boot parameter is UI-managed and no deployment changes behavior silently when config fallback is removed.

Evidence for the Performance slice: focused resolver, registry, dashboard
authorization/save/restore, instrumentation, pruning, diagnostics, bootstrap
degradation, and settings tests pass (79 tests, 214 assertions); adjacent AI
and residue compatibility suites pass; Pint and the UI detector pass.

- [ ] Inventory `.env.example`, Laravel config, module config, and direct `env()` or `config()` reads by consumer category
- [x] Migrate Performance logging controls (`enabled`, minimum request time, slow-SQL threshold, retention, and log path) to global definitions and the authorized Performance UI; remove their environment/config fallbacks {Codex/GPT-5}
- [ ] Migrate `APP_LOCALE` to the existing `ui.locale` setting contract and Localization UI; put its default in the setting definition, keep the missing-translation fallback in versioned code, and retain `APP_FAKER_LOCALE` only as a development/test input
- [ ] Migrate mail transport, endpoint, credentials, and sender identity from `MAIL_*` to encrypted global settings with an authorized Email/Integrations UI and a safe code default
- [ ] Migrate the Lara prompt-extension path and Bash-tool enablement from `AI_LARA_PROMPT_EXTENSION_PATH` and `AI_BASH_TOOL_ENABLED` to global AI definitions and authorized UI; use a safe disabled Bash default
- [ ] Migrate `SESSION_LIFETIME` to a global session-policy definition resolved before session middleware needs it; keep session storage, cookie transport, and encryption bootstrap inputs environment-owned
- [ ] Split `APP_NAME`: store the user-facing product name as a global branding/system-identity setting and give cache, Redis, session, deployment, and process namespaces explicit environment-owned keys
- [ ] Remove `EARLY_HINTS_ENABLED` from `.env.example` after confirming it remains unconsumed; do not create a setting for dead configuration
- [ ] Keep bootstrap and external tooling inputs environment-owned and documented
- [ ] Add missing settings definitions and authorized UIs for post-boot parameters
- [ ] Provide a deliberate import or operator migration path for existing environment-backed runtime values
- [ ] Remove runtime-parameter environment entries and config fallbacks only after their values and UI paths are accounted for
- [ ] Define and validate the installation invariant for machine-specific global settings

### Phase 5 — Enforcement and completion

Goal: the repository mechanically protects the simplified mental model.

- [ ] Add architecture tests that reject environment or config sources for declared runtime parameters
- [ ] Add tests that every editable field references a declared parameter and every persisted key is claimed
- [ ] Add tests that defaults, scopes, validation, encryption, and UI reset behavior come from one definition
- [ ] Update affected runbooks and guides after implementation matches the architecture
- [ ] Remove transitional compatibility code, stale employee terminology, and obsolete preference storage
- [ ] Mark `docs/architecture/settings.md` implemented and this plan complete only after behavior and documentation agree
