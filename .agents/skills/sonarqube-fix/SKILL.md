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

Retrieve open SonarCloud issues for the BLB project, triage them using
BLB-specific rules, **mark false positives on SonarCloud via API**, apply code
fixes to real issues, validate, and create a PR. All in one pass — no user
prompts needed.

## Gotchas

- Use BLB-specific triage rules, not generic Sonar advice.
- Mark false positives and safe hotspots before applying code fixes.
- Skip metric-only fixes that worsen the design; note them for human review.
- Work in the quality worktree on `sonar-gate`, never directly on `main`.

## Workflow Checklist

- [ ] Read and validate `SONAR_TOKEN` from `.env`
- [ ] Switch `blb-quality-tree` to `sonar-gate` and reset to the remote default branch
- [ ] Fetch open Sonar issues and hotspots
- [ ] Triage into false positives, safe hotspots, fixable issues, and skipped issues
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
```

From each issue record, capture:
- `rule` — Sonar rule ID (e.g. `php:S3776`)
- `message` — human-readable description
- `component` — file path (strip the `BelimbingApp_lara:` prefix to get the workspace-relative path)
- `line` — line number
- `severity` — BLOCKER / CRITICAL / MAJOR / MINOR / INFO
- `type` — BUG / VULNERABILITY / CODE_SMELL / SECURITY_HOTSPOT

Sort by severity descending (BLOCKER first) before proceeding to triage.

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

## Step 6: Validate

Use this validation loop:

```bash
php artisan test --stop-on-failure
vendor/bin/pint --dirty
npm run build
```

If a step fails, fix or revert the offending change before proceeding.

## Step 7: Commit and create PR

```bash
git switch sonar-gate

git add .
git commit -m "quality: fix Sonar issues"

git push -u origin sonar-gate

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

## Step 8: Report results

Report:
- **Marked as false positive:** count by rule, with comment reason used
- **Marked as safe (hotspots):** count by rule, with comment reason used
- **Issues fixed:** rule, file, brief description of fix
- **Issues skipped:** rule, file, reason skipped
- **Issues requiring human review:** rule, file, why you couldn't fix it (behavior change needed, architectural question, etc.)
