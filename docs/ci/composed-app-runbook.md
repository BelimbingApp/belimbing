# Recover a failed composed-application pin advance

Use this runbook when a changed Domain snapshot refuses application boot or module migration preflight. It covers the current manifest, provider-order, route and migration composition guards; arbitrary exceptions from Domain business code are outside this inventory.

## Capture the composition before changing it

Record the failed run, authored PR head, actual checkout SHAs and `domain-materialization` artifact. Compare the last green set with the failing set. Sibling Domains are composed at their `main` and the Domain under test comes from the caller checkout, so two runs of the same commit can mount different sibling revisions: read the `domain-materialization` artifact for the revisions a run actually used rather than assuming. The caller's reusable workflow ref and `platform-ref` determine which platform code it used, and changing platform main does not change a caller pinned to an older platform commit. See [Domain CI composition](domain-ci.md).

Prefer a corrective commit or a reviewed restoration of the last compatible pin set. Do not force-push Domain history or delete deployed data to make boot pass. A code-pin rollback is not a database rollback. Rebuild configuration and route caches after changing the mounted composition. Preserve [schema maturity and migration history](../../app/Base/Database/AGENTS.md).

## Message inventory

The blocks below preserve literal PHP diagnostic templates. `%s` and `$module`/`$required` are substituted at runtime. Cycle prefixes are quoted to preserve their final space; the quotes are delimiters, not output. Their tails are dynamically assembled.

Manifest discovery feeds provider resolution in [#599](https://github.com/BelimbingApp/belimbing/pull/599), implementing issue [#585](https://github.com/BelimbingApp/belimbing/issues/585). Issue [#608](https://github.com/BelimbingApp/belimbing/issues/608) was closed as duplicate of that existing boot validation. Route-key and table collision guards landed in [#570](https://github.com/BelimbingApp/belimbing/pull/570), table checks at boot in [#588](https://github.com/BelimbingApp/belimbing/pull/588), and route-name collisions in [#618](https://github.com/BelimbingApp/belimbing/pull/618). Older migration dependency and metadata behavior remains defined by the linked source, not a separate #608 implementation.

### Descriptor has no package name

```text
Module manifest at %s has no name.
```

The descriptor path contains an extra.blb block but no non-empty Composer name. Fix that Module manifest; advance its owning Domain pin, or restore the previous compatible Domain commit. Changing the platform version cannot supply the missing metadata. [Owning source](../../app/Base/Foundation/ModuleManifest/ModuleManifestReader.php).

### Duplicate stable Module identity

```text
Duplicate BLB module identity [%s] declared by [%s] and [%s].
```

The two named roots claim one stable ID. Remove an obsolete relocated copy or correct a genuinely different Module identity. Select a compatible pair of owner/removal commits; move the old owner pin as well as the new owner when relocation spans People and PeopleConnector. [Owning source](../../app/Base/Foundation/ModuleManifest/ModuleManifestReader.php).

### Missing or incompatible provider dependency

```text
Cannot resolve provider order: %s requires %s (%s; constraint %s).
```

The substitutions name the requiring Module, required ID, missing/incompatible kind and constraint. Install and enable the required Domain, or select a dependency commit publishing a compatible version. Move the required Domain pin; if the consumer introduced an unsupported requirement, fix or restore the consumer pin instead. A disabled mount needs enablement as well as compatible code. [Owning source](../../app/Base/Foundation/Providers/ModuleProviderOrder.php).

### Dependency points to a later root

```text
$module cannot require later-root module $required.
```

A dependency would invert Base → Core → enabled Domains → Extensions. Fix the requiring Module boundary or replace the upward dependency with an explicit contribution contract. Advance or restore that consumer pin; reordering pins or disabling the guard cannot legalize the edge. [Owning source](../../app/Base/Foundation/Providers/ModuleProviderOrder.php).

### Provider dependency cycle

```text
'Module dependency cycle: '
```

The diagnostic appends a closed chain of stable Module IDs and a final period. Break the cycle in the participating Module contracts. Advance the changed owners together, or restore the last compatible pin set; moving only a leaf dependency will not necessarily remove the cycle. [Owning source](../../app/Base/Foundation/Providers/ModuleProviderOrder.php).

### Duplicate route key

```text
Route %s is registered by more than one module route file: %s and %s. Laravel would keep only the last one; give each module its own URI.
```

The key includes HTTP method, domain constraint and URI. Give separate workflows distinct URIs or remove the obsolete route file after relocation. Move the pin owning that obsolete or conflicting declaration; for relocation select both new-owner and old-owner-removal commits. Renaming only the route name does not fix this refusal. [Owning source](../../app/Base/Routing/RouteDiscoveryService.php).

### Duplicate route name

```text
Route name %s is registered by more than one module route file: %s and %s. Laravel would keep only the last one; give each module its own route name.
```

Distinct URIs still share the same non-empty name across discovered files. Namespace the names and update their callers, or remove the obsolete owner. Move the corrected Module pin and any dependent caller pin. Changing only the URI leaves this refusal intact. [Owning source](../../app/Base/Routing/RouteDiscoveryService.php).

### Migration preflight envelope

```text
Module migration dependency preflight failed.
```

This is the shared header, not a separate root cause. Read every following line: one preflight may report several defects. Choose the pin changes from those named owners and dependencies. The table-ownership boot guard uses this same envelope before a migration command runs. [Owning source](../../app/Base/Database/Services/ModuleMigrationDependencyChecker.php).

### Missing migration dependency

```text
- %s requires %s (%s), but that module is not installed or enabled.
```

The line names the requiring package, required Module and constraint. Install/enable the dependency and move its Domain pin to a compatible revision, or fix/restore the consumer requirement. An immutable SHA alone does not enable a disabled Domain. [Owning source](../../app/Base/Database/Services/ModuleMigrationDependencyChecker.php).

### Incompatible migration dependency

```text
- %s requires %s (%s), but installed version is %s.
```

The installed version can be reported as unversioned. Select a required-Module commit with a compatible declared version; advance that dependency pin. If the constraint itself is wrong, fix the consumer contract and advance its pin instead of weakening the version check. [Owning source](../../app/Base/Database/Services/ModuleMigrationDependencyChecker.php).

### Migration filenames violate dependency order

```text
- %s requires %s, but %s would run before required migration %s. Rename migrations so the required module sorts first.
```

Provider ordering does not replace Laravel filename ordering. Correct the named migration ordering under the schema-history policy and advance the owning Domain pin, together with dependencies when necessary. For applied stable history use an approved forward migration strategy; do not blindly rename deployed migration records or edit the database ledger. [Owning source](../../app/Base/Database/Services/ModuleMigrationDependencyChecker.php).

### Duplicate migration basename

```text
- Duplicate migration name %s appears in: %s. Laravel keeps one file per migration name, so rename one of them.
```

The named files collide even if their directories differ. Remove an obsolete relocation copy, or give genuinely separate new migrations unique names under the schema-history policy. Advance the corrected source pin; applied migration history needs a deliberate forward strategy, not an ad hoc filename edit. [Owning source](../../app/Base/Database/Services/ModuleMigrationDependencyChecker.php).

### Multiple table owners

```text
- Table %s is created by more than one module: %s. One module owns a table; remove or rename the table in every module but its owner.
```

The owner list contains Module IDs and declaration files. Complete the relocation by advancing the old owner to its removal commit as well as the new owner, or rename genuinely separate new schema through its owning migration policy. Renaming only a migration file does not change table ownership. This source scan neither inspects nor repairs live database contents. [Owning source](../../app/Base/Database/Services/ModuleMigrationDependencyChecker.php).

### Migration dependency cycle

```text
'- Module manifest dependency cycle: '
```

The diagnostic appends unresolved Module IDs joined by arrows and a final period; this migration diagnostic is not necessarily the closed cycle path used by provider resolution. Break the participating required-module edges and advance the affected owner pins together, or restore the previously compatible set. [Owning source](../../app/Base/Database/Services/ModuleMigrationDependencyChecker.php).

## Prove the replacement set

Run `bash tests/ci/test-ci-scripts.sh` after editing the descriptor, then run the affected Domain's suite from a prepared platform checkout. Require composed evidence with the proposed immutable platform and Domain refs on both SQLite and PostgreSQL; platform-only green tests do not prove optional mounts were present. Inspect the materialization artifact again, then land the platform descriptor and dependent caller pins in the established dependency order through their canonical gates. Do not bypass failed checks or turn a missing dependency into an optional one solely to clear CI.

The dedicated [composed-smoke](../../.github/workflows/composed-smoke.yml) workflow (#600 / #712) runs on PR, push to main, and a nightly schedule (#663); on nightly refusal it opens or updates one **Composed boot failed** issue. Existing route/table scans also have limits: cross-file route checks do not validate duplicates within one file, and table discovery reads supported literal declarations rather than arbitrary runtime DDL. See the [module authoring contract](../architecture/module-authoring.md) and [composition architecture](../architecture/module-system.md#composed-application-refusals).
