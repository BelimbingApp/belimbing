# platform-maintenance-strategy

**Status:** In progress — platform implementation underway; Domain caller pinning, Sonar administration, Windows cutover, and restore-drill evidence require post-merge/external rollout
**Last Updated:** 2026-08-29
**Sources:** `.github/workflows/lint.yml`; `.github/workflows/security.yml`; `.github/workflows/tests.yml`; `.github/workflows/test-audit-report.yml`; `.github/workflows/domain-ci.yml`; `.github/dependabot.yml`; `scripts/ci/domain-repos.json`; `scripts/ci/compose-domain.php`; `scripts/ci/setup-sonar.php`; `sonar-project.properties`; `composer.json`; `package.json`; `app/Base/Schedule`; `app/Base/System/Services/StatusBarDiagnostics.php`; `docs/plans/base-status-bar-diagnostics.md`; `docs/plans/authorable-php-formatting-baseline.md`; `docs/plans/security-hardening.md`; `app/Base/Database/Console/Commands/SchemaDriftCommand.php`; `docs/plans/database-backup-security.md`; `docs/runbooks/database-backup.md`; `docs/ai-team/scripts/gate.sh`; GitHub Protect Main ruleset `11722555`; issue #421; Larastan 3.x repository and Packagist metadata
**Agents:** Amp/GPT-5; Amp/GPT-5.1; kiat-luna/GPT-5

**Revision note:** The 2026-08-07 CI review updates Current Coverage to recognize the platform's SonarQube Cloud automatic analysis and the three existing thin Domain callers. Design Decisions and Phases now distinguish Sonar from Larastan, replace hand-copied Domain workflows with a versioned composed-source contract, and add idempotent bootstrap/verification for Belimbing-controlled distributed Domains. Extensions remain outside Belimbing's repository authority: the platform skill `setting-up-extension-ci` becomes the licensee-facing path to an optional conformance kit, while BLB validates installed compatibility but never provisions or attests an Extension repository's CI. The schema-drift review removes periodic inspection and status-bar projection: drift validation now runs only at migration-source change and migration/deployment boundaries, where it can halt unsafe code before workers reload.

## Problem Essence

Belimbing already performs many maintenance checks, but their execution model is uneven: some repository checks mutate rather than enforce, some safe runtime retention work remains manual or operating-system-specific, and recovery evidence is documented without a reliable product signal. Adding a generic maintenance cron or dashboard would duplicate existing ownership and mix developer concerns with installation health.

## Desired Outcome

Each maintenance concern runs in the narrowest trustworthy environment: deterministic source checks gate pull requests, judgment-heavy repository work is handled by Amp, safe installation-local upkeep runs through Laravel's observable scheduler, and deliberate recovery operations remain operator-triggered. The existing status bar reports only actionable persisted health from the subsystem that owns remediation.

## Current Coverage

| Concern | Current state | Strategy |
| --- | --- | --- |
| PHP formatting | Pint runs on platform push and pull request in write mode, while the separate formatting-baseline plan records hundreds of existing authorable and immutable-history findings. Domain CI correctly uses check mode on its owned path. | Enforce check mode only for changed authorable PHP until the canonical baseline is complete; never rewrite hash-immutable migrations to satisfy CI. Do not add a cron or UI action. |
| Full tests and frontend build | Pest, Vite build, and PostgreSQL mirror integration checks already run in CI. | Keep; do not duplicate in a maintenance scheduler. |
| Dependency vulnerabilities | Composer and Bun audits run on push, pull request, and weekly. | Keep; no additional audit cron. |
| Secret scanning | Gitleaks runs on push, pull request, and weekly. | Keep; no in-app equivalent. |
| Dependency update discovery | Dependabot opens weekly Composer and Bun pull requests. | Keep automation; use Amp for triage, grouping, verification, and major-version decisions rather than adding another updater. |
| Platform code quality | SonarQube Cloud automatic analysis currently publishes a successful `SonarCloud Code Analysis` check on the platform's `main`; the root workflow does not invoke the scanner or upload test coverage. Protect Main now requires that check alongside the five repository checks documented below. | Preserve the existing check while its server-side quality profile, gate, and new-code policy are inventoried. Move to workflow-driven analysis only as an explicit no-duplicate migration when coverage and consistent gate semantics justify it. |
| Domain CI | People, Commerce, and Operation call the platform's reusable `domain-ci.yml`, which composes the source, builds assets, runs Pint check mode, runs scoped Pest with coverage, and submits Sonar analysis. | Keep the central harness, but harden and version it. The current callers' floating `@main`, duplicated inputs, inconsistent manual trigger, limited security checks, and non-reproducible dependency checkout are unfinished distribution infrastructure. |
| Extension CI | Extension repositories are operator/user chosen and outside Belimbing's control. BLB cannot inspect their settings, require workflows, provision credentials, or attest source quality. Inexperienced licensees still need an actionable path to a reasonable baseline. | Ship a platform-owned `setting-up-extension-ci` agent skill as the primary guide. The skill invokes an optional composed conformance workflow/local validator, helps install repository-owned CI, and leaves credentials, enforcement, and quality claims with the Extension owner. |
| Source bootstrap | `compose-domain.php` resolves immediate cross-Domain dependencies and `setup-sonar.php` provisions known Sonar projects/tokens. No tool creates or verifies a controlled Domain repository's workflow, ruleset, CI settings, or future package test shape. | Evolve these into one descriptor-driven, idempotent bootstrap/verify path for Belimbing-controlled Domains rather than maintaining their repository setup by hand. |
| Slow-test and critical mutation reporting | A weekly/manual workflow already uploads both reports. | Keep generation; make periodic Amp review consume the reports instead of adding more report-only automation. |
| Test isolation checks | Pull requests inspect changed tests for unsafe runtime-storage use. | Keep; broaden only when a demonstrated test-isolation failure justifies a rule. |
| Schedule history retention | Pruning already occurs when scheduled runs start, using the configured retention window. | Keep; no second pruning schedule. |
| Queue and runtime diagnostics | Queue failures, FrankenPHP reload state, software/module drift, frontend build staleness, PHP extension drift, performance regressions, reported errors, and filesystem health already contribute authorized status-bar diagnostics. | Keep; do not introduce a generic health provider that repeats these checks. |
| Windows runtime health | Supervised server, queue, scheduler, health checks, and nightly backup are wired. | Preserve during migration to cross-platform Laravel scheduling; avoid duplicate backups. |
| Backup creation and pruning | CLI and UI exist; Windows runs a nightly pruned backup. Managed databases can opt out. | Make Laravel's scheduler the single cross-platform application-backup owner, then retire the duplicate Windows backup task. |
| Backup integrity and restoration | UI can verify artifact hashes; a quarterly restore drill is documented. The current UI and runbook still name a restore command that was deliberately removed. | Correct the stale contract first. Keep restore drills deliberate, isolated, and Amp/runbook-led; hash verification is not restore proof. |
| Schema drift | A deterministic read-only command exists and migration deployment guards detect incubating-source hash drift. Drift becomes possible when migration source or database state changes, not with elapsed time. | Run it as a change-triggered CI and migration/deployment postcondition. Do not schedule it, add a status-bar warning, or auto-repair. |
| Performance-log retention | A safe pruning command exists but is manual. | Schedule it through Base Perf. |
| Integration payload retention | Retention logic and a manual UI action exist but no automatic invocation was found. | Schedule safe payload redaction through Base Integration; keep the UI action as Run now. |
| Data Share package retention | Conservative preview/apply tooling exists; unapplied packages require an explicit option. | Schedule only the conservative applied-package cleanup. Keep unapplied/orphan cleanup manual. |
| PHP code analysis | No PHPStan, Larastan, or Psalm configuration or CI step exists. | Run a bounded Larastan pilot; retain and gate it only if the criteria below hold. |

## Top-Level Components

- **Repository quality contract:** check-only formatting, package-manifest validation, tests, build, security scans, and framework-aware PHP code analysis.
- **Composed Domain CI contract:** one versioned platform-owned harness for Belimbing-controlled Domain repositories with reproducible platform/dependency materialization.
- **Domain bootstrap and verification:** one descriptor and idempotent tool that generates/verifies thin Domain callers and provisions supported GitHub/Sonar settings without inventing Module metadata.
- **Extension CI setup skill:** a platform-owned agent workflow that audits an Extension, explains the minimum standard, installs or repairs owner-controlled CI with approval, and invokes the conformance kit.
- **Extension conformance engine:** an optional public workflow/command and synthetic fixtures providing stable machine-verifiable criteria without central repository access or quality attestation.
- **Subsystem-owned runtime maintenance:** recurring commands registered by Perf, Integration, and other owning providers rather than a central maintenance module; Database backup scheduling remains blocked on stronger serialization and rollout proof.
- **Scheduler liveness:** a cheap diagnostic over the existing durable Schedule run ledger; no second heartbeat command or persistence model.
- **Actionable diagnostics:** status-bar providers read cheap persisted snapshots and link to the owning remediation page; they never perform expensive maintenance during shell rendering.
- **Amp maintenance lane:** dependency currency, baseline reduction, test-quality review, bounded old-file formatting, dead-code review, and restore drills that require engineering judgment.

## Design Decisions

### Classify by where the truth exists

One universal cron is simple to describe but wrong for Belimbing. Pint, Larastan, dependency currency, and tests describe a Git revision; running them inside a deployed application would require development dependencies and source mutation privileges. Backup freshness, scheduler liveness, and schema drift describe one installation; GitHub Actions cannot observe them.

Recommendation: use four execution lanes.

1. **Pull-request CI** for deterministic revision checks that should block merging.
2. **GitHub schedules** only for repository facts that can change without a commit, such as newly disclosed dependency vulnerabilities, and for bounded report generation already present.
3. **Laravel scheduler** for safe, idempotent, installation-local upkeep and read-only inspections.
4. **Amp or explicit operator action** for work requiring triage, source edits, destructive staging, credentials, or interpretation.

### Keep ownership distributed; aggregate only the signal

A new Maintenance module or all-in-one Maintenance page would become a shallow dispatcher over Database, Schedule, Perf, Integration, Software, and CI. It would duplicate capabilities, settings, actions, and remediation links while weakening the four-root ownership model.

Recommendation: do not create a generic maintenance module or page. Each subsystem registers its own scheduled work, stores its own last-result snapshot, and owns its own Run now action where useful. Base Schedule provides execution history, while Base System's existing status bar aggregates only authorized diagnostics.

### Use the status bar for failures, not chores

The status bar is appropriate for conditions an operator can act on now. It is not a task list for formatting, dependency upgrades, test cleanup, or speculative recommendations. Running expensive schema parsing or remote filesystem scans during every shell render would also violate the cheap live-diagnostic contract.

Recommendation: add only two maintenance signals initially:

- no recent scheduler activity recorded, linked to Schedule;
- application backup missing, stale, or last attempt failed when application backups are enabled, linked to Database Backups;

Providers read persisted or cached snapshots produced by commands. Successful routine runs remain visible in Schedule history and do not produce status-bar entries.

Scheduler liveness reuses `base_schedule_runs`: existing every-minute tasks already provide activity, and Base Schedule already records every scheduler event. Absence of recent rows is reported honestly as missing recorded activity rather than proof that the scheduler process is dead, because recorder or database failure can produce the same observation.

Schema drift does not belong in this signal model. A scheduled or shell-visible result arrives after changed migration source is already serving traffic. BLB must instead prove schema alignment inside the migration/deployment transaction boundary and refuse worker reload when that proof fails or is incomplete.

### Keep Sonar as the broad quality service; require incremental value from Larastan

The platform already receives SonarQube Cloud automatic analysis, and each registered Domain already submits a scoped Sonar scan with Pest coverage. Replacing Sonar with Larastan would lose maintainability, duplication, security, and cross-language analysis. Running both without distinct contracts would create duplicate findings and unclear merge authority.

Recommendation: Sonar remains the broad code-quality and security service. First inventory the server-owned platform quality profile, assigned gate, new-code definition, and pull-request decoration. The required-check portion of that contract is recorded below and enforced by the active Protect Main ruleset; the repository should record the intended policy even when SonarCloud owns the implementation.

Automatic platform analysis is acceptable while it produces a reliable gate and secret-free pull-request coverage. Workflow-driven platform analysis becomes preferable if Belimbing wants test coverage in Sonar, one scanner configuration across platform and distributed sources, or explicit quality-gate wait/failure semantics. Migration must be atomic: configure the workflow and branch check, prove it, then disable automatic analysis so one commit never produces competing Sonar analyses.

### Make Protect Main enforce the revision checks

The repository's `Protect Main` ruleset previously enforced pull-request shape,
code quality, and Copilot review settings but had no `required_status_checks`
rule. That left the six checks below advisory and made `gate.sh` the only
merge-time defense against a red revision. The platform rule now carries the
same exact contexts observed on the merged `main` revision, with GitHub Actions
integration ID `15368` for the five workflow checks and SonarCloud integration
ID `12526` for its check.

The chosen enforcement contract is strict head freshness
(`strict_required_status_checks_policy: true`): a pull request must contain the
current `main` tip before its checks can satisfy the rule. No merge bypass actors
are configured. This keeps the normal merge path subject to the same proof for
repository administrators, repository roles, and integrations; an emergency
owner can still change the ruleset itself through GitHub administration rather
than silently bypassing a failing revision.

The six required contexts are:

- `ci` (GitHub Actions, integration `15368`)
- `quality` (GitHub Actions, integration `15368`)
- `postgres-mirror` (GitHub Actions, integration `15368`)
- `Secret scan` (GitHub Actions, integration `15368`)
- `Dependency audit` (GitHub Actions, integration `15368`)
- `SonarCloud Code Analysis` (SonarCloud, integration `12526`)

The current repository does not use a merge queue. If one is enabled later,
the queue's temporary merge-group revision must report these same contexts;
the workflows must first opt into the `merge_group` event and the ruleset must
be observed on a real queued pull request before queue merges are treated as
covered. Strictness remains enabled because it prevents a check result from
proving a tree that no longer contains `main`.

Larastan has a narrower candidate role: Laravel-aware type and call-contract analysis before runtime. Adopt it only if the pilot finds high-confidence defects or useful type debt that the current Sonar profile does not report. If retained, Larastan is a required CI command whose output may be linked from CI; Sonar remains the quality trend/gate. Do not import Larastan findings into Sonar unless a later measurement proves that a single display reduces rather than duplicates triage.

### Make controlled Domain CI generated, versioned, and reproducible

The current thin Domain callers are directionally correct: source repositories should not copy the platform's build logic, and they correctly test inside a composed Laravel checkout. Their contract is still too manual. Each caller repeats path and Sonar identity, points at the mutable platform `main`, and relies on immediate shallow clones of dependency default branches. Commerce also differs from the other callers only by omitting manual dispatch.

Recommendation: evolve `domain-ci.yml` into a versioned composed Domain workflow. A caller supplies one stable Domain ID and a pinned workflow release; the platform-owned Domain descriptor resolves repository, mount path, Sonar identity, and compatible platform/dependency refs. The workflow validates that the caller repository and checkout path match the descriptor before running anything.

The descriptor replaces duplicated repository-level CI identity, not Module manifests. Module-owned `composer.json` remains the authority for stable Module IDs, versions, dependencies, and events. Bootstrap tooling may create a minimal manifest for a genuinely new Module from explicit inputs, but must never infer or rewrite business dependencies in existing Modules.

The composition resolver should resolve incrementally: pin and fetch the source under test, read its manifests, resolve and fetch each declared Domain dependency at an exact ref, inspect those manifests recursively, detect cycles, then emit the final lock-like materialization plan before application boot/install/tests. CI artifacts should retain exact platform/source/dependency SHAs and mount paths so a failed composition can be reproduced.

Use an idempotent platform command/script rather than a GitHub repository template as the source of truth for repositories Belimbing controls. Templates drift after repository creation; a bootstrap with `apply` and `verify` modes can continuously enforce the current Domain contract. Given an explicit Domain descriptor, it should:

- generate or verify the minimal caller workflow;
- provision or verify the Sonar project and token visibility;
- provision or verify Actions permissions, Testing environment, ruleset/required checks, and optional manual dispatch policy where credentials permit;
- install or verify Domain-appropriate secret scanning and dependency policy;
- report repository settings as unverifiable rather than silently passing when the token lacks access;
- support nested-Git materialization now and a packaged release artifact later through the same mount-path contract.

Cross-repository writes remain an explicit operator/Amp action. Platform CI runs only verify mode when a descriptor or reusable workflow changes; it does not silently push workflow commits into other repositories.

Extensions use the application contract but are not members of this managed repository system. BLB should publish an optional conformance entrypoint that an Extension owner can call from its own CI using a released platform ref. It may validate manifest shape, mount-path discovery, migrations, assets, tests, and compatibility without receiving Belimbing organization secrets. Passing that workflow is evidence controlled by the Extension owner, not a platform guarantee.

Platform CI proves the conformance kit against synthetic public fixtures representing supported Extension shapes. At installation/update time, BLB validates only facts it can observe locally—manifest compatibility, module dependencies, migration safety, discovery, and runtime contract. It must never imply that an installed Extension has passed Sonar, secret scanning, dependency review, or any remote CI it cannot verify.

The licensee-facing interface should be the project skill `setting-up-extension-ci`, not a page of criteria. Licensees normally work on an Extension inside a composed Belimbing checkout, so the platform skill is discoverable without copying it into every Extension repository. One platform-owned skill can evolve with the conformance contract; copied Extension-local skills would drift and falsely appear authoritative after the platform changes.

The skill should audit before editing, preserve any useful existing CI, and select the smallest baseline the repository can honestly run. Its minimum GitHub profile should cover Extension-scoped syntax, authorable Pint check mode, manifest/compatibility validation, composed Pest tests, owned asset build when assets exist, Gitleaks, and migration postconditions when migration source changes. It should run dependency audits only when the Extension owns the corresponding package manifest and lockfile; it must not report the platform's dependency audit as proof about Extension-owned dependencies. Larastan joins the profile only if Phase 3 adopts it. Sonar remains optional because BLB cannot require an external account or token from a licensee.

The skill may create or update a thin workflow in the Extension repository after showing the intended changes. It must not overwrite unrelated jobs, request secrets it does not need, weaken an existing stricter policy, configure remote rulesets, commit, or push without explicit authority. When GitHub Actions is unavailable, it should leave the same commands as a documented local/pre-release checklist rather than inventing another CI product abstraction.

The conformance engine supplies deterministic pass/fail results; the skill supplies diagnosis and remediation. Neither makes an Extension “BLB certified.” The strongest truthful claim is that a named Extension revision passed a named conformance-contract version in its owner's environment.

### Adopt Larastan only as a framework-aware developer tool

Plain PHPStan would produce avoidable noise around Eloquent, facades, and container resolution. Larastan 3.10 explicitly supports Illuminate/Laravel 13, PHP 8.5 satisfies its PHP requirement, and its PHPStan 2.2 dependency supports PHP 8.5. It boots the Laravel container, which matches Belimbing better than pretending the application is framework-free. Compatibility alone is not enough: the pilot must also prove incremental value beyond the active Sonar profile.

Recommendation: Larastan is architecturally suitable as a development-only repository analyzer, subject to a representative pilot. It must not become an application service, scheduled runtime command, settings option, status-bar diagnostic, or user-facing UI.

The pilot must prove all of the following before adoption:

- it boots the composed platform without writing production-like state or requiring optional Domain/Extension repositories;
- it handles representative Eloquent, Livewire, service-container, console, and provider-discovery code with a manageable signal-to-noise ratio;
- suppressions are narrow, identifier-based where possible, and explain a real framework-analysis limitation;
- no generated IDE-helper files or broad source exclusions are required to manufacture a green result;
- the baseline, if needed, contains triaged existing debt and cannot grow in CI.

The root platform analysis should cover tracked production PHP under `app/Base` and `app/Core`; tests are excluded from the first contract because Pest and test doubles have a different dynamic surface. Optional Domains are analyzed by the reusable composed Domain CI against the platform, preserving optional-module ownership. Extensions should receive the same composed-check pattern when an Extension CI contract exists.

Start at a moderate rule level rather than maximum strictness. Amp should first fix high-confidence type defects, then baseline only the remaining categorized debt. Raise strictness or add tests to analysis only after measured findings justify the cost.

### Keep destructive and recovery work deliberate

Automatic restore testing inside the live application would require broad database-creation and restore credentials and could turn a maintenance feature into a destructive primitive. An operator checkbox saying a drill passed would be unverifiable and therefore dishonest.

Recommendation: keep restore drills outside the product execution path. Add only a non-destructive decrypt/staging command that turns a selected encrypted artifact into a new operator-chosen output file, refuses overwrite, and never connects to a target database. Amp may then run the documented quarterly drill against a fresh isolated target, collect the command evidence, and help diagnose failure. The Backups UI continues to create and hash-verify artifacts, explains that integrity is not restorability, and points to the corrected manual runbook. No restore button or automatic database promotion is added.

## Public Contract

- `composer lint` remains the local write-mode formatting command; CI uses Pint's check mode.
- Composer package metadata is validated strictly in pull-request CI.
- SonarQube Cloud remains the broad quality/security analysis contract. Platform automatic analysis and workflow-driven analysis are mutually exclusive modes; any migration switches once and preserves one stable required check.
- Protect Main requires the six revision checks listed in this plan, uses strict head freshness, and has no merge bypass actors; `docs/ai-team/scripts/gate.sh` remains the richer exact-head/ownership pre-flight.
- Every Belimbing-controlled Domain is represented by one platform-owned descriptor with stable Domain ID, repository, mount path, Sonar identity, and compatibility/ref policy. Module identities and dependencies remain in Module manifests.
- Controlled Domain callers contain only triggers, permissions, a pinned reusable-workflow reference, and their stable Domain ID. Generated caller drift fails bootstrap verification.
- The target composed Domain CI contract records exact source refs and runs build, authorable Pint, Pest, Sonar, security/secret checks, manifest validation, migration checks, and Larastan when adopted. The current harness delivers exact materialization, build, Domain-scoped Pint/Pest, and Sonar; the remaining checks stay explicit rollout work below.
- Extension repositories are never centrally registered or attested. BLB publishes `setting-up-extension-ci` plus an optional, secret-independent conformance entrypoint; Extension owners retain full responsibility for repository CI and source quality.
- The Extension skill audits before editing, preserves stricter existing checks, never requires Sonar, scopes dependency claims to Extension-owned lockfiles, and never commits, pushes, changes remote settings, or handles secrets without explicit authority.
- Larastan, if the pilot passes and proves value beyond Sonar, is exposed through one Composer script and one shared configuration. A clean exit is required in platform pull requests and composed-source CI; no second analyzer configuration is invented per module.
- Every recurring application task is registered by its owning provider with a stable command identity, overlap protection, and Schedule history visibility.
- Scheduler liveness reads the latest existing Schedule ledger activity and reports only that no recent activity was recorded.
- APP_KEY fingerprint validation lives at the shared backup service boundary. Backup scheduling remains blocked until lease-safe serialization and durable attempt recording also live at that boundary for CLI, UI, Data Share, and scheduled callers.
- Application backups move to one canonical schedule only after the legacy Windows task and optional off-box replication path have an explicit cutover; managed-database installations remain opted out.
- Schema drift inspection is read-only, connection-aware, and runs unconditionally after every successful non-pretend BLB migration. Confirmed drift and incomplete inspection both fail before source baselines/approvals are finalized or workers reload; neither triggers DDL, migration edits, automatic repair, or a status-bar diagnostic.
- Status-bar diagnostics enforce the same capability as their target page, reveal no credentials or database contents, and disappear when a fresh healthy snapshot replaces the failing state.
- Safe retention jobs delete only data already authorized by their existing retention policy. Ambiguous, unapplied, orphaned, held, or unresolved records remain manual-review territory.

## Phases

### Phase 1 — Lock the inventory and correct misleading contracts

Goal: Maintenance work starts from current behavior and does not duplicate completed systems.

- [x] Inventory platform CI, GitHub schedules, Dependabot, Laravel schedules, Windows runtime tasks, retention commands, status-bar providers, backup UI/runbook, and schema drift. {Amp/GPT-5}
- [x] Classify already-covered suggestions and remove them from proposed implementation scope. {Amp/GPT-5}
- [x] Confirm current Larastan compatibility with Laravel 13, PHP 8.5, and PHPStan 2.2 from upstream package metadata. {Amp/GPT-5}
- [x] Reconcile the active restore runbook and Database Backups help text with the deliberate removal of `blb:db:backup:restore`; leave one truthful manual restore drill. The broader backup-security plan remains open for its unrelated rollout items. {Amp/GPT-5.1}
- [x] Add a non-destructive backup decrypt/staging command for app-key and plaintext artifacts; refuse overwrite and never write to a database. {Amp/GPT-5.1}
- [ ] Confirm the production deployment matrix that currently relies on the Windows nightly backup before changing its ownership.

Validation: source search finds no user-facing instruction for a nonexistent restore command, and each current maintenance mechanism has one declared owner.

### Phase 2 — Make repository CI enforce rather than mutate

Goal: Pull requests fail on repository defects without rewriting the runner checkout or duplicating scheduled security work.

- [x] Change platform Pint CI to check only changed authorable PHP files, preserving recorded immutable migrations until the formatting-baseline plan completes; reduce workflow permissions to read-only. {Amp/GPT-5.1}
- [x] Add strict Composer manifest validation to pull-request CI. {Amp/GPT-5.1}
- [ ] Record the platform SonarQube Cloud project, quality profile, assigned quality gate, and new-code definition without copying server secrets into source.
- [x] Make Protect Main require the six observed CI/Sonar contexts with strict head freshness, no merge bypass actors, and a documented merge-queue decision. {kiat-luna/GPT-5}
- [x] Retain automatic platform Sonar analysis: the existing check is successful and no evidence yet justifies duplicate workflow scanning or a hosted-settings migration. The remaining profile/gate inventory stays external administration work. {Amp/GPT-5}
- [x] Keep existing Pest, build, PostgreSQL mirror, dependency-audit, Gitleaks, Dependabot, slow-test, mutation, and changed-test checks; add only the migration-source postcondition to the existing test job. {Amp/GPT-5.1}
- [ ] Document the local distinction between write-mode formatting and CI check mode in the smallest existing contributor surface.

Validation: an intentionally misformatted fixture causes the lint job to fail without modifying the checkout; valid Composer metadata passes strict validation; one and only one Sonar analysis/check evaluates a platform revision and its merge-enforcement status is documented.

### Phase 3 — Larastan pilot rejected for now

Goal: Belimbing gains useful framework-aware code analysis without a suppression-heavy parallel type system.

- [x] Temporarily install Larastan 3.10 and run a representative level-5 pilot across Base System, Base Database schema inspection, Base Livewire, and Core Company. {Amp/GPT-5}
- [x] Reject and remove Larastan: the bounded pilot produced 180 findings dominated by Eloquent dynamic-property/relation inference and would require a broad baseline or pervasive model annotations before it could become a trustworthy gate. {Amp/GPT-5}
- [ ] Reconsider only after model metadata becomes a first-class generated or source-owned contract, or after a narrower future Larastan release materially improves this signal-to-noise ratio.

Validation: the dependency, configuration, and provisional Composer command are absent after the rejected pilot; Sonar remains the sole static quality service and no suppression baseline is introduced.

### Phase 4 — Automate controlled Domain CI and publish Extension conformance

Goal: Current and future Belimbing-controlled Domains receive reproducible CI, while independent Extension owners can opt into the same application compatibility checks without surrendering repository control.

- [x] Generalize `domain-repos.json` into a validated descriptor for Belimbing-controlled Domains while preserving stable IDs and mount paths. {Amp/GPT-5.1}
- [ ] Refactor `compose-domain.php` into a Domain materializer that resolves transitive dependencies, detects cycles, honors compatibility/ref policy, and emits exact refs before checkout.
- [x] Prepare a versioned reusable composed-source workflow and local caller verification; replace callers' floating `@main` only in separate Domain PRs after an immutable platform ref exists. {Amp/GPT-5.1}
- [ ] Reduce each caller to triggers, least privileges, pinned workflow reference, and stable source ID; make manual-dispatch policy consistent and intentional.
- [ ] Bring People, Commerce, and Operation onto the complete baseline: authorable Pint scope, scoped tests/coverage, Sonar quality result, secret scanning, manifest validation, and adopted Larastan analysis without duplicating platform dependency audits that already cover the shared lockfiles.
- [ ] Split Domain-CI tooling into pure local render/verify/materialize behavior and credentialed remote audit/apply behavior; platform CI runs only pure verification and clearly reports settings the credential cannot inspect.
- [x] Make platform CI run descriptor verification when source descriptors or CI scripts change; never let CI push cross-repository edits. {Amp/GPT-5.1}
- [x] Publish a secret-independent, versioned Extension conformance workflow/command that composes an explicitly supplied Extension against an exact caller-supplied platform commit and returns compatibility evidence to the Extension owner's CI. Release-channel compatibility policy remains future work. {Amp/GPT-5.1}
- [x] Create the project skill `setting-up-extension-ci` with an audit-first workflow for inexperienced licensees: detect the Extension shape and owned dependency files, explain the proposed baseline, preserve stricter existing CI, apply approved workflow changes, run the conformance engine, and return actionable failures. {Amp/GPT-5.1}
- [x] Keep the skill's minimum profile explicit and bounded: syntax, authorable Pint, manifest/compatibility validation, composed tests, conditional asset build, Gitleaks, migration proof, and adopted Larastan only if it passes a future platform pilot; keep Sonar and other hosted services optional. {Amp/GPT-5.1}
- [x] Add skill guardrails against unsupported quality claims, platform-lockfile proxy claims, secret collection, unrelated workflow replacement, remote-setting mutation, commit, and push. {Amp/GPT-5.1}
- [ ] Add synthetic Extension fixtures covering conventional and multi-Module layouts, invalid manifests, incompatible platform constraints, migration failures, and owned assets without referencing or accessing real Extension repositories.
- [ ] Defer package-materialization fixtures until a real package format and release channel exist; preserve mount-path independence in the current nested-Git contract.
- [ ] Reconcile and complete the controlled-Domain CI rows in `authorable-php-formatting-baseline.md` and `security-hardening.md`; reclassify Extension CI rows as owner guidance/conformance tooling rather than platform implementation work.

Validation: local bootstrap verification passes for all controlled Domain descriptors; changing a generated caller, mount path, or Sonar identity fails verification; a Domain and its transitive dependencies are composed at recorded exact SHAs; the deterministic workflow helper upgrades a weak synthetic Extension workflow without weakening a stricter one and the skill explains a failing fixture; synthetic Extension fixtures prove the public conformance contract without external repository access.

### Phase 5 — Establish one observable runtime maintenance clock

Goal: Safe installation maintenance works consistently across supported deployments and operators can tell when the scheduler is not running.

Affected pages: Administration → System → Schedule; any authorized page with the status bar.

- [x] Add a Base Schedule status-bar provider backed by recent `base_schedule_runs` activity; describe missing recorded activity without claiming proof that the scheduler process is dead, and avoid warning before the first recorded run. {Amp/GPT-5.1}
- [ ] Add lease-safe installation-wide serialization and a durable attempt snapshot at the shared backup service boundary before registering any new schedule. Common APP_KEY fingerprint validation is now enforced there. A fixed one-hour cache lease was tested and rejected because supported dump/upload work can outlive it. {Amp/GPT-5.1}
- [ ] Define canonical backup cadence, timezone, first-run grace, stale threshold, and off-box storage behavior; do not enable the Laravel backup event until the Windows task cutover is externally proven.
- [x] Make `perf:prune` validate retention input and report deletion failure accurately, then schedule it through Base Perf. {Amp/GPT-5.1}
- [x] Make outbound payload pruning mutation-safe, expose a stable Base Integration command, and schedule it while retaining the authorized Run now UI action. {Amp/GPT-5.1}
- [ ] Add an exact safe Data Share retention mode before scheduling; do not claim the current default is applied-package-only because it also removes abandoned receiving uploads.
- [ ] Prove every task appears in Schedule history and that one task failure does not stop later scheduler ticks.

Validation: Schedule lists each enabled safe-retention task with stable identity and next run; recent ledger activity clears the scheduler diagnostic; retention failure is recorded honestly; backup concurrency is serialized across scheduled and direct callers. Backup scheduling remains disabled until one Windows deployment proves exactly one owner and preserved durable/off-box storage.

### Phase 6 — Surface backup freshness and gate schema changes before runtime

Goal: Operators see actionable backup risk, while migration-source drift is rejected before changed code reaches workers rather than reported later.

Affected pages: Administration → System → Database Backups; any authorized page with the status bar; Administration → System → Updates for deployment failure evidence.

- [ ] Add a Database-owned backup health snapshot updated by scheduled, CLI, and UI attempts, recording only safe outcome metadata and latest successful manifest time.
- [ ] Add an authorized status-bar provider for enabled application backups that are missing, stale beyond the declared cadence/grace window, or most recently failed; disabled managed-database backups remain healthy by policy and produce no warning.
- [x] In pull-request CI, run the custom migration path (which includes `blb:schema:drift`) against the fully migrated disposable database when migration sources or the drift inspector change; treat confirmed drift and incomplete inspection as failures. {Amp/GPT-5.1}
- [x] Make schema drift inspection connection-aware and run it unconditionally after every successful non-pretend `migrate` and `migrate --dev`. {Amp/GPT-5.1}
- [x] Finalize migration-source baselines and consume approvals only after migration and drift both succeed; remove the current unconditional failure-path bookkeeping. {Amp/GPT-5.1}
- [ ] Prove the existing Update/deployment propagation naturally halts before worker reload when migrate returns non-zero; do not add a second Software-layer inspector.
- [ ] Keep repair outside the migration gate: local/testing repair follows the schema-drift skill and `migrate --dev`; stable production/staging history is repaired through a forward migration and never automatic DDL.
- [x] Document that pulling application source without running BLB's migration/deployment path is unsupported; cron and the status bar are not fallback deployment guards. {Amp/GPT-5.1}
- [x] Clarify in the Backups UI that manifest hash verification proves integrity, not successful restoration, and point to the corrected isolated drill. {Amp/GPT-5.1}

Validation: status providers perform no schema parsing or remote artifact scan during shell render; fresh backup snapshots clear warnings; disabled managed backups never create false alarms; a changed migration that produces drift or incomplete inspection halts CI/deployment before source bookkeeping or worker reload and cannot mutate the database through the inspector.

### Phase 7 — Use Amp for judgment-heavy maintenance

Goal: Recurring reports and dependency automation result in reviewed improvements rather than unattended churn.

- [x] Schedule a monthly Amp run on the first Monday that reviews Dependabot state, direct dependency currency, security workflow results, and the latest slow-test/mutation artifacts; it groups safe minor/patch work and records major upgrades as explicit decisions. {Amp/GPT-5}
- [x] Include composed-source bootstrap verification and Sonar quality-gate health in the monthly review; repair central tooling rather than patching generated callers independently. {Amp/GPT-5}
- [x] Schedule a quarterly Amp test-suite audit on the first Tuesday by coherent module, using the existing audit skill and reports; do not create a second report workflow. See the dedicated [scheduled audit thread](https://ampcode.com/threads/T-019fda1c-4a3d-731d-b26b-c406bc102582). {Amp/GPT-5}
- [x] Schedule a quarterly Amp restore-drill review on the second Tuesday; it may operate only on an explicitly available isolated target and otherwise returns a prerequisite checklist. See the dedicated [scheduled restore thread](https://ampcode.com/threads/T-019fda21-1640-717c-86fe-4b7e195bd0f7). {Amp/GPT-5}
- [ ] Treat old-file Pint cleanup, dead-code review, architecture-boundary review, and Larastan baseline reduction as on-demand bounded module passes, each separate from behavior changes.
- [ ] Do not schedule Amp source changes automatically until the repository's review/commit/push authority for that run is explicit.

Validation: each Amp run has a narrow prompt, consumes existing evidence before generating new scans, and leaves reviewable bounded changes or a no-change report rather than unattended source mutation.

### Phase 8 — Rollout and noise review

Goal: Maintenance is trustworthy in practice and does not turn the status bar or scheduler into background noise.

- [ ] Roll out CI enforcement before runtime scheduling so repository defects are caught independently of installation health.
- [ ] Land composed Domain workflow/bootstrap changes in the platform first, then regenerate and verify controlled Domain callers; publish Extension conformance separately without onboarding or modifying external repositories.
- [ ] Roll out the Schedule-ledger diagnostic before moving additional retention ownership onto Laravel scheduling.
- [ ] Exercise backup, retention, scheduler-failure, and migration-postcondition scenarios on disposable local/testing data and one representative Windows deployment.
- [ ] Review diagnostic frequency after real use; adjust grace windows at the owning provider rather than adding global acknowledgement or snooze.
- [ ] Update this plan, the status-bar diagnostics plan, owning architecture docs, and runbooks with final evidence and remove superseded deployment instructions.

Validation: CI is green, scheduled tasks have observable successful runs, backup/scheduler failure simulations produce one actionable diagnostic each, migration drift stops before worker reload, healthy recovery clears runtime diagnostics, and no source-quality concern appears in the deployed status bar.
