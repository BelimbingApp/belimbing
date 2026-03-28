---
name: sonarqube-fix
description: >
  Use this skill when the user asks to fix SonarQube or Sonar issues, clean up
  static analysis findings, or address code quality warnings from SonarCloud.
  This skill handles triaging issues (false positives vs real problems),
  marking false positives on SonarCloud via API, applying code fixes that
  preserve behavior, and creating quality improvement PRs.
  Activate even if they say "clean up quality issues" or "fix linter warnings"
  without explicitly mentioning "Sonar" or "SonarQube".
---

# SonarQube Issue Fixer

Retrieve SonarCloud findings for the BLB project, including both rule-backed
issues and metric-only quality gate failures such as **duplication on new
code**, triage them using BLB-specific rules, **mark false positives on
SonarCloud via API**, apply behavior-preserving fixes, validate, and create a
PR. All in one pass — no user prompts needed.

## Gotchas

- Use BLB-specific triage rules, not generic Sonar advice.
- Mark false positives and safe hotspots before applying code fixes.
- Skip metric-only fixes that worsen the design; note them for human review.
- A red quality gate with **zero open issues** often means a metric failure, not a clean project. Check measures before concluding there is nothing to fix.
- `new_*` metrics are usually stored in SonarCloud's `periods[0].value`, not `value`. Parse both.
- Work in the quality worktree, but create a fresh topic branch from `origin/main` before committing if `sonar-gate` is dirty or diverged.
- Metric-only duplication failures cannot be transitioned like normal issues. If the remaining failure is a project metric, either refactor the code or recommend a Sonar scope / quality-gate change.

## Workflow Checklist

- [ ] Read and validate `SONAR_TOKEN` from `.env`
- [ ] Switch `blb-quality-tree` to `sonar-gate` and reset to the remote default branch
- [ ] Fetch open Sonar issues, hotspots, and quality-gate metrics
- [ ] If the gate is failing with zero issues, fetch per-file metric breakdown for new-code duplication / other metric-only failures
- [ ] Triage into false positives, safe hotspots, fixable issues, skipped issues, and metric-only refactors
- [ ] Mark false positives and safe hotspots via API
- [ ] Apply code fixes
- [ ] Validate with tests, Pint, and build when needed
- [ ] Commit, push, and open the PR
- [ ] Report counts, fixes, skips, and human-review items

## Authentication

Read `SONAR_TOKEN` from the project `.env` file. Never ask the user to paste it
into chat.

```bash
SONAR_TOKEN=$(grep '^SONAR_TOKEN=' /home/kiat/repo/laravel/blb/.env | cut -d= -f2)
```

If it is missing or empty, ask the user to add it to `.env`.

Use HTTP Basic Auth with the token as the username:

```bash
curl -sS -u "$SONAR_TOKEN:" "https://sonarcloud.io/api/..."
```

Validate before proceeding:

```bash
curl -sS -u "$SONAR_TOKEN:" "https://sonarcloud.io/api/authentication/validate"
```

## Step 1: Switch to quality worktree

```bash
cd /home/kiat/repo/laravel/blb-quality-tree
git switch sonar-gate

git fetch --prune origin
if git show-ref --verify --quiet refs/remotes/origin/master; then
  git reset --hard origin/master
else
  git reset --hard origin/main
fi
```

## Step 2: Retrieve issues from SonarCloud

- **Project key:** `BelimbingApp_lara`
- **Branch:** `main`

```bash
# All open issues (paginate with p=1, p=2, ... if total > 100)
curl -sS "https://sonarcloud.io/api/issues/search?componentKeys=BelimbingApp_lara&branch=main&resolved=false&ps=100&p=1"

# Security hotspots
curl -sS "https://sonarcloud.io/api/hotspots/search?projectKey=BelimbingApp_lara&branch=main&status=TO_REVIEW"

# Project-level quality-gate metrics (useful when there are zero open issues)
curl -sS "https://sonarcloud.io/api/measures/component?component=BelimbingApp_lara&branch=main&metricKeys=alert_status,new_duplicated_lines_density,new_duplicated_lines,new_lines,duplicated_lines_density"

# Per-file new-code duplication metrics
curl -sS "https://sonarcloud.io/api/measures/component_tree?component=BelimbingApp_lara&branch=main&metricKeys=new_duplicated_lines_density,new_duplicated_lines,new_lines&qualifiers=FIL&ps=500&p=1"
```

From each issue record, capture:
- `rule` — Sonar rule ID (e.g. `php:S3776`)
- `message` — human-readable description
- `component` — file path (strip the `BelimbingApp_lara:` prefix to get the workspace-relative path)
- `line` — line number
- `severity` — BLOCKER / CRITICAL / MAJOR / MINOR / INFO
- `type` — BUG / VULNERABILITY / CODE_SMELL / SECURITY_HOTSPOT

Sort by severity descending (BLOCKER first) before proceeding to triage.

### When the failing gate is "duplication on new code"

Do **not** stop after `issues/search` returns zero issues. SonarCloud often
reports duplication only through measures and quality-gate status.

Use this workflow:
1. Read project-level measures from `/api/measures/component`.
2. If `new_duplicated_lines` or `new_duplicated_lines_density` is non-zero, fetch `/api/measures/component_tree` for files.
3. For each file, read both `measure.value` and `measure.periods[0].value` because new-code metrics are often stored only in the period value.
4. Filter to files with `new_duplicated_lines > 0` or `new_duplicated_lines_density > 0`.
5. Correlate those files with the current branch diff so you focus on code introduced since the new-code baseline.

Capture for each flagged file:
- file path
- `new_duplicated_lines`
- `new_duplicated_lines_density`
- the duplicated block theme (e.g. repeated relation methods, repeated prompt file loader, repeated workflow seeder persistence)
- the smallest safe refactor that removes duplication without changing behavior

## Step 3: Triage issues (fix vs false positive)

Triage every issue before touching code.

### Always false positive — mark on SonarCloud

- **`php:S4144` on Livewire `updated{Property}()` hooks** — Different event handlers, identical bodies are intentional
- **`php:S1192` on i18n keys** (`__('some.key')`) — Translation keys are not duplicate string literals
- **`php:S1192` in Laravel config files** (`config/*.php`) — Each config block (database connection, logging channel) is independent and self-contained. Extracting literals to constants breaks Laravel conventions.
- **`php:S107` on Laravel constructor DI** (≥8 injected services) — Container-resolved DI is not the same problem as arbitrary parameters
- **`php:S1142` ("too many returns") on straightforward guard-clause flow** — Early returns are intentional; leave them alone
- **Complexity flags on `render()`, Eloquent model definitions, or migration `up()`** — Framework-driven, structurally unavoidable
- **Any issue where the only fix is to add logic, change a message, or alter control flow** — That is a behavior change; handle separately

**BLB-specific gotchas:**
- `md5()` is often used as a **non-cryptographic shortener** (cache keys / stable identifiers). This is safe when not used for passwords, signatures, auth tokens, integrity checks, or any security boundary.

**BLB-specific hotspot safe patterns:**
- **`php:S4790` (`sha1`/`md5`) in test files** — Often required to match Laravel's own framework conventions (e.g. email verification URLs use `sha1($user->email)`). Safe when mirroring framework behavior, not implementing security.
- **`Web:S5725` (missing SRI) on CDN font stylesheets** — SRI is impractical for CDN-served CSS that may update content hashes. Font stylesheets carry no executable code risk.
- **`php:S5042` (archive expansion) on trusted data sources** — Safe when extracting known data files from official sources (e.g. GeoNames). No user-supplied archives.
- **`githubactions:S7637` (version tags instead of SHA)** — Safe for well-known, high-profile GitHub Actions (e.g. `actions/checkout`, `shivammathur/setup-php`). Version tags are the standard practice recommended by GitHub.

### Fix with confidence

- **`php:S3776` — High cognitive complexity** — Extract cohesive private methods; each must have a single nameable responsibility
- **`php:S107` — Too many parameters (non-DI contexts)** — Introduce or reuse an existing DTO (check for existing DTOs first)
- **`php:S1448` — Too many methods** — Extract to Livewire concerns or service classes; group by *what* the methods are about
- **`php:S4144` — Identical method bodies (non-lifecycle)** — Extract a private helper
- **`php:S3358` — Nested ternaries** — Unpack to `if` / `return` chain
- **`Web:S5256` — Missing table headers** — Add `<thead>` with `<th scope="col">`; use `class="sr-only"` if visually unnecessary
- **`Web:S7927` — aria-label mismatch** — Align `aria-label` with visible text, or remove it if visible text is sufficient
- **`php:S1192` — Duplicate literals in production code** — Extract to a `private const`

### Metric-only duplication fixes

When Sonar fails on `new_duplicated_lines_density` or similar metric-only
signals, there may be no individual issue keys to transition. Treat the work as
structural cleanup instead of false-positive management.

Preferred fixes by duplication shape:
- **Repeated Livewire setup / glue across sibling components** — extract a shared concern when three or more methods move together and the concern exposes a simple interface.
- **Repeated service file-loading / exception boundaries** — extract a dedicated helper/service rather than repeating `is_file()` / `file_get_contents()` / exception handling blocks.
- **Repeated model relations across sibling models** — extract a trait only when the relation semantics are truly identical; do not abstract unrelated fillable/cast definitions just to satisfy Sonar.
- **Repeated workflow seeder persistence shape** — extract a trait or base seeder for the common persistence loop, while keeping workflow definitions inline per module.
- **Repeated development seeder workflow boilerplate** — extract shared open/step/payload builders, but keep each seeded scenario readable as a business story.
- **Repeated test scaffolding** — prefer helper builders, datasets, and file-level constants over abstract test classes.
- **Repeated migration column/index bundles** — extract only if the helper makes the migration easier to read; do not hide important schema shape behind vague helpers.

BLB guidance for dev seeders:
- **Optimize for readable scenarios, not maximum DRY** — duplication in `Database/Seeders/Dev/` is lower-risk than duplication in runtime code because these files are fixture stories, not production behavior.
- **Refactor repeated scaffolding first** — shared `open()` payload defaults, repeated workflow step arrays, and repeated existence guards are good extraction targets.
- **Leave scenario data inline when it tells the story** — titles, summaries, departments, corrective actions, and business-specific values should usually remain visible in the seeder.
- **Stop when abstraction hides the fixtures** — if the next helper makes it harder to understand what lifecycle state is being seeded, prefer readability and consider a Sonar exclusion instead.
- **When Sonar still fails on dev-seeder duplication after reasonable cleanup, recommend changing Sonar scope** rather than forcing deeper abstractions that make the fixtures worse.

Avoid these anti-patterns:
- extracting a trait/helper used only twice when the resulting API is less obvious
- creating abstractions that mix unrelated responsibilities just to reduce line similarity
- moving code out of place when the duplication is better handled with a local private method or constant
- forcing DRY across migrations or tests when the shared helper obscures the domain intent
- forcing dev seeders into opaque builders or config blobs that hide the seeded lifecycle narrative just to satisfy a duplication percentage

### Apply carefully (requires judgement)

- **`php:S108` — Empty code block** — If intentional: add `// No action needed — <reason>`. Never add logic silently inside a quality fix.
- **`php:S1142` — Too many returns (complex flows)** — Usually skip when control flow is already straightforward. Only refactor if it clearly improves readability, and prefer extracting a well-named private method over re-shaping the logic.
- **`php:S1192` — Duplicate literals in test files** — Extract only if the string is test data that would be painful to update; keep expectations inline
- **`sh:S7682` — Shell function without explicit return** — Add `return 0` only if the caller checks `$?`

### Security hotspots

For each `SECURITY_HOTSPOT`:
1. Read the flagged code.
2. Decide whether the risk is real in BLB's context.
3. If real, fix it and document why.
4. If not real, mark it safe via API.

## Step 4: Mark false positives on SonarCloud

Do this immediately after triage and before code fixes.

### Marking issues as false positive

```bash
# 1. Add a comment explaining why it's a false positive
curl -sS -u "$SONAR_TOKEN:" -X POST "https://sonarcloud.io/api/issues/add_comment" \
  --data-urlencode "issue=$ISSUE_KEY" \
  --data-urlencode "text=$REASON"

# 2. Transition to false positive
curl -sS -u "$SONAR_TOKEN:" -X POST "https://sonarcloud.io/api/issues/do_transition" \
  --data-urlencode "issue=$ISSUE_KEY" \
  --data-urlencode "transition=falsepositive"
```

### Marking hotspots as safe

```bash
curl -sS -u "$SONAR_TOKEN:" -X POST "https://sonarcloud.io/api/hotspots/change_status" \
  --data-urlencode "hotspot=$HOTSPOT_KEY" \
  --data-urlencode "status=REVIEWED" \
  --data-urlencode "resolution=SAFE" \
  --data-urlencode "comment=$REASON"
```

### Comment guidelines

Use one clear reason per batch. Examples:

| Rule | Comment template |
|------|-----------------|
| `php:S1142` | "False positive: This method uses straightforward guard-clause flow with early returns. Each return handles a distinct precondition check. This is an intentional, readable pattern — not excessive complexity." |
| `php:S1192` (config) | "False positive: This is a standard Laravel config file. Each block is independent and self-contained — extracting the literal to a constant would break Laravel conventions." |
| `php:S4790` (test sha1) | "Safe: sha1() is used here to construct Laravel's email verification URL, matching the framework's own implementation. Not a security context." |
| `Web:S5725` (SRI) | "Safe: Font stylesheet from external CDN. SRI is impractical for CDN-served CSS. No executable code risk." |
| `php:S5042` (zip) | "Safe: Extracts trusted archive from official data source. No user-supplied archives are processed." |
| `githubactions:S7637` | "Safe: Well-known GitHub Action referenced by major version tag, which is standard practice." |

### Batch processing

Process by rule:
1. Verify the first issue's code.
2. Apply the same reason and transition to matching issues.
3. Check each API response.

## Step 5: Apply code fixes

Apply fixes only after marking false positives and safe hotspots.

For metric-only duplication failures, there may be nothing to mark in SonarCloud;
go straight from triage to refactor.

### Boy-Scout Rule

While fixing the flagged issue, also clean up *immediately surrounding code*:
- Remove unused `use` imports
- Remove stale comments or dead branches introduced by the fix
- Fix obvious naming issues in methods you touch
- Do not widen the scope beyond the current method/class

### BLB Design Principles (do not violate)

- **Deep modules:** extracted methods must hide complexity, not just redistribute lines
- **No magic methods:** use `Model::query()->method()` not `Model::method()`
- **Explicit return types** on all methods you touch
- **Single quotes** for string literals (double quotes only for interpolation/escapes)
- **Double-space alignment** in PHPDoc `@param` blocks
- Do not add error handling, fallbacks, or validation for scenarios that cannot happen

### Quality bar

A fix is acceptable only if it:
1. does not change observable behavior
2. improves clarity, testability, or structure
3. leaves the surrounding code at least as clean as it was found
4. passes validation

If a Sonar-only fix would harm the design, skip it and note it for human review.

### Duplication-specific heuristics

When choosing between possible refactors, prefer this order:
1. extract a private method inside the same class
2. extract a focused concern/trait shared by sibling classes in the same module
3. extract a small service/helper with a domain name
4. introduce a broader base abstraction only when the duplication spans multiple callers and the resulting contract is obvious

For BLB specifically:
- Shared Livewire concerns are often the right answer for repeated admin setup logic.
- Shared model concerns are appropriate for repeated evidence/event relations across quality records.
- Seeder traits are appropriate when multiple workflow seeders repeat the same `updateOrCreate` orchestration.
- Do not abstract whole models or whole components merely because Sonar found a duplicated block.

## Step 6: Validate

Use this validation loop:

```bash
php artisan test --stop-on-failure
vendor/bin/pint --dirty
npm run build
```

If a step fails, fix or revert the offending change before proceeding.

For metric-only duplication refactors, start with the narrowest useful checks for
the touched area, then widen only as needed. Example:

```bash
# Targeted tests for touched domain first
php artisan test tests/Unit/Modules/Core/AI/Services/LaraPromptFactoryExceptionTest.php

# Then formatting
vendor/bin/pint --dirty

# Then broader/regression checks if the change touched shared primitives
php artisan test --stop-on-failure

# Build only if frontend assets or browser-facing resources changed
npm run build
```

## Step 7: Commit and create PR

```bash
git switch sonar-gate
git fetch --prune origin

# If sonar-gate has existing work, create a fresh topic branch from origin/main
git switch -c quality/sonar-fix origin/main

git add .
git commit -m "quality: fix Sonar issues"

git push -u origin quality/sonar-fix

gh pr create --base main --head quality/sonar-fix --title "quality: fix Sonar issues" --body "$(cat <<'EOF'
## Summary
- Fix Sonar findings (no behavior change)

## Test plan
- [ ] php artisan test --stop-on-failure
- [ ] vendor/bin/pint --dirty
- [ ] npm run build (if frontend files changed)
EOF
)"
```

## Step 8: Report results

Report:
- **Marked as false positive:** count by rule, with comment reason used
- **Marked as safe (hotspots):** count by rule, with comment reason used
- **Issues fixed:** rule, file, brief description of fix
- **Metric-only fixes:** metric, file, duplication theme, refactor used
- **Issues skipped:** rule, file, reason skipped
- **Issues requiring human review:** rule, file, why you couldn't fix it (behavior change needed, architectural question, etc.)

When the original failure was `new_duplicated_lines_density`, explicitly report:
- project-level metric before the fix if you captured it
- files that contributed to the new duplication budget
- which duplicated structures were extracted
- which known duplication hotspots still remain after this pass
