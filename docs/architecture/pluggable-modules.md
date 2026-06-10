# Pluggable Modules Architecture

**Document Type:** Architecture Specification
**Purpose:** Define how BLB evolves from a monolithic application into a framework that ships an integrated HR core and lets full-stack downstream modules (payroll, finance, country-specific verticals) plug in and out independently.
**Status:** Direction confirmed. Implementation phased.
**Audience:** BLB framework team, licensee developers, future plugin contributors.
**Last Updated:** 2026-05-25
**Related:**
- `docs/architecture/module-system.md` — directory layer convention.
- `docs/plans/pluggable-module-view-colocation.md` — migration plan for colocating module-owned Blade views with pluggable modules.
- `docs/guides/extensions/private-extension-repositories.md` — existing nested-git pattern.
- `docs/tutorials/people/` — domain crash course; the People domain is the first pluggable case.

---

## 1. Vision

BLB is a framework, not an app. It ships **one opinionated HR core** (Attendance, Leave, Claim, Settings) plus **one reference downstream plugin** for the first country (`blb-payroll-my`). Around that, the community builds:

- Per-country payroll plugins (`blb-payroll-sg`, `blb-payroll-id`, …).
- Industry-vertical variants (`blb-payroll-construction-my`, …).
- Alternative downstream consumers — a customer using an external payroll system installs Attendance + Leave + Claim and skips payroll entirely.
- Future domains (Finance, Sales, Procurement) follow the same shape.

Downstream plugins are **full-stack**: each one owns its complete vertical — run lifecycle, intake, statutory calculations, forms, ledger, UI. BLB does not provide a "framework Payroll" that plugins specialize into. The shared surface is **source-module events**, not a consumer framework.

The reasoning: in the AI-coding-agent era, re-implementing run plumbing per plugin is cheap; coordinating contract changes across a framework boundary is expensive. Maintenance surface dominates. We trade a small amount of duplication for a much simpler contract.

The model is what Laravel, Django, and Rails do at their best: the reference is the documentation, contracts are the public API, the community contributes around the edges — but without a privileged consumer-side framework that becomes a release-coordination bottleneck.

---

## 2. Goals

1. **True plug-and-play.** A module the deployment doesn't need is not installed. Removing a downstream plugin does not break the HR core.
2. **Minimal shared surface.** Only what genuinely must be shared (source-module events, the manifest format) lives in the framework. Everything else lives in plugins.
3. **No framework-vs-plugin tax.** A new payroll plugin does not require BLB-core PRs. Plugin authors own their plugins end-to-end.
4. **Same Git ergonomics as today.** The existing `extensions/{licensee}/` nested-git pattern works. We extend it; we don't replace it.
5. **No premature composer split.** Lift modules out of the main tree when contracts have stabilized — not before.
6. **One reference, many implementations.** `blb-payroll-my` is the canonical example for others to learn from, not a privileged path.

---

## 3. Architectural Layers

The layer convention from `docs/architecture/module-system.md` stays. Plug-ability rules apply per layer.

| Layer | Path | Plug-ability | Distribution |
|---|---|---|---|
| **Framework infrastructure** | `app/Base/{Module}/` | Not pluggable | Main repo |
| **Application Core** | `app/Modules/Core/{Module}/` plus shared `resources/core` presentation | Not pluggable | Main repo |
| **HR source modules** | `app/Modules/People/{Module}/` (Attendance, Leave, Claim, Settings), including module-owned `Views/` | Optional inside the HR domain | Main repo today; nested-git/composer later |
| **Downstream plugins** | Plugin repos checked out at `app/Modules/{Domain}/{Module}/`, including module-owned `Views/` | **Pluggable** | Nested git today; composer later |
| **Licensee extensions** | `extensions/{licensee}/{module}/` | Pluggable (private) | Nested git, private origin |

### 3.1 What stays in main repo

`app/Base/`, `app/Modules/Core/`, and the HR source modules under `app/Modules/People/` are the integrated application BLB ships. They have no compile-time dependency on any downstream plugin and function fully if no plugin is installed.

Base and Core are the exception to full-stack plugin colocation. Framework-wide presentation — shell layout, shared Blade components, navigation components, and design tokens — stays in `resources/core`. Non-Core domains own their module-specific presentation inside the module root.

The HR source modules publish events that downstream plugins listen to. Those event classes are the **public API** of BLB's HR application.

### 3.2 What becomes a plugin

Anything that *consumes* source-module facts and produces its own output. Today that means payroll. Soon it will mean finance, then potentially sales, procurement, and so on.

Each plugin is a full-stack vertical: it owns its tables, its lifecycle, its calculations, its UI, its exports. Module-specific Blade views live under the plugin's `Views/` directory and are registered by that plugin's service provider. The plugin depends on BLB's HR core (for the event classes) but not on any other plugin.

### 3.3 Why no shared "consumer framework"

There is no consumer-side framework — no shared "core Payroll" that plugins specialize into. Plugins own their entire vertical.

Reasons:

- The bits that would be shared (run state machine, period arithmetic, neutral input envelope, immutable ledger) are mechanically simple and cheap to AI-generate per plugin.
- The genuinely-different bits (statutory schemes, forms, statutory profile schemas) dominate every plugin anyway.
- A framework + specialization split would create exactly the release-coordination tax we want to avoid: every change to a shared Payroll core would force every country specialization to update.
- Plugin authors gain more by owning their plugin end-to-end than by inheriting plumbing they could not bend.

We accept the duplication. The duplicated code is mechanical and stable; it is not where bugs and design drift come from.

---

## 4. Module Taxonomy

Two roles. Role is enforced by contract, not by location.

### 4.1 Source modules

Author business facts. Country-neutral. Live in BLB main repo.

Examples: Attendance, Leave, Claim, Settings. Future Sales, Procurement.

Properties:
- Owns its tables, lifecycle, approvals, UI.
- Keeps module-owned views under its module root once it is pluggable-shaped.
- Dispatches public events for facts other modules may care about.
- Works in isolation — Attendance functions fully on a deployment with no plugin installed.
- Has no compile-time dependency on any plugin.

### 4.2 Full-stack plugins

Consume source events; produce their own output end-to-end. Live in separate repos.

Examples: `blb-payroll-my` (BLB's reference), future `blb-payroll-sg`, `blb-payroll-id`, `blb-finance-mfrs`, industry-vertical variants.

Properties:
- Depends on BLB's HR core (for event classes and identity models like Employee/Company).
- Does not depend on any other plugin.
- Owns its own data model — tables, pay-item taxonomy, run lifecycle, ledger, exports.
- Owns its own Blade views under `Views/`; shared shell and reusable components still come from `resources/core`.
- Registers its listeners via service-container tagging when it boots.
- Can be installed alongside other plugins (a multinational installs `blb-payroll-my` and `blb-payroll-sg`).

---

## 5. Communication Contracts

### 5.1 Events are the primary contract

When a source module produces a fact, it dispatches an event:

```
app/Modules/People/Attendance/Events/AttendanceAllowanceMaterialized.php
```

The event is a value object with the producer's domain shape: employee ID, date, the source rule ID, the amount. **No payroll concepts in the payload.** The producer does not know what a pay item is.

Plugins register listeners. Each plugin's listener interprets the producer-domain fact in its own terms — looks up the rule, maps to its own pay item, writes to its own input table. If no plugin is installed, the event has no listener and the source module continues unaffected.

**Event payloads are public API.** Once shipped, fields can be added but not removed or renamed. Breaking changes require a new versioned event class (`AttendanceAllowanceMaterializedV2`).

### 5.2 No cross-plugin interfaces

Plugins do not extend a framework, so no contributor interfaces (`PayItemContributor`, `StatutorySchemeCalculator`, …) exist. Each plugin owns its internal protocols.

If two plugins need to coordinate (rare — a future Finance plugin listening to a Payroll plugin's posting events), the same event-bus pattern applies. The plugin publishing the event owns its event class; the plugin consuming it depends only on the event payload.

### 5.3 The plugin manifest is `composer.json`

Every plugin ships a `composer.json`. PHP-package dependencies, autoload, and plugin identity belong to composer — BLB does not re-implement dependency resolution. BLB-specific metadata lives under `extra.blb`:

```json
{
  "name": "blb/payroll-my",
  "type": "blb-plugin",
  "require": {
    "php": ">=8.3",
    "phpoffice/phpspreadsheet": "^2.0"
  },
  "autoload": {
    "psr-4": { "App\\Modules\\People\\Payroll\\": "" }
  },
  "extra": {
    "blb": {
      "requires-modules": { "people/attendance": ">=1.2" },
      "optional-modules": { "finance/mfrs": ">=0.1" },
      "publishes-events": [],
      "consumes-events": [
        "App\\Modules\\People\\Attendance\\Events\\AttendanceAllowanceMaterialized"
      ]
    }
  }
}
```

Two responsibilities, two readers:

- **Composer** reads `require`, `autoload`, `name`, `type` and resolves the PHP package graph at install time. Conflict detection, transitive resolution, and the lockfile are composer's job — not BLB's.
- **BLB's runtime** reads `extra.blb` at boot to verify required BLB modules are present and to wire event listeners.

This pattern matches how Laravel package discovery uses `extra.laravel.providers` — idiomatic to composer-land, no new format to define, no PHP execution at parse time.

### 5.4 Resolving plugin dependencies across phases

**Phase 1–3 (nested-git, plugins are not standalone composer packages yet):**

The root project uses `wikimedia/composer-merge-plugin` to merge each plugin's `composer.json` into the root resolution:

```json
{
  "require": { "wikimedia/composer-merge-plugin": "^2.1" },
  "extra": {
    "merge-plugin": {
      "include": [
        "app/Modules/People/*/composer.json",
        "extensions/*/*/composer.json"
      ],
      "recurse": true,
      "replace": false,
      "merge-dev": true
    }
  }
}
```

`composer install` at the root walks all matched files, merges `require` sections, resolves the full graph, fails on conflicts. Plugin authors write a normal `composer.json`; nothing nested-git-specific leaks into it.

**Phase 4 (composer-ize):**

Plugins become real composer packages on Packagist or a private Satis. The root project does `composer require blb/payroll-my`. The merge-plugin disappears. Plugin internals do not change — the same `composer.json` works.

**Edge cases:**

- OS-level dependencies (image libraries, native extensions) are documented in plugin README. Not BLB's problem to install.
- Plugin-internal vendor isolation is not supported. Composer's autoloader is global per project; conflicting transitive deps must be resolved at the project level via composer's normal mechanisms.
- A plugin's own `composer.lock` is ignored at root-merge time. Only `require` sections merge.

### 5.5 The pay-item code does not belong to source modules

The pay-item code is a payroll concept and does not belong on an attendance record. The split:

- Attendance owns the rule (with its `condition_rows`, amount, schedule).
- Each payroll plugin owns a `rule_id → pay_item_code` mapping in its own tables.
- The event payload carries the rule ID, not a pay-item code.
- Plugins read the rule and apply their own mapping.

This deferral is the cost of plugin freedom. Plugins gain the ability to model pay items however they like; producers stay neutral.

### 5.6 Security model for full-stack plugins

Pluggable modules are **trusted application code**, not sandboxed content. A
plugin that ships a `ServiceProvider`, routes, Livewire components, migrations,
listeners, and Blade views already has application-code privileges. Colocating
Blade files under the module root does not make the plugin less safe or more
safe by itself; it makes the trust boundary explicit and reviewable as one
directory.

Security rules:

- **No sandbox promise.** Installing a plugin grants it the same trust level as
  application code. BLB does not promise runtime isolation between installed
  plugins.
- **Namespaced views only.** Plugins register their own `Views/` directory with
  a module-specific namespace. They render their own screens through that
  namespace, for example `view('payroll-my::livewire.runs.index')`.
- **No global view shadowing.** Plugins must not prepend global view paths or
  shadow `resources/core` views/components unless the framework explicitly adds
  a reviewed extension point for that purpose.
- **Core components are shared API.** Plugins compose framework components from
  `resources/core`; reusable UI improvements are contributed to Core rather
  than copied into plugins.
- **Authorization is server-side.** Plugin Blade may hide or show controls for
  usability, but routes, Livewire actions, services, jobs, and listeners enforce
  capabilities, company scope, and actor context. A view is never the security
  boundary.
- **Escaping discipline is unchanged.** Plugin views use Blade escaping by
  default (`{{ }}`). Raw output (`{!! !!}`) is allowed only for sanitized or
  known-safe HTML owned by the module.
- **Assets use reviewed entry points.** Module-owned CSS/JavaScript may live
  with the module, but ad hoc global script/style injection is not part of the
  contract. The host build decides which module asset entry points are compiled,
  and plugin code must remain compatible with the framework CSP.

### 5.7 Plugin asset contract

Most plugin UI should need no private CSS or JavaScript. A plugin should first
compose shared Blade components, semantic Tailwind tokens, Livewire actions, and
small inline Alpine expressions in its own namespaced views. Tailwind scans
module `Views/` directories, so utility classes used by colocated Blade files
are already part of the main build.

When a module truly needs owned frontend source, it keeps that source under its
module root in `Assets/`:

- `Assets/css/` for module-scoped styles.
- `Assets/js/` for module-scoped JavaScript.
- Any imported images, fonts, or static files stay below `Assets/` unless the
  module explicitly stores user-uploaded content through application storage.

Nested-git plugins do **not** get automatic global asset injection. A module
asset becomes active only through an explicit, reviewable host integration: the
root app adds the module entry point to the Vite input list or imports it from a
framework-owned entry point. This keeps production bundles deterministic,
prevents a private checkout from silently changing the public framework shell,
and makes CSP/script review visible in the main repo diff.

Rules for module assets:

- Prefer `resources/core` design tokens and components over private CSS.
- No remote CDNs, hosted fonts, analytics snippets, or third-party widgets from
  module Blade or assets.
- JavaScript should be a module-scoped enhancement for that module's DOM, not a
  global monkey patch. Shared behavior graduates to `resources/core/js`.
- CSS should target module-owned wrappers or exported utility/component classes;
  framework-wide tokens still live in `resources/core/css`.
- Providers may publish or expose static files only through explicit framework
  support. Do not invent per-module public paths ad hoc.

Composer-installed plugins use the same source layout (`Assets/`) and the same
security rules. The unresolved composer-specific detail is packaging mechanics:
whether BLB consumes package asset manifests directly from `vendor/`, publishes
them into a build workspace, or requires the host app to declare plugin entries.
Until composerization ships, nested-git plugins use the explicit host Vite entry
contract above.

---

## 6. Payroll Plugins as the First Worked Example

Payroll is the first vertical to plug out. Its shape illustrates what every future plugin looks like.

### 6.1 What `blb-payroll-my` owns end-to-end

- Run lifecycle (draft → calculated → posted → closed) and its UI.
- Pay-period management.
- Input intake (writes its own payroll-input table, applies its own idempotency).
- Pay-item taxonomy as MY chooses to model it.
- Scheme calculators (EPF, SOCSO, EIS, PCB, HRD Corp).
- Rate tables and bands.
- Employer and employee statutory profiles.
- Form generators (EA, E, CP39, Form A, Form 8A, CP21/22/22A).
- All UI surfaces — payroll dashboards, election screens, payslip viewer.
- Listeners for HR-core events.
- GL posting in the format MY's accountants need.

### 6.2 What a hypothetical `blb-payroll-sg` would own

The same shape, different content: CPF, SDL, IR8A, AIS, Singapore CPF account fields. Independently maintained. Independently released. No shared code with `blb-payroll-my` is required.

If both ship and a multinational installs both, each routes employees by country-of-employment to its own pipeline. Two ledgers, two payslip layouts. A separate display-layer plugin can unify them if that need arises — but it is not a problem the framework solves up front.

### 6.3 Reference, not template

`blb-payroll-my` is the canonical example for future plugin authors to read, not a template to copy and rename. Singapore is not Malaysia with renames — different schemes, different forms, different employee-self-file model (Singapore has no monthly tax withholding by employer). The *patterns* transfer (listening to events, owning your own pay items, structuring your statutory rules as data). The *content* is fully replaced.

This means the goal for the contracts is not "make SG a five-line config change." The goal is "let a SG engineer build SG without coordinating with the MY team."

---

## 7. Physical Structure: How Plugins Are Stored and Versioned

### 7.1 Current state

- Main repo (`belimbing`) contains `app/Base/`, `app/Modules/{Core,Commerce,Operation,People}/`, `resources/core/`, `docs/`.
- `extensions/{licensee}/{module}/` is a nested git repo with its own `origin`, ignored by the parent via `.git/info/exclude`. This is the working "sub-git" pattern.
- No composer split has been done.

### 7.2 The decision: extend nested-git first; composer later

**Nested git (the existing sub-git pattern).** Each plugin is its own git repository, checked out into the BLB working tree at a canonical path:

```
belimbing/app/Modules/People/Payroll/      (repo: blb-payroll-my, origin: github.com/blb/payroll-my)
belimbing/app/Modules/People/Finance/      (repo: blb-finance-mfrs, origin: github.com/blb/finance-mfrs)
```

The parent repo lists these paths in `.gitignore` (public) or `.git/info/exclude` (per-checkout). Each plugin has its own commits, branches, releases, issues.

**Composer packages.** Each plugin is a composer package (`belimbing/payroll-my`), installed via `composer require`, autoloaded via PSR-4. Reaches Packagist or a private Satis. Lockfile handles version pinning.

### 7.3 Why nested-git first

- **Consistency with the existing extensions model.** Licensees already understand nested-git. One mental model across BLB and extensions.
- **Path-based module discovery already works.** Service providers, menu glob (`app/Modules/*/*/Config/menu.php`), migration discovery, view discovery — all operate on filesystem paths. Composer-based discovery would require lifting those off paths first. That's real work and not the place to start.
- **Developer ergonomics during stabilization.** Modifying a plugin's code while developing BLB core is trivial in a nested-git world: edit, commit in the plugin, commit a path/version pin in the parent. With composer you need path repositories during development.
- **No autoloader gymnastics.** The current `App\Modules\People\Payroll\` namespace resolves via the framework's existing module loader. Composer would require either renaming all namespaces to package-style (`Blb\Payroll\My\`) or maintaining custom autoload rules.

### 7.4 Why composer later, not never

When plugins stabilize:

- **External contributors.** A community-built `blb-payroll-id` is far easier to install via `composer require` than via "clone this nested repo into this path."
- **Statutory updates.** New tax year for `blb-payroll-my` ships as a minor version bump.
- **Multi-version coexistence.** Two licensees pinning different versions handled by composer's lockfile.
- **Marketplace.** Packagist or Satis is built for this.

Transition is plugin-by-plugin, when each has earned stable contracts.

### 7.5 The hybrid in practice

Three categories of code coexist:

1. **Main repo content.** `app/Base/`, `app/Modules/Core/`, `app/Modules/People/{Attendance,Leave,Claim,Settings}/`, `resources/core/`, `docs/`. Single git repo, framework-owned.
2. **BLB-owned plugins.** Nested git repos at canonical paths, public origins (`github.com/BelimbingApp/blb-payroll-my`, …). Same authoring team as the main repo but separable for issue tracking, release cadence, and future composer migration.
3. **Licensee-owned extensions.** Nested git repos at `extensions/{licensee}/{module}/`, private origins. The existing model, unchanged.

A licensee's local working tree looks the same regardless of category — directories on disk. The difference is which remote each directory points at.

### 7.6 Full-stack module directories

Pluggable modules are full-stack ownership units. Their PHP classes, routes,
database files, tests, config, and module-owned Blade views live under the same
module root:

```text
app/Modules/People/Payroll/
├── Config/
├── Database/
├── Livewire/
├── Routes/
├── Services/
├── Tests/
├── Views/
└── ServiceProvider.php
```

The same rule applies to private licensee extensions under
`extensions/{owner}/{module}/`. Do not create a parallel presentation tree under
`resources/` for plugin-owned screens. `resources/core` remains the shared
framework UI surface: shell layouts, reusable components, tokens, and other UI
that is genuinely owned by the framework.

---

## 8. Module Boot and Discovery

Two stages: composer at install time, BLB at runtime. The shape differs between nested-git phases and composer-ized phases — be explicit about both.

### 8.1 Where plugins live on disk

**Phase 1–3 (nested-git):** plugins are git checkouts at canonical paths.

```
app/Modules/People/Payroll/         # blb-payroll-my checkout (own .git)
app/Modules/People/Attendance/      # blb-attendance checkout (own .git)
extensions/sb-group/qac/            # licensee extension (own .git)
vendor/                             # PHP libraries only (phpoffice, etc.) — NOT plugins
```

Plugins do not live in `vendor/`. Only the PHP libraries they depend on do.

**Phase 4 (composer-ized):** plugins are installed composer packages.

```
vendor/blb/payroll-my/              # installed via composer require
vendor/blb/attendance/              # installed via composer require
extensions/sb-group/qac/            # licensee extensions stay path-based
```

This is the standard composer layout. BLB does not move code out of `vendor/` (see §8.5 for the reasoning).

### 8.2 Install-time (composer)

When the operator runs `composer install` (phase 1–3 with merge-plugin; phase 4 with standalone packages), composer:

- Resolves PHP package dependencies across plugins.
- Builds the autoloader for all plugin namespaces.
- Writes the lockfile.
- Fails the install if PHP-side requirements conflict.

BLB does not duplicate any of this. If composer succeeds, every plugin's PHP dependencies are present and autoloadable.

### 8.3 Phase 4 distribution options

Composer supports several distribution mechanisms; BLB does not mandate any single one. Each plugin author chooses what fits.

- **Packagist (public).** First-party open-source BLB plugins (`blb-payroll-my`). Free registration; `composer require blb/payroll-my` works everywhere.
- **Private Packagist / Satis.** Commercial or licensee-private plugins. Self-hosted or paid service; root `composer.json` adds the private repo URL.
- **VCS repository.** Any git repo, no registry needed. Root `composer.json` declares the repo URL; composer pulls directly from git. Good for closed-source plugins without running a registry.
- **Composer path repository.** Local development against a host app. Root `composer.json` declares a path; composer symlinks the local checkout into `vendor/`.

The framework is portable across all of these. No mandatory dependency on Packagist or any other central infrastructure.

### 8.4 Runtime detection

On boot, BLB scans for modules:

- **Phase 1–3:** `app/Base/`, `app/Modules/*/*/`, `extensions/*/*/`. A module root is identified by a `ServiceProvider.php` plus a `composer.json` whose `extra.blb` block declares it as a BLB plugin. Module-owned views are loaded from each module's `Views/` directory by its provider.
- **Phase 4:** add a scan of `vendor/` for installed composer packages with `type: blb-plugin` and an `extra.blb` block. Path-based scans (`app/Modules/`, `extensions/`) stay in place for licensee path-based plugins.

A plugin not present on disk is simply not loaded. No fatal error.

### 8.5 Why plugins stay in `vendor/` at Phase 4

Moving composer-installed plugin code from `vendor/` to a canonical `app/Modules/.../` path would be possible (Drupal and WordPress do it via `composer/installers`-style routing) but is rejected here:

- `composer update` writes to `vendor/`. A post-install move makes the update flow lossy or repeated; symlinks help on Linux/Mac but are awkward on Windows dev environments.
- Stack traces, IDE indexing, and "go to definition" become misleading when a file's reported path is not where the file actually lives.
- The mechanism (custom installer plugin or `extra.installer-paths`) is extra BLB-maintained code that fights composer's worldview.
- The motivation — "consistent paths across phases" — is better served by **discovery being location-agnostic**, not by forcing all plugin code into one path. BLB's runtime resolves a plugin by name, not by path.

Path-based discovery in Phase 1–3 is a transitional state, not a desired invariant. By Phase 4 the framework should look at composer's view of the world, not impose a custom view on top of it. Plugins live where composer puts them; BLB finds them by manifest.

### 8.6 BLB-side dependency check

BLB's manifest reader walks each loaded plugin's `extra.blb.requires-modules` and `extra.blb.optional-modules`:

- If a **required** BLB module is missing, fail loudly: "blb-payroll-my requires People/Attendance ≥ 1.2. Install or upgrade."
- If an **optional** BLB module is missing, log at info level and continue. Listeners that depend on it are not registered.

This is distinct from composer's PHP-package check: composer answers "is `phpoffice/phpspreadsheet` installed?"; BLB answers "is the `people/attendance` plugin enabled in this deployment?".

### 8.7 Service-provider order

Modules register their service providers in BLB-module dependency order. The framework's existing module bootstrapping is path-based today; it will need to honor the `extra.blb.requires-modules` graph.

---

## 9. Migration Path

### Phase 0 (current)

- All BLB code in one git repo, including current Payroll content.
- `extensions/{licensee}/` already uses nested git.
- No formal event contracts between application modules; cross-module imports happen freely.

### Phase 1 — Establish source-module events in-tree

Goal: source modules emit public events; current Payroll listens; manifest format is in place for Phase 2.

- Define event classes for facts that downstream consumers may care about: `AttendanceAllowanceMaterialized`, `AttendanceOvertimeApproved`, `LeaveUnpaidDayConfirmed`, `ClaimReimbursementApproved`.
- Migrate the existing Attendance → Payroll direct-write path onto event + listener.
- Drop `payroll_pay_item_code` from `AttendanceAllowanceRule`; introduce a payroll-side mapping table.
- Add `composer.json` files to each module under `app/Modules/People/*/`, each declaring its `extra.blb` block (requires-modules, optional-modules, publishes-events, consumes-events).
- Install `wikimedia/composer-merge-plugin` in the root project and configure it to merge `app/Modules/People/*/composer.json` and `extensions/*/*/composer.json`.
- Implement BLB's runtime manifest reader that walks loaded plugins' `extra.blb` and verifies the BLB-module dependency graph.

Exit criterion: source modules dispatch events; Payroll consumes them via listeners; missing required BLB modules fail loudly at boot. No source module imports a payroll class.

### Phase 2 — Extract Payroll as a nested-git plugin

Goal: prove the plug-out shape with the largest real plugin.

- Create a public origin `github.com/BelimbingApp/blb-payroll-my`.
- Move the entire current Payroll content (with the Malaysian schemes, forms, rate tables, UI, listeners) into the new repo's tree at `app/Modules/People/Payroll/`.
- Add the path to the parent repo's `.gitignore`.
- A fresh main-repo clone plus a clone of `blb-payroll-my` reproduces the current application identically.

Exit criterion: removing the `blb-payroll-my` checkout leaves a working HR application (Attendance, Leave, Claim function; payroll surfaces are absent).

### Phase 3 — Build a second country plugin

Goal: validate the event contracts on a real second country.

- Build a minimal `blb-payroll-sg` (even one scheme — CPF — and one form — IR8A). Production-grade is not the goal.
- Watch for places where the event contracts force SG to fight Malaysia-shaped assumptions in event payloads.
- Fix those event payloads in Phase 1 contracts. Re-verify MY.

Exit criterion: someone can build a new payroll plugin without modifying BLB core.

### Phase 4 — Composer-ize

Goal: open the door to external contributors.

- Lift `blb-payroll-my` and the source modules onto composer packages.
- Publish to Packagist or a private Satis.
- Define a contribution guide, a contract-stability policy, a SemVer commitment.

This phase is at least 12–18 months out and should not influence current decisions.

### Phase 5 — Apply the pattern to Finance

When the People domain has shipped and stabilized, repeat for Finance: ship MY-context first (MFRS reporting, statutory accounts) as a plugin built on the same event-driven shape.

---

## 10. Developer Workflow

### 10.1 Working in the main repo

Unchanged from today.

### 10.2 Working on a BLB-owned plugin

Two repos, two pushes:

```
cd belimbing                                    # main repo
cd app/Modules/People/Payroll                   # nested plugin repo (blb-payroll-my)
git checkout -b feature/new-scheme
... edit ...
git commit
git push                                        # pushes to blb-payroll-my origin

cd -                                            # back to main repo
git status                                      # nested repo path is ignored — no diff in parent
```

Releases:
- Plugin tags its version.
- Main repo's setup/install docs note tested plugin versions.
- Eventually (Phase 4), composer takes over the version pinning.

### 10.3 Working on a licensee extension

Identical to the current model. See `docs/guides/extensions/private-extension-repositories.md`.

### 10.4 Working across modules during a contract change

Coordinated edits across source module and plugin:

- Branch in main repo (for the event change) and in the affected plugin (for listener changes).
- Merge in dependency order: source event first, plugin update second.
- Document the required plugin version in the main repo's release notes.

This is the same coordination cost the extension model already incurs.

---

## 11. Open Questions

These do not block Phase 1 but should be settled before later phases.

1. **Namespace strategy at composer-ization.** Stay with `App\Modules\People\Payroll\` or move to vendor-style `Blb\Payroll\My\`? Decision affects Phase 4.
2. **Multi-plugin coexistence.** When `blb-payroll-my` and `blb-payroll-sg` are both installed, how is an employee's country-of-employment determined? Probably an `EmployeeWorkProfile` field; specify the routing rule in Phase 3.
3. **Contract governance.** Solo today, harder later. Document the breaking-change policy before opening to external contributors in Phase 4.
4. **Optional-dependency soft-fail UX.** What does the admin UI show when a deployment has Attendance but no payroll plugin? "Allowances are being recorded but not paid through any installed plugin." Small but real.

---

## 12. What This Document Does Not Cover

- **Implementation details of the boot-time `extra.blb` reader.** Separate design when Phase 1 starts.
- **Composer package layout and autoloading mechanics.** Package asset source uses the `Assets/` contract in this document, but composer-specific publish mechanics are settled during composerization.
- **CI/CD across multiple repos.** Phase 2 surfaces this; address when it does.
- **Versioning policy and release cadence.** Phase 3 surfaces this.
- **The conceptual layer of any specific domain.** Lives in tutorials (`docs/tutorials/{domain}/`) and per-module design docs (`docs/plans/{domain}/`).
- **A "Payroll plugin anatomy" guide for future plugin authors.** Worth writing after `blb-payroll-my` is extracted in Phase 2.

---

## 13. Summary

- BLB ships an integrated HR core (Attendance, Leave, Claim, Settings) plus one reference downstream plugin (`blb-payroll-my`). No "framework Payroll" exists for plugins to specialize into.
- Source modules emit public events. Plugins listen, interpret in their own terms, and produce their own full vertical end-to-end (run lifecycle, calculations, forms, ledger, UI).
- The shared surface is small: source-module event classes and the manifest format. Everything else is plugin-internal.
- Distribution: extend the existing nested-git pattern from `extensions/` to BLB-owned plugins first. Composer later, per plugin, when contracts have stabilized.
- Phases: establish events in-tree → extract Payroll as a plugin → build a second country → composer-ize → apply to Finance.

The trade-off: plugins re-implement run-lifecycle plumbing each. We accept this because the plumbing is mechanical, AI-generation makes it cheap, and the saved duplication would not justify the release-coordination tax of a framework boundary.

The model is the one mature open-source frameworks actually scale on: small public contracts, full ownership inside plugins, the reference implementation as documentation by example.
