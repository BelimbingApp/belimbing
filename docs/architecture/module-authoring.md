# Module Authoring Contract

**Document Type:** Architecture Reference
**Scope:** Current descriptor, composition and test contracts for a new optional Domain Module
**Last Updated:** 2026-09-06

## Overview

A Module owns its code and contributions inside `app/Domains/{Domain}/{Module}`; the Domain is the installable and enableable unit. The same shape applies in development, testing and production. Use the [module system](module-system.md) for the complete topology and contribution inventory. This reference collects the author-facing contracts and distinguishes naming conventions from enforced refusals.

## Descriptor and identity

Place the Module's `composer.json` beside its `ServiceProvider.php`, not only at the Domain repository root. The [manifest reader](../../app/Base/Foundation/ModuleManifest/ModuleManifestReader.php) reads `extra.blb`; the installed-module metadata surface is recorded in [#152](https://github.com/BelimbingApp/belimbing/pull/152), and its use for provider dependency resolution in [#599](https://github.com/BelimbingApp/belimbing/pull/599).

| Field | Author contract |
|---|---|
| Composer `name` | Non-empty package name required when a BLB descriptor is read. This is separate from the Module ID. |
| `extra.blb.module` | Stable lowercase Module identity, such as `people/training`. A declared identity overrides the conventional path fallback; two roots may not own the same identity. |
| `extra.blb.version` | Module contract version. A dependency with a non-wildcard version constraint requires a compatible published version. |
| `extra.blb.description` | Optional description, falling back to Composer's top-level description. |
| `extra.blb.requires-modules` | Object mapping required stable Module IDs to version-constraint strings, for example `"people/provider": "*"`. |
| `extra.blb.optional-modules` | Optional integration map. It does not create a required boot-order edge; absence must remain supported by the integration. |
| `extra.blb.publishes-events`, `extra.blb.consumes-events` | Lists of event names describing the Module's event surfaces. They do not register listeners. |

These fields describe BLB contracts, not Composer package installation. The [constraint evaluator](../../app/Base/Foundation/ModuleManifest/ModuleVersionConstraint.php) supports common exact, comparison, caret, tilde, wildcard and alternative constraints; do not assume it implements every Composer constraint form. Use valid string maps and lists even where the current reader tolerates malformed values. The reader skips JSON without an array-valued `extra.blb`; boot dependency validation is not a comprehensive JSON-schema validator. These are the reader and dependency semantics exercised by [#599](https://github.com/BelimbingApp/belimbing/pull/599).

## Required modules and provider boot

Declare dependencies on stable public contracts. [#599](https://github.com/BelimbingApp/belimbing/pull/599), implementing issue [#585](https://github.com/BelimbingApp/belimbing/issues/585), establishes the [provider-order contract](../../app/Base/Foundation/Providers/ModuleProviderOrder.php):

- Preserve Base → Core → enabled Domains → Extensions. A requirement on a later root is refused.
- Within each root, required modules precede their consumers. Alphabetical filesystem order breaks ties among ready modules; providerless modules still carry transitive edges.
- Missing or disabled required modules, incompatible versions and dependency cycles refuse resolution before discovered providers register.
- Rebuild cached configuration after changing descriptors or providers. A cached provider list does not rerun the sorter. The loaded provider sequence is logged at debug after boot.

Descriptor dependency validation at boot is already delivered by #599. Issue [#608](https://github.com/BelimbingApp/belimbing/issues/608) was closed as duplicate; it is not a separate implementation PR. For exact exception templates and corrections, see [composition refusals](module-system.md#composed-application-refusals).

## Route ownership

Keep routes in the owning Module's `Routes/web.php` or `Routes/api.php`. Discovery applies `web` middleware to web routes and `api` middleware plus the `api` URI prefix to API routes. Give each Module distinct URI space and named routes, such as `/people/training` and `people.training.index`.

[#570](https://github.com/BelimbingApp/belimbing/pull/570) rejects cross-file duplicates of the HTTP method/domain/URI key. [#618](https://github.com/BelimbingApp/belimbing/pull/618) additionally rejects a duplicate non-empty route name across discovered files, even when URIs differ. Renaming only the route name cannot fix a URI-key collision; changing only the URI cannot fix a name collision. Different HTTP methods remain distinct. The [route loader](../../app/Base/Routing/RouteDiscoveryService.php) does not provide this cross-file guarantee for duplicates inside one file. Rebuild route caches when changing the composition.

## Table ownership and migration names

New optional Domain tables follow `{domain}_{module}_{entity}`; use the owning [database naming and prefix registry](database.md#2-naming--execution-order) instead of inventing migration ranges. This naming convention predates the recent composition PRs and is recorded in the [four-root topology commit](https://github.com/BelimbingApp/belimbing/commit/ff44f92a); it is not a prefix validator added by #570. Retained `people_connector_*` tables in relocated People Modules are migration history, not permission to give new tables another Module's identity.

The enforced rule is one table owner: [#570](https://github.com/BelimbingApp/belimbing/pull/570) adds migration preflight refusal and [#588](https://github.com/BelimbingApp/belimbing/pull/588) applies that check at boot. The source scan recognizes literal table declarations; it does not inspect or repair an existing database or prove ownership of arbitrary dynamic DDL. Renaming a migration file does not fix two Modules creating the same table.

Required-module declarations also participate in migration preflight. The requiring Module's earliest migration filename must sort after its required Module's latest migration filename when both ship migrations. Provider topological order from [#599](https://github.com/BelimbingApp/belimbing/pull/599) does not replace Laravel filename ordering. Preserve the [migration and schema-maturity policy](../../app/Base/Database/AGENTS.md) when changing existing history; follow the current [dependency preflight](../../app/Base/Database/Services/ModuleMigrationDependencyChecker.php), not an ad hoc ordering override.

## Module tests

Keep Module tests in its `Tests/` directory and cross-Module Domain tests in the Domain's `Tests/`. From a prepared platform checkout with that Domain mounted, run only the owned directory, for example:

```bash
vendor/bin/pest app/Domains/People/Training/Tests
```

The platform [Pest configuration](../../tests/Pest.php) supplies its application `TestCase` and `RefreshDatabase` to Domain `Tests/Feature` files. The current suite configuration is carried by [#543](https://github.com/BelimbingApp/belimbing/pull/543); mounted sibling-module resolution for domain CI is addressed by [#486](https://github.com/BelimbingApp/belimbing/pull/486). A bare Domain checkout is not a bootstrapped application. Use [domain composition and pins](../ci/domain-pins.md) for the platform and dependency setup, and [test guidance](../../tests/AGENTS.md) for isolation and meaningful failure evidence. Do not replace a Module-scoped run with the full platform suite or parallel Pest execution during an author lane.

Before opening a PR, run `php artisan blb:module-check <module-id>` (for example `people/training`). It composes the Module with its declared `requires-modules`, prints routes, tables, and provider bindings from that closure, and exits non-zero on dependency or ownership refusals ([#657](https://github.com/BelimbingApp/belimbing/issues/657)).
