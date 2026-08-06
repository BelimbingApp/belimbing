# Four-root application topology

**Status:** Complete
**Last Updated:** 2026-08-06
**Sources:** `docs/architecture/decisions/0001-four-root-application-topology.md`, `docs/architecture/module-system.md`, `docs/architecture/database.md`, root `AGENTS.md`
**Agents:** codex/gpt-5.6-sol

## Problem Essence

Belimbing's paths and vocabulary no longer express its understood ownership model: domains contain modules, Core is a required domain with platform lifecycle, and extensions are application code with intentionally flexible semantics. The stale topology is duplicated across seven Git repositories and in runtime discovery, persisted provenance, serialized class references, tooling, tests, and operator surfaces.

## Desired Outcome

All runtime PHP code lives below the four accepted roots `app/Base`, `app/Core`, `app/Domains`, and `app/Extensions`; namespaces, discovery, lifecycle, UI language, tooling, documentation, and durable identities agree with that model. The composed checkout boots, migrates, builds, and passes platform plus source-repository verification with no active references to the retired topology outside a narrowly documented compatibility boundary.

## Top-Level Components

- **Application topology:** centralized definitions for Base, Core, Domain, and Extension roots and their deterministic discovery order.
- **Domain lifecycle:** Core is required; optional domain sources install below `app/Domains` and support enable/disable/uninstall safely.
- **Extension lifecycle:** extension sources install below `app/Extensions`, remain semantically relaxed, and disappear from discovery when their checkout is absent.
- **Logical identity:** canonical domain/module/extension IDs remain stable when physical paths and PHP namespaces change.
- **Compatibility boundary:** old persisted paths and class names are normalized or resolved for durable state and immutable migration execution without preserving the old filesystem layout.
- **Operator information architecture:** Domains are primary; Modules are contained capabilities; source/repository detail appears only where update diagnostics require it.

## Design Decisions

### Four roots rather than one uniform Domain collection

`app/Core` wins over `app/Domains/Core` because Core is platform-owned and has a non-optional lifecycle. The separate root removes repeated negative rules while a central Domain locator preserves one logical model. `app/Base/Core` is rejected because it misstates ownership and dependency direction.

### Extensions remain a relaxed mixed bag

The migration does not force private code into strict domain classification. `app/Extensions` is the explicit deployment-owned escape hatch, while stable dependency and contribution declarations keep its integration honest. This preserves user freedom without retaining a bespoke runtime-code root.

### Hard path cutover with bounded class compatibility

Long-lived dual discovery would create permanent entropy. Filesystem paths cut over together and the old bespoke Extension autoloader is removed. Composer loads one bounded prefix-alias bootstrap because durable serialized state and immutable pre-cutover migrations may still request old class names. Stable IDs replace path-derived identity wherever the current code relies on location.

## Public Contract

- Base components: `app/Base/{Component}` / `App\Base\{Component}`.
- Core modules: `app/Core/{Module}` / `App\Core\{Module}`.
- Optional domain modules: `app/Domains/{Domain}/{Module}` / `App\Domains\{Domain}\{Module}`.
- Extension modules: `app/Extensions/{Extension}/{Module}` / `App\Extensions\{Extension}\{Module}`.
- Application ownership segments under `app/` are PascalCase; conventional repository metadata keeps its native naming, and persisted logical IDs are kebab-case without deriving authority from paths.
- Discovery order is Base → Core → enabled Domains → installed Extensions.
- Core is visible as a required Domain but is updated only with the platform.
- Extensions may own schema, routes, views, services, adapters, and cross-domain behavior; relaxed placement does not relax quality, authorization, dependency, or data-safety requirements.
- The old `app/Modules`, repository-root `extensions`, `App\Modules`, and `Extensions\` contracts are not valid authoring targets after cutover.
- Retired class names are accepted only by the bounded compatibility bootstrap and persistence-boundary normalization; they are never valid new source identities.

## Compatibility Removal Condition

The old `ExtensionAutoloader` and its custom filesystem mapping are gone. Composer currently loads `app/Base/Foundation/bootstrap/autoload_legacy_application_classes.php`, a one-way bridge limited to the retired `App\Modules\Core\`, `App\Modules\`, and `Extensions\` prefixes. It serves both durable pre-cutover state and immutable migrations that still execute retired model or seeder classes.

The alias bootstrap may be removed only when all of these conditions hold:

1. Every supported installation has applied `2026_08_05_000000_normalize_four_root_application_topology`.
2. Every retained queue, failed-job, workflow, and outbox payload created before cutover has completed, been deliberately discarded, or been verified to contain canonical class names.
3. A canonical migration baseline or squash has replaced the executable pre-cutover history, or an exhaustive review proves that no supported fresh install, rollback, or replay can request a retired class or re-author a retired durable identity.

Database normalization alone is therefore insufficient to remove the bridge. The authenticated legacy Modules URL redirect remains a separate compatibility surface and may be removed after one supported release in which all generated navigation and documentation use the Domains URL. Stable historical migrations and persisted table names remain records rather than new authoring surfaces. Source-declared `IncubatingSchema` migrations remain editable under the database policy and were canonicalized in place where this cutover exposed retired references.

The forward topology normalizer declares `ReplaysAfterIncubatingSchema` because later local edits to incubating migrations can recreate rows that need canonicalization after their tables are rebuilt. The marker makes the stable normalizer's idempotent, data-only `up()` join that local replay; it does not make the migration incubating, permit schema mutation, or authorize retrofitting an already-applied stable migration without explicit recovery or ADR approval.

## Phases

### Decision and inventory

- [x] Sync and confirm clean `main` checkouts for the platform, Commerce, Operation, People, Ham, Kiat, and SB Group repositories. {codex/gpt-5.6-sol}
- [x] Record the accepted topology and rejected alternatives in ADR 0001. {codex/gpt-5.6-sol}
- [x] Complete a path, namespace, persisted-identity, tooling, documentation, and operator-vocabulary inventory across all seven repositories. {codex/gpt-5.6-sol}

### Compatibility and stable identity

- [x] Centralize root discovery so production mechanisms consume the four-root contract rather than duplicating path literals. {codex/gpt-5.6-sol}
- [x] Preserve canonical module/domain/extension IDs while replacing path-derived update and inventory keys. {codex/gpt-5.6-sol}
- [x] Add bounded legacy class resolution for queued jobs, events, workflows, polymorphic references, and immutable migrations created before the namespace cutover. {codex/gpt-5.6-sol}
- [x] Add an idempotent normalization path for registry module paths, seeder classes, migration source paths, and known executable class references. {codex/gpt-5.6-sol}
- [x] Mark the stable, data-only topology normalizer to replay after a referenced incubating-schema rebuild without relaxing schema maturity. {codex/gpt-5.6-sol}
- [x] Prove compatibility with focused tests that begin from legacy stored identities and resolve the new classes exactly once. {codex/gpt-5.6-sol}

### Repository relocation

- [x] Move platform-owned Core from `app/Modules/Core` to `app/Core` and rename `App\Modules\Core` to `App\Core`. {codex/gpt-5.6-sol}
- [x] Move Commerce, Operation, and People nested repositories to `app/Domains/{Domain}` and rename their namespaces to `App\Domains`. {codex/gpt-5.6-sol}
- [x] Move Ham, Kiat, and SB Group nested repositories to PascalCase roots below `app/Extensions` and rename their namespaces to `App\Extensions`. {codex/gpt-5.6-sol}
- [x] Rename extension module directories to PascalCase without losing nested Git history. {codex/gpt-5.6-sol}
- [x] Update every source repository's Composer metadata, event declarations, CI, tests, scripts, and docs for its mounted path and namespace. {codex/gpt-5.6-sol}

### Platform integration

- [x] Implement Base → Core → Domains → Extensions provider and artifact discovery across migrations, seeders, routes, menus, settings, authz, dashboard widgets, Livewire components, skills, manifests, views, assets, and tests. {codex/gpt-5.6-sol}
- [x] Update domain and extension install, state, residue, inventory, update, and nested-git services for their new roots and stable identities. {codex/gpt-5.6-sol}
- [x] Remove the bespoke Extension autoloader and replace its Composer entry with the bounded legacy-alias bootstrap required by durable state and immutable migration execution. {codex/gpt-5.6-sol}
- [x] Rename operator-facing Modules surfaces to Domains while retaining Module as the contained ownership-boundary noun. {codex/gpt-5.6-sol}
- [x] Retire Distribution Bundle product/general-architecture terminology and use source/repository only where delivery mechanics require it. {codex/gpt-5.6-sol}
- [x] Update `.gitignore` so the platform tracks `app/Core` and root agent guides while protecting nested Domain and Extension repositories. {codex/gpt-5.6-sol}

### Documentation and guidance

- [x] Rewrite `docs/architecture/module-system.md` as the current Domain-and-Module contract and link ADR 0001. {codex/gpt-5.6-sol}
- [x] Update root and nested `AGENTS.md` guidance for all four roots, including relaxed Extension placement. {codex/gpt-5.6-sol}
- [x] Update guides, runbooks, plans, examples, CI descriptions, and generated-path documentation without rewriting historical records that are explicitly archival. {codex/gpt-5.6-sol}
- [x] Document the `ReplaysAfterIncubatingSchema` authoring boundary in database architecture and migration guidance. {codex/gpt-5.6-sol}
- [x] Add a repository-wide guard that rejects new active references to retired paths/namespaces outside the compatibility allowlist. {codex/gpt-5.6-sol}

### Verification

- [x] Run formatting and syntax checks in every changed repository. {codex/gpt-5.6-sol}
- [x] Run platform and per-domain/per-extension test suites, including discovery, lifecycle, migration dependency, inventory/update, queue/class compatibility, and persisted provenance coverage. {codex/gpt-5.6-sol}
- [x] Run `composer dump-autoload`, asset production build, module-aware migration status/migration appropriate to `APP_ENV`, and composed application boot smoke tests. {codex/gpt-5.6-sol}
- [x] Confirm all seven Git working trees contain only intended changes and no nested repository was accidentally staged by the platform. {codex/gpt-5.6-sol}
- [x] Review the complete diff for low-entropy cleanup, stale terminology, and unauthorized compatibility residue. {codex/gpt-5.6-sol}
- [x] Rerun the complete composed suite after the review fixes and record the final landing counts. {codex/gpt-5.6-sol}

### Landing

- [x] Record the bounded compatibility removal condition and pre-review validation evidence. {codex/gpt-5.6-sol}
- [x] Replace provisional validation figures with final post-review evidence. {codex/gpt-5.6-sol}
- [x] After explicit commit/push authorization, commit each nested repository and the platform with the required co-author, then push in the documented compatibility-safe order. {codex/gpt-5.6-sol}

## Validation Evidence

- The final post-review, post-formatter-remediation exact-tree Pest run passed **3,506 tests and 26,591 assertions**, with 22 environment-gated skips, at the normal 512 MB limit in 446.55 seconds; the preceding standard `php artisan test --compact` run also exited successfully at that limit. Data Share canonical-line reads now use bounded chunks rather than reserving the full 32 MB record limit for every small line; its focused slice passed 124 tests and 922 assertions.
- The review-focused topology, migration-policy, compatibility, and System Info set passed 37 tests and 167 assertions; the final replay-safety/guard slice passed 25 tests and 118 assertions after rejecting both literal and dynamic schema mutation.
- The pre-review syntax sweep passed across 2,945 PHP files. The final optimized Composer autoloader built successfully with 10,106 classes, Composer validation passed, and the Vite production asset build succeeded. Review-touched PHP passes scoped Pint and the final composed suite.
- The local composed application boots, exposes `admin.system.software.domains`, and has applied the forward normalization migration in batch 5. A final `migrate --dev --no-interaction` rebuilt the full incubating chain, replayed the stable data-only normalizer, and completed production plus development seeding; the seeder registry then contained zero `App\\App\\Extensions\\`, `App\\Modules\\`, or top-level `Extensions\\` class names.
- The full run exposed and fixed a pre-existing UTC/Malaysia business-date boundary bug in Kiat's investment context; its deterministic regression file passes 5 tests and 55 assertions.
- Stable historical migrations were not edited. The topology guard explicitly allowlists 27 audited pre-cutover stable migrations that retain retired provenance. Ten source-declared incubating migrations were intentionally canonicalized in place: People Payroll migrations `000007` through `000012`, and SB Group IBP migrations `000021`, `000025`, `000027`, and `000030`; the earlier blanket R100/no-internal-Domain-edit claim is superseded.
- `git diff --check` passes in the platform and all six nested repositories. All seven remain on `main`; nested repositories contain no untracked residue, and the platform tracks only the Domain and Extension root guides across nested Git boundaries.
- Focused Pint checks pass for review-touched PHP. The broad baseline check exposed 616 pre-existing findings among 2,839 files, including hash-immutable historical migrations; remediation and a trustworthy authorable-file CI scope are recorded in `docs/plans/authorable-php-formatting-baseline.md`.
- The coordinated `main` landing completed in platform → Domains → Extensions order: platform `ff44f92a`, Commerce `deac42b`, Operation `8a21b59`, People `c2195fa`, Ham `f94ddbc`, Kiat `e226e2a`, and SB Group `82c830b`.
