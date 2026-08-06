# Payroll Module Source Extraction Runbook

**Document Type:** Operational runbook
**Scope:** Extracting `app/Domains/People/Payroll` from the People Domain repository into a standalone nested Git source (`blb-payroll-my`) mounted at the same Module slot
**Last Updated:** 2026-08-05
**Status:** Not executed. Run only when the Payroll slot decision and repository are approved.

## Outcome

Payroll currently belongs to the optional People Domain source. This procedure changes only its Git ownership: the stable Module remains:

- path: `app/Domains/People/Payroll`;
- namespace: `App\Domains\People\Payroll`;
- Module ID: `people/payroll`.

After extraction, the People repository stops tracking the `Payroll/` directory and a selected Payroll source fills that exact slot as a nested repository. Dependents continue to use Payroll's documented intake/events/contracts; they never identify the implementation by its Git remote.

This is a whole-Module slot extraction, not an independent enable/disable toggle inside the People Domain. A deployment without a Payroll implementation omits the slot source. Switching implementations on a live database remains a data-migration project.

## Prerequisites

- The People producer Modules do not import Payroll implementation classes outside the documented intake/event boundary.
- All `*DoesNotImportPayrollTest` tests and `app/Domains/People/Payroll/Tests/Feature/PayrollIntakeBoundaryTest.php` pass.
- `git filter-repo` is installed locally.
- `github.com/BelimbingApp/blb-payroll-my` exists and is empty.
- The People source repository (`BelimbingApp/blb-people`) is clean and pushed.
- You have a throwaway clone of the People repository. Never rewrite history in the primary composed checkout.
- The compatibility-safe landing order and temporary CI behavior are agreed before either repository is pushed.

## Procedure

### 1. Extract Payroll history from the People repository

The People repository root corresponds to `app/Domains/People` in a composed checkout, so `Payroll/` is the path to filter:

```bash
git clone git@github.com:BelimbingApp/blb-people.git /tmp/blb-payroll-extract
cd /tmp/blb-payroll-extract

git filter-repo \
    --path Payroll/ \
    --path-rename Payroll/:
```

The extracted repository root should directly contain Payroll's Module files, including its co-located tests:

```text
/tmp/blb-payroll-extract/
├── Config/
├── Contracts/
├── CountryPacks/
├── Database/
├── Listeners/
├── Livewire/
├── Models/
├── Routes/
├── Services/
├── Tests/
├── Views/
├── ServiceProvider.php
└── composer.json
```

Verify the history contains Payroll changes rather than the full People log:

```bash
git log --oneline | wc -l
git status --short
```

Do not introduce a `src/` wrapper. The repository root must mount directly at the canonical Module path so its existing PSR-4 mapping, views, migrations, tests, and discovery surfaces remain unchanged.

### 2. Push the extracted source

```bash
cd /tmp/blb-payroll-extract
git remote remove origin
git remote add origin git@github.com:BelimbingApp/blb-payroll-my.git
git branch -M main
git push -u origin main
git push --tags
```

Inspect the remote file tree and history before modifying the People repository.

### 3. Stop tracking the slot in the People repository

In a clean People repository checkout, add this source-boundary rule to its `.gitignore`:

```gitignore
# The people/payroll slot is supplied by a separate nested source.
/Payroll/
```

Then remove Payroll from the People repository index:

```bash
git rm -r --cached Payroll/
git commit -m "chore: extract people/payroll source"
```

`git rm --cached` leaves the current files on disk, but the subsequent nested clone needs an empty target. After verifying the extraction remote and preserving any local changes, remove or relocate that untracked working copy before cloning. Do not delete it until its contents and Git status are proven recoverable.

The platform repository needs no new ignore rule: it already ignores the optional Domain checkout at `app/Domains/People`. The People source owns the nested slot boundary.

### 4. Mount the Payroll source at the canonical slot

From the composed Belimbing checkout:

```bash
git clone git@github.com:BelimbingApp/blb-payroll-my.git app/Domains/People/Payroll
```

Verify both repository boundaries:

```bash
git -C app/Domains/People remote -v
git -C app/Domains/People/Payroll remote -v
git -C app/Domains/People/Payroll status -sb
```

The inner remote must be `blb-payroll-my`; the outer remote must remain `blb-people`.

### 5. Prove discovery and boundaries

Run the composed migration and test flows from the platform root:

```bash
php artisan migrate --dev
php artisan test app/Domains/People/Payroll/Tests
php artisan test app/Domains/People/Tests
```

Then run the full People source suite used by its CI. Expected behavior is identical to the pre-extraction baseline:

- `App\Domains\People\Payroll\ServiceProvider` is discovered;
- Payroll migrations and seeders load through the standard Domain Module patterns;
- `people/payroll` remains the manifest identity;
- Module-owned views and assets resolve from the same path;
- producer boundary guards remain green.

### 6. Update CI and setup automation

Any CI job that expects the complete People Domain must clone Payroll before running the People suite:

```yaml
- name: Clone Payroll Module source
  run: git clone --depth 1 https://github.com/BelimbingApp/blb-payroll-my.git app/Domains/People/Payroll
```

If the Payroll repository is private, use the deployment's approved read credential and avoid printing it. Pinning a tag or commit is preferable for reproducible release jobs.

Update composed-checkout setup and `.agents/skills/blb-repo-sync/SKILL.md` so the sync order becomes platform → People Domain → Payroll slot → Extensions. A parent source must exist before a nested source can be mounted below it.

### 7. Update operator and contributor documentation

Document:

- how a fresh deployment obtains the selected `people/payroll` source;
- which Payroll revision belongs to each platform/People release;
- the multi-repository change and review workflow;
- the supported no-Payroll composition, if it remains a product option;
- data migration requirements before changing slot implementations.

Source/repository details belong in setup, update, and diagnostics documentation. Operator business surfaces continue to present the People Domain and Payroll Module.

## Landing Order

Avoid a period where the People repository no longer contains Payroll but CI/setup still assumes it does.

1. Push and verify `blb-payroll-my`.
2. Land platform/setup/CI support capable of cloning the new source while the old tracked path still works.
3. Land the People repository commit that stops tracking `Payroll/`.
4. Update deployment composition pins and run the complete verification suite.

If repository protections require a different order, use a temporary compatibility branch or pinned commit explicitly; do not support two runtime filesystem paths.

## Rollback

If extraction must be undone:

1. In the People repository, revert the commit that removed `Payroll/` and its `.gitignore` rule.
2. Remove or relocate the nested Payroll checkout only after proving it is clean and pushed.
3. Restore the tracked `Payroll/` directory from the People commit before extraction.
4. Remove the temporary CI clone step.
5. Run the composed People migration and test suite again.

The standalone Payroll repository may remain archived for a later retry; deleting the remote is unnecessary.

## Contributor Workflow After Extraction

Payroll work happens inside its nested repository:

```bash
cd app/Domains/People/Payroll
git status
git commit -m "Payroll change"
git push origin main
```

People producer work happens one repository above. A change spanning both boundaries needs separate commits, reviews, pushes, and an explicit landing order.

Prefer compatibility sequencing:

1. land an additive producer event or contract change;
2. update Payroll to consume it;
3. remove deprecated producer behavior only after every supported Payroll source has migrated.

## What Does Not Change

- The canonical Module path, namespace, and stable ID.
- Base → Core → enabled Domains → Extensions discovery order.
- Payroll's provider, migrations, routes, config, views, and test contracts.
- The People Domain's install/enable/disable/update lifecycle.
- The rule that code removal and persistent-data cleanup are separate decisions.

## Verify the No-Payroll Composition

Only run this check if omitting the Payroll slot is a supported deployment composition. Start from a clean People checkout without the nested Payroll source:

```bash
php artisan migrate --dev
php artisan test app/Domains/People/Attendance/Tests
php artisan test app/Domains/People/Leave/Tests
php artisan test app/Domains/People/Claim/Tests
php artisan test app/Domains/People/Settings/Tests
php artisan test app/Domains/People/Employees/Tests
```

Producer Modules must boot and complete their own behavior without Payroll listeners. Tests whose purpose is Payroll integration should be skipped by composition or run only after the slot source is mounted; unrelated failures indicate the contract boundary regressed.

## Future Package Delivery

A future package source may replace the nested Git checkout if it mounts the same canonical Module root and preserves `App\Domains\People\Payroll`, `people/payroll`, migrations, views, tests, and manifests. Changing delivery mechanism must not change business identity or consumer contracts.

## See Also

- `docs/architecture/module-system.md` — Domain, Module, source, and slot contracts.
- `docs/guides/extensions/private-extension-repositories.md` — nested private-source safety pattern.
- `docs/plans/people/12_attendance-event-decoupling.md` through `17_claim-pay-item-mapping.md` — historical prerequisite decoupling work.
