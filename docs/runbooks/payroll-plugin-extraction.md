# Payroll Plugin Extraction Runbook

**Document Type:** Operational runbook
**Scope:** Extracting `app/Modules/People/Payroll/` from the main BLB repo into a standalone nested-git repo (`blb-payroll-my`).
**Last Updated:** 2026-05-16
**Status:** Not executed. This runbook documents the procedure; the actual extraction will be performed when the architecture-spec Phase 2 milestone lands.

---

## What this is

After Plans 12–17, Attendance, Leave, Claim, Settings, and Employees do not import anything from `App\Modules\People\Payroll\`. The plug-out experiment proves the source modules function when Payroll's `ServiceProvider` is disabled. The next milestone is **physical extraction** — Payroll becomes its own git repository at `github.com/BelimbingApp/blb-payroll-my`, cloned into the same canonical path (`app/Modules/People/Payroll/`) via the nested-git pattern already used by `extensions/{licensee}/`.

When this is done, "uninstall Payroll" becomes `rm -rf app/Modules/People/Payroll/` — no main-repo commit required.

## Prerequisites

- Plans 12, 13, 14, 15, 16, 17 complete and verified.
- All five `*DoesNotImportPayrollTest` plus `PayrollIntakeBoundaryTest` green.
- `git filter-repo` installed locally (`pip install git-filter-repo` or `brew install git-filter-repo`). `git subtree split` is an alternative but produces less clean history.
- A public origin created: `github.com/BelimbingApp/blb-payroll-my` (empty, no initial commit).
- A throwaway working copy of the main repo — do not run the extraction in your primary checkout.

## Procedure

### Step 1 — extract history into a temporary directory

Clone the main repo into a scratch path. From there, `git filter-repo` rewrites history to contain only `app/Modules/People/Payroll/`.

```bash
git clone git@github.com:BelimbingApp/belimbing.git /tmp/blb-payroll-extract
cd /tmp/blb-payroll-extract

git filter-repo \
    --path app/Modules/People/Payroll/ \
    --path-rename app/Modules/People/Payroll/:
```

The `--path-rename` line strips the leading `app/Modules/People/Payroll/` so the resulting repo's root maps cleanly back to `app/Modules/People/Payroll/` on checkout. Verify:

```bash
ls
# Should show: Config/ Console/ Contracts/ CountryPacks/ Database/ Listeners/ Livewire/ Models/ Routes/ Services/ ServiceProvider.php composer.json ...

git log --oneline | wc -l
# Should be roughly the count of commits that touched Payroll, not the full main-repo log.
```

### Step 2 — also include tests under the same path policy

The Payroll tests live at `tests/Feature/Modules/People/Payroll/`. They should ship with the plugin.

Re-run filter-repo with both paths preserved:

```bash
# In a fresh clone (filter-repo refuses to run on a dirty repo)
rm -rf /tmp/blb-payroll-extract
git clone git@github.com:BelimbingApp/belimbing.git /tmp/blb-payroll-extract
cd /tmp/blb-payroll-extract

git filter-repo \
    --path app/Modules/People/Payroll/ \
    --path tests/Feature/Modules/People/Payroll/ \
    --path-rename app/Modules/People/Payroll/:src/ \
    --path-rename tests/Feature/Modules/People/Payroll/:tests/
```

The resulting layout:

```
/tmp/blb-payroll-extract/
├── src/                  # was app/Modules/People/Payroll/
│   ├── Config/
│   ├── Console/
│   ├── ...
│   ├── ServiceProvider.php
│   └── composer.json
└── tests/                # was tests/Feature/Modules/People/Payroll/
    ├── PayrollContributionIntakeTest.php
    └── ...
```

> **Note:** the `src/` and `tests/` convention is a placeholder. If you prefer Plan-12 §7.5's "canonical path" model (the plugin repo's root maps directly to `app/Modules/People/Payroll/` and tests live in a `tests/` subfolder), adjust the `--path-rename` directives accordingly.

### Step 3 — push to the new origin

```bash
cd /tmp/blb-payroll-extract
git remote remove origin
git remote add origin git@github.com:BelimbingApp/blb-payroll-my.git
git branch -M main
git push -u origin main
git push --tags
```

Sanity check: visit the GitHub UI; confirm the file tree and history.

### Step 4 — wire the main repo to ignore the path

In a working copy of the main repo (not the extraction scratch), update `.gitignore`:

```diff
+ # Payroll is now a separate plugin repo (blb-payroll-my); see docs/runbooks/payroll-plugin-extraction.md.
+ app/Modules/People/Payroll/
+ tests/Feature/Modules/People/Payroll/
```

Remove the now-redundant files from the main repo's tracking:

```bash
git rm -r --cached app/Modules/People/Payroll/ tests/Feature/Modules/People/Payroll/
git commit -m "chore: stop tracking app/Modules/People/Payroll (extracted to blb-payroll-my)"
```

> **Important:** `git rm -r --cached` does NOT delete the files on disk. They stay in your working tree but are no longer tracked. Combined with the `.gitignore` entries, future changes to those files will not surface in `git status`.

### Step 5 — clone the new plugin repo into the canonical path

In your main-repo working copy:

```bash
git clone git@github.com:BelimbingApp/blb-payroll-my.git app/Modules/People/Payroll
```

(If the extraction used a `src/` layout, the clone is `git clone <repo> app/Modules/People/Payroll` and Payroll's autoload PSR-4 needs to reference `src/`. If the extraction preserved root mapping, no PSR-4 change needed.)

Verify:

```bash
ls app/Modules/People/Payroll/.git
# .git directory exists — confirms it's a nested git repo
git -C app/Modules/People/Payroll/ remote -v
# Should show github.com/BelimbingApp/blb-payroll-my, not the main repo
```

### Step 6 — run the full People test suite

```bash
php artisan migrate:fresh --env=testing
php artisan test tests/Feature/Modules/People
```

Expected: same number of tests passing as before the extraction. The migrations registered by Payroll's plugin are auto-discovered the same way as any other module migration. The architectural and contract tests do not care whether Payroll's files come from the main repo or a nested clone.

### Step 7 — update onboarding docs

Document the new clone step:

- `README.md` — add a section "Setting up Payroll" that explains the nested clone.
- `CONTRIBUTING.md` — describe the dual-PR workflow when a change spans main + plugin.
- `docs/architecture/pluggable-modules.md` — update §7 "Physical Structure" to show the actual layout now in production.

### Step 8 — update CI

The main-repo CI must clone the plugin too, or it can't run the full Payroll test suite.

GitHub Actions example:

```yaml
- name: Clone Payroll plugin
  run: git clone --depth 1 https://github.com/BelimbingApp/blb-payroll-my.git app/Modules/People/Payroll
```

If using GitLab/Bitbucket, the equivalent runs in the `before_script` or first job step.

## Rollback

If something breaks after extraction and you need to undo:

1. In a fresh clone of the main repo at the commit BEFORE the `git rm --cached` commit, the files are still in history. Use `git checkout <hash> -- app/Modules/People/Payroll/ tests/Feature/Modules/People/Payroll/`.
2. Revert the `.gitignore` change.
3. `git add` the files back and commit.

The new `blb-payroll-my` repo can sit dormant until you're ready to retry; it doesn't have to be deleted.

## What changes for downstream consumers

After extraction, anyone working on Payroll:

1. `cd app/Modules/People/Payroll/` — they're inside the plugin's git context. `git status`, `git commit`, `git push` operate against the plugin's origin.
2. `cd -` — they're back in the main repo's context.

A change that spans both repos requires two commits, two pushes, two PRs. This is the same cost the existing `extensions/{licensee}/` model already incurs.

Cross-repo coordination tips:

- Land the source-module event change (main repo) first.
- Update Payroll's listener (plugin repo) second, referencing the now-merged event class.
- Note required plugin version in the main repo's release notes.

## What does NOT change

- The plugin's code does not move. Same files, same paths, same namespaces.
- The boot mechanism (path-based `ProviderRegistry::resolve`) does not change.
- Test discovery does not change — Payroll's tests still live at `tests/Feature/Modules/People/Payroll/`, just sourced from the plugin clone.
- Migrations still timestamp into the `0320_03_*` band; they auto-discover via the registry the same way.

## Verifying full plug-out after extraction

The plug-out experiment from Plan 15's exit verifies a future scenario: a deployment that does not need payroll simply skips the Payroll clone. From a clean main-repo checkout:

```bash
# Do NOT clone Payroll into app/Modules/People/Payroll/
php artisan migrate:fresh --env=testing
php artisan test tests/Feature/Modules/People/Attendance tests/Feature/Modules/People/Leave tests/Feature/Modules/People/Claim tests/Feature/Modules/People/Settings tests/Feature/Modules/People/Employees
```

Expected: source-module tests that don't depend on Payroll classes pass; the three integration-style tests that assert a `PayrollInput` row was created fail (identical to the current `ServiceProvider.php.disabled` experiment).

If anything else fails, the boundary regressed since Plan 15 — investigate before completing extraction.

## Composer migration (much later — architecture-spec Phase 4)

When `blb-payroll-my` is mature enough to publish to Packagist (or a private Satis), the nested-git step is replaced by `composer require blb/payroll-my`. The plugin's `composer.json` is already in place from Plan 12 Phase 5. Switching from nested-git to composer is a deployment-time change; nothing about the code's structure has to move.

## See also

- `docs/architecture/pluggable-modules.md` — the architecture spec this runbook executes.
- `docs/guides/extensions/private-extension-repositories.md` — the existing nested-git pattern (used by `extensions/{licensee}/`) that Payroll extraction is modelled on.
- `docs/plans/people/12_attendance-event-decoupling.md` through `17_claim-pay-item-mapping.md` — the prerequisite decoupling work.
