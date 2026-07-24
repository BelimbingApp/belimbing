# settings-model-evolution

**Status:** Complete
**Last Updated:** 2026-07-24
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

Evidence: the canonical registry/resolver, architecture, settings UI, AI, Performance, and module-consumer suites pass. Definitions are discovered across Base, Core modules, Commerce, and extensions; Pint and the UI detector pass.

- [x] Land the first vertical slice: introduce definition discovery and validation, make migrated keys use definition-owned fallback instead of config/caller fallbacks, distinguish definitions from runtime-state claims, and migrate AI tool rounds plus `pdftotext` {Codex/GPT-5}
- [x] Extend discovered module settings declarations with exact definitions, types, scopes, defaults, validation, encryption, ownership, and editability {Codex/GPT-5}
- [x] Make editable UI fields derive defaults, rules, labels, help, encryption, and scope from canonical definitions {Codex/GPT-5}
- [x] Change the settings read contract so callers cannot supply or duplicate parameter defaults {Codex/GPT-5}
- [x] Separate runtime-state namespace claims from runtime parameter definitions {Codex/GPT-5}
- [x] Add contract tests for missing definitions, invalid scopes, nullable defaults, encryption, caching, global uniqueness, and restore-default behavior {Codex/GPT-5}
- [x] Keep fresh-install and pre-migration reads boot-safe by returning definition defaults (or `null` for claimed runtime state) until `base_settings` exists {Codex/GPT-5}

### Phase 3 — User scope and durable preferences

Affected pages: profile appearance, application top bar, localization settings, dashboard, profile settings
Goal: authenticated preferences follow the user account across sessions and devices without depending on an employee record.

Evidence: user-scope resolver, preference migration, appearance, top-bar theme,
timezone, locale, landing-page, dashboard, and AI model-hint tests pass. Users
without employee records have complete preference behavior.

- [x] Replace the unused employee settings scope with user scope; no deployed employee-scoped preference rows existed to migrate {Codex/GPT-5}
- [x] Store `ui.timezone.mode` in user scope and migrate legacy effective preferences; rename the company IANA setting to `localization.timezone` with declared `UTC` default {Codex/GPT-5}
- [x] Store authenticated theme preference in user scope with `system` as the declared default; retain browser storage only for anonymous state or synchronized pre-paint {Codex/GPT-5}
- [x] Migrate landing page, dashboard layout, AI model hints, and theme from `users.prefs` to user-scoped settings, then remove the preference blob {Codex/GPT-5}
- [x] Authorize self-owned user preferences independently from employee identity and declare an explicit support-override capability {Codex/GPT-5}
- [x] Verify users without employee records receive complete preference behavior {Codex/GPT-5}

### Phase 4 — Environment and runtime-parameter migration

Goal: every post-boot parameter is UI-managed and no deployment changes behavior silently when config fallback is removed.

Evidence: focused runtime UI, import, mail, session, localization, backup,
Performance, AI, Data Share, schedule, media, software, Commerce, Kiat, and SBG
consumer suites pass. `.env.example` contains only environment-owned and
external-tool inputs; the upgrade importer is preview-first and redacts values.

- [x] Inventory `.env.example`, Laravel config, module config, and direct `env()` or `config()` reads by consumer category {Codex/GPT-5}
- [x] Migrate Performance logging controls (`enabled`, minimum request time, slow-SQL threshold, retention, and log path) to global definitions and the authorized Performance UI; remove their environment/config fallbacks {Codex/GPT-5}
- [x] Migrate `APP_LOCALE` to `ui.locale` and the Localization/Profile UIs; keep the translation-language fallback in versioned code and `APP_FAKER_LOCALE` as a development/test input {Codex/GPT-5}
- [x] Migrate mail transport, endpoint, credentials, and sender identity from `MAIL_*` to global definitions with encrypted credentials, an authorized Email UI, and safe defaults {Codex/GPT-5}
- [x] Migrate the Lara prompt-extension path and Bash-tool enablement to global AI definitions and authorized UI, with Bash disabled by default {Codex/GPT-5}
- [x] Migrate `SESSION_LIFETIME` to a global session-policy definition projected before session middleware; keep session storage, cookie transport, and encryption environment-owned {Codex/GPT-5}
- [x] Split `APP_NAME`: store the user-facing product name globally while cache, Redis, session, deployment, and process namespaces use explicit environment-owned names {Codex/GPT-5}
- [x] Remove the unconsumed `EARLY_HINTS_ENABLED` variable without creating a setting {Codex/GPT-5}
- [x] Keep bootstrap, infrastructure, native toolchain topology, and external-tool inputs environment-owned and document their boundary {Codex/GPT-5}
- [x] Add definitions and authorized UIs for the remaining inventoried post-boot parameters across Base, Core modules, Commerce, Kiat, and SBG {Codex/GPT-5}
- [x] Provide `blb:settings:import-environment` as a deliberate preview-first, value-redacting migration path for legacy environment-backed runtime values {Codex/GPT-5}
- [x] Remove runtime-parameter environment entries and config fallbacks after their definitions, UI paths, and import mappings are present {Codex/GPT-5}
- [x] Define and validate the single-compatible-runtime invariant for machine-specific global settings {Codex/GPT-5}

### Phase 5 — Enforcement and completion

Goal: the repository mechanically protects the simplified mental model.

- [x] Add architecture tests that reject environment or config sources for declared runtime parameters {Codex/GPT-5}
- [x] Add tests that every editable field references a declared parameter and every persisted key is claimed {Codex/GPT-5}
- [x] Add tests that defaults, scopes, validation, encryption, and UI reset behavior come from one definition {Codex/GPT-5}
- [x] Update affected runbooks, guides, architecture docs, and related plans after implementation matches the architecture {Codex/GPT-5}
- [x] Remove transitional runtime fallbacks, stale settings-scope terminology, and obsolete `users.prefs` storage {Codex/GPT-5}
- [x] Mark `docs/architecture/settings.md` implemented and this plan complete after behavior and documentation agree {Codex/GPT-5}
