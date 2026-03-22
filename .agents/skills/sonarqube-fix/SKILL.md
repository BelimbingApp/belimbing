---
name: sonarqube-fix
description: >
  Use this skill when the user asks to fix SonarQube or Sonar issues, clean up
  static analysis findings, or address code quality warnings from SonarCloud.
  This skill handles triaging issues (false positives vs real problems),
  applying fixes while preserving behavior, and creating quality improvement PRs.
  Activate even if they say "clean up quality issues" or "fix linter warnings"
  without explicitly mentioning "Sonar" or "SonarQube".
---

# SonarQube Issue Fixer

You will retrieve open SonarCloud issues for the BLB project, triage them using
BLB-specific rules, apply fixes that improve code quality without changing behavior,
validate the changes, and create a PR on the `sonar-gate` branch.

## Step 1: Switch to quality worktree

```bash
# Work in the quality worktree
cd /home/kiat/repo/laravel/blb-quality-tree
git switch sonar-gate

# Reset the branch to the remote default branch.
# Some repos use origin/master; BLB uses origin/main.
git fetch --prune origin
if git show-ref --verify --quiet refs/remotes/origin/master; then
  git reset --hard origin/master
else
  git reset --hard origin/main
fi
```

## Step 2: Retrieve issues from SonarCloud

The BLB project is hosted at:
- **Project key:** `BelimbingApp_lara`
- **Branch:** `main`

Fetch open issues and hotspots using the SonarCloud public API:

```bash
# All open issues (paginate with p=1, p=2, ... if total > 100)
curl -sS "https://sonarcloud.io/api/issues/search?componentKeys=BelimbingApp_lara&branch=main&resolved=false&ps=100&p=1"

# Security hotspots
curl -sS "https://sonarcloud.io/api/hotspots/search?projectKey=BelimbingApp_lara&branch=main&status=TO_REVIEW"
```

From each issue record, capture:
- `rule` — Sonar rule ID (e.g. `php:S3776`)
- `message` — human-readable description
- `component` — file path (strip the `BelimbingApp_lara:` prefix to get the workspace-relative path)
- `line` — line number
- `severity` — BLOCKER / CRITICAL / MAJOR / MINOR / INFO
- `type` — BUG / VULNERABILITY / CODE_SMELL / SECURITY_HOTSPOT

Sort by severity descending (BLOCKER first) before proceeding to triage.

## Step 3: Triage issues (fix vs skip)

Apply this decision framework to every issue **before touching any code**.

### Always skip (false positives)

- **`php:S4144` on Livewire `updated{Property}()` hooks** — Different event handlers, identical bodies are intentional
- **`php:S1192` on i18n keys** (`__('some.key')`) — Translation keys are not duplicate string literals
- **`php:S107` on Laravel constructor DI** (≥8 injected services) — Container-resolved DI is not the same problem as arbitrary parameters
- **`php:S1142` ("too many returns") on straightforward guard-clause flow** — Early returns are intentional; leave them alone
- **Complexity flags on `render()`, Eloquent model definitions, or migration `up()`** — Framework-driven, structurally unavoidable
- **Any issue where the only fix is to add logic, change a message, or alter control flow** — That is a behavior change; handle separately

**BLB-specific gotchas:**
- `md5()` is often used as a **non-cryptographic shortener** (cache keys / stable identifiers). This is safe when not used for passwords, signatures, auth tokens, integrity checks, or any security boundary.

### Fix with confidence

- **`php:S3776` — High cognitive complexity** — Extract cohesive private methods; each must have a single nameable responsibility
- **`php:S107` — Too many parameters (non-DI contexts)** — Introduce or reuse an existing DTO (check for existing DTOs first)
- **`php:S1448` — Too many methods** — Extract to Livewire concerns or service classes; group by *what* the methods are about
- **`php:S4144` — Identical method bodies (non-lifecycle)** — Extract a private helper
- **`php:S3358` — Nested ternaries** — Unpack to `if` / `return` chain
- **`Web:S5256` — Missing table headers** — Add `<thead>` with `<th scope="col">`; use `class="sr-only"` if visually unnecessary
- **`Web:S7927` — aria-label mismatch** — Align `aria-label` with visible text, or remove it if visible text is sufficient
- **`php:S1192` — Duplicate literals in production code** — Extract to a `private const`

### Apply carefully (requires judgement)

- **`php:S108` — Empty code block** — If intentional: add `// No action needed — <reason>`. Never add logic silently inside a quality fix.
- **`php:S1142` — Too many returns (complex flows)** — Usually skip when control flow is already straightforward. Only refactor if it clearly improves readability, and prefer extracting a well-named private method over re-shaping the logic.
- **`php:S1192` — Duplicate literals in test files** — Extract only if the string is test data that would be painful to update; keep expectations inline
- **`sh:S7682` — Shell function without explicit return** — Add `return 0` only if the caller checks `$?`

### Security hotspots

For each `SECURITY_HOTSPOT`:
1. Read the flagged code carefully.
2. Assess whether the risk is real in BLB's context (self-hosted, no untrusted user-supplied commands, internal API, etc.).
3. If real: fix it and document *why* in the commit message.
4. If not real: add a `// NOSONAR — <reason>` comment and mark as "Acknowledged" in SonarCloud.
5. Never mark a hotspot as safe without reading the code.

## Step 4: Apply fixes

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

A fix is only acceptable if it:
1. Does **not** change observable behavior
2. Improves clarity, testability, or structure
3. Leaves the surrounding code at least as clean as it was found
4. Passes all existing tests

Skip metric-only fixes that make the code worse. If satisfying a Sonar rule would
reduce clarity, introduce shallow extractions, or otherwise harm the design,
skip the issue and note it for human review.

If you cannot satisfy all four, **skip the issue** and note it for a human review.

## Step 5: Validate

After applying fixes, run validation in this exact order:

```bash
# Tests must pass
php artisan test --stop-on-failure

# Linter must be clean
vendor/bin/pint --dirty

# Frontend must build (if Blade/Livewire files were touched)
npm run build
```

If any step fails, **revert the specific fix that caused the failure** and note the issue.

Do **not** proceed to Step 6 until all validation passes.

## Step 6: Commit and create PR

```bash
# Ensure you are on the quality branch
git switch sonar-gate

# Commit on sonar-gate (one concern per commit is still the rule)
git add .
git commit -m "quality: fix Sonar issues"

# Push the branch (never push directly to main)
git push -u origin sonar-gate

# Open a PR to main
gh pr create --base main --head sonar-gate --title "quality: fix Sonar issues" --body "$(cat <<'EOF'
## Summary
- Fix Sonar findings (no behavior change)

## Test plan
- [ ] php artisan test --stop-on-failure
- [ ] vendor/bin/pint --dirty
- [ ] npm run build (if frontend files changed)
EOF
)"
```

## Step 7: Report results

After completing all steps, provide a summary with:
- **Issues fixed:** rule, file, brief description of fix
- **Issues skipped:** rule, file, reason skipped
- **Issues requiring human review:** rule, file, why you couldn't fix it (behavior change needed, architectural question, etc.)
