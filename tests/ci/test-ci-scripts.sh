#!/usr/bin/env bash
set -euo pipefail

root=$(git rev-parse --show-toplevel)
cd "$root"
bash -n scripts/ci/changed-authorable-php.sh scripts/ci/extension-conformance.sh scripts/ci/mount-guard.sh scripts/ci/phpstan-baseline-gate.sh scripts/ci/record-pest-timing.sh
python3 -m py_compile scripts/ci/aggregate-pest-timing.py

# Timing aggregator (#614): one table from per-suite JSON, fail-closed on empty.
timing_fixture=$(mktemp -d)
trap 'rm -rf "$timing_fixture"' EXIT
mkdir -p "$timing_fixture"
printf '%s\n' '{"job":"Feature","suite":"Feature","wall_seconds":12.5,"tests":3,"assertions":9}' > "$timing_fixture/b.json"
printf '%s\n' '{"job":"Unit","suite":"Unit","wall_seconds":1.25,"tests":2,"assertions":4}' > "$timing_fixture/a.json"
aggregate_out=$(python3 scripts/ci/aggregate-pest-timing.py "$timing_fixture")
grep -q '| Unit | Unit | 1.250 | 2 | 4 |' <<< "$aggregate_out"
grep -q '| Feature | Feature | 12.500 | 3 | 9 |' <<< "$aggregate_out"
grep -q '| \*\*Σ\*\* |  | \*\*13.750\*\* | \*\*5\*\* | \*\*13\*\* |' <<< "$aggregate_out"
if python3 scripts/ci/aggregate-pest-timing.py "$timing_fixture/empty" >/dev/null 2>&1; then
    echo 'aggregate-pest-timing accepted a missing directory' >&2
    exit 1
fi
mkdir -p "$timing_fixture/empty"
if python3 scripts/ci/aggregate-pest-timing.py "$timing_fixture/empty" >/dev/null 2>&1; then
    echo 'aggregate-pest-timing accepted an empty timing directory' >&2
    exit 1
fi
rm -rf "$timing_fixture"
trap - EXIT

# record-pest-timing.sh must parse colourised Pest footers (#614).
ansi_fixture=$(mktemp -d)
trap 'rm -rf "$ansi_fixture"' EXIT
mkdir -p "$ansi_fixture/vendor/bin" "$ansi_fixture/scripts/ci"
cp scripts/ci/record-pest-timing.sh "$ansi_fixture/scripts/ci/"
cat > "$ansi_fixture/vendor/bin/pest" <<'PEST'
#!/usr/bin/env bash
printf '  \033[90mTests:\033[39m    \033[32;1m1525 passed\033[39;22m\033[90m (10675 assertions)\033[39m\n'
printf '  \033[90mDuration:\033[39m \033[39m147.86s\033[39m\n'
exit 0
PEST
chmod +x "$ansi_fixture/vendor/bin/pest"
(
  cd "$ansi_fixture"
  MATRIX_SUITE=Unit TIMING_DIR=timing bash scripts/ci/record-pest-timing.sh Unit -- --testsuite=Unit >/dev/null
  python3 - <<'CHECK'
import json
from pathlib import Path
payload = json.loads(Path("timing/Unit__Unit.json").read_text(encoding="utf-8"))
assert payload["tests"] == 1525, payload
assert payload["assertions"] == 10675, payload
assert abs(float(payload["pest_duration_seconds"]) - 147.86) < 0.001, payload
CHECK
)
rm -rf "$ansi_fixture"
trap - EXIT

python3 -m py_compile scripts/ci/dependency-audit.py
python3 -m json.tool docs/ci/dependency-audit-policy.json >/dev/null
python3 -m json.tool scripts/ci/domain-repos.json >/dev/null

# Feature shards must stay disjoint and cover every first-level Feature
# directory so CI cannot silently drop a folder when a new one lands (#576).
python3 -m json.tool scripts/ci/platform-feature-shards.json >/dev/null
python3 scripts/ci/platform-feature-shards.py --validate-only >/dev/null
grep -q 'platform-feature-shards.py' .github/workflows/tests.yml

# Feature shard membership must fail closed, not merely parse (#576).
shard_root="$(mktemp -d)"
mkdir -p "$shard_root/tests/Feature/Alpha" "$shard_root/tests/Feature/Beta" "$shard_root/tests/Feature/Gamma"
touch "$shard_root/tests/Feature/Alpha/AlphaTest.php" "$shard_root/tests/Feature/Beta/BetaTest.php" "$shard_root/tests/Feature/Gamma/GammaTest.php"
shard_file="$shard_root/shards.json"

printf '{"shards":{"a":["Alpha","Beta"],"b":["Gamma"]}}' > "$shard_file"
if ! python3 scripts/ci/platform-feature-shards.py --root "$shard_root" --shards-file "$shard_file" --validate-only >/dev/null; then
    echo 'Feature shard validator rejected a complete, disjoint layout' >&2; exit 1
fi

printf '{"shards":{"a":["Alpha"],"b":["Beta"]}}' > "$shard_file"
if python3 scripts/ci/platform-feature-shards.py --root "$shard_root" --shards-file "$shard_file" --validate-only >/dev/null 2>&1; then
    echo 'Feature shard validator accepted an unsharded directory' >&2; exit 1
fi

printf '{"shards":{"a":["Alpha","Beta"],"b":["Beta","Gamma"]}}' > "$shard_file"
if python3 scripts/ci/platform-feature-shards.py --root "$shard_root" --shards-file "$shard_file" --validate-only >/dev/null 2>&1; then
    echo 'Feature shard validator accepted overlapping shards' >&2; exit 1
fi

printf '{"shards":{"a":["Alpha","Beta"],"b":["Gamma"]}}' > "$shard_file"
touch "$shard_root/tests/Feature/LooseTest.php"
if python3 scripts/ci/platform-feature-shards.py --root "$shard_root" --shards-file "$shard_file" --validate-only >/dev/null 2>&1; then
    echo 'Feature shard validator accepted a loose Feature test file' >&2; exit 1
fi
rm -rf "$shard_root"

# Dependency audit policy (#617): expired allowlist entries fail closed; a
# non-expired policy with empty audit reports passes. Live composer/bun are
# skipped — fixtures only.
dependency_audit=scripts/ci/dependency-audit.py
empty_composer=tests/ci/fixtures/dependency-audit/empty-composer.json
empty_bun=tests/ci/fixtures/dependency-audit/empty-bun.json
if python3 "$dependency_audit" \
    --policy tests/ci/fixtures/dependency-audit/policy-expired.json \
    --composer-report "$empty_composer" \
    --bun-report "$empty_bun" \
    --today 2026-09-06 \
    --skip-live >/dev/null; then
    echo 'dependency-audit accepted an expired allowlist entry' >&2
    exit 1
fi
python3 "$dependency_audit" \
    --policy tests/ci/fixtures/dependency-audit/policy-ok.json \
    --composer-report "$empty_composer" \
    --bun-report "$empty_bun" \
    --today 2026-09-06 \
    --skip-live >/dev/null
# A reported advisory at or above min_severity fails; an unexpired allowlist
# entry for its CVE clears it; a finding below the threshold is ignored.
if python3 "$dependency_audit" \
    --policy tests/ci/fixtures/dependency-audit/policy-ok.json \
    --composer-report tests/ci/fixtures/dependency-audit/composer-high.json \
    --bun-report "$empty_bun" \
    --today 2026-09-06 \
    --skip-live >/dev/null 2>&1; then
    echo 'dependency-audit passed with a high advisory and no allowlist' >&2
    exit 1
fi
python3 "$dependency_audit" \
    --policy tests/ci/fixtures/dependency-audit/policy-allow-high.json \
    --composer-report tests/ci/fixtures/dependency-audit/composer-high.json \
    --bun-report "$empty_bun" \
    --today 2026-09-06 \
    --skip-live >/dev/null
python3 "$dependency_audit" \
    --policy tests/ci/fixtures/dependency-audit/policy-high-threshold.json \
    --composer-report tests/ci/fixtures/dependency-audit/composer-low.json \
    --bun-report "$empty_bun" \
    --today 2026-09-06 \
    --skip-live >/dev/null
grep -q 'dependency-audit.py' .github/workflows/security.yml
grep -q 'docs/ci/dependency-audit-policy.json' docs/security-advisories.md

# Database feature tests prove the behaviour most exposed to dialect, schema,
# and constraint differences. Their PostgreSQL coverage is discovered, not
# copied into the workflow, so adding a file cannot silently leave it SQLite
# only (#536).
postgres_tests=$(python3 scripts/ci/postgres-mirror-feature-tests.py)
test -n "$postgres_tests"
grep -qx 'tests/Feature/Database/QueryTest.php' <<< "$postgres_tests"
grep -qx 'tests/Feature/Database/DataShareMirrorUiTest.php' <<< "$postgres_tests"
grep -q 'postgres-mirror-feature-tests.py' .github/workflows/tests.yml
postgres_surface_fixture=$(mktemp -d)
trap 'rm -rf "$postgres_surface_fixture"' EXIT
mkdir -p "$postgres_surface_fixture/tests/Feature/Database/Nested"
touch "$postgres_surface_fixture/tests/Feature/Database/Nested/ExampleTest.php"
grep -qx 'tests/Feature/Database/Nested/ExampleTest.php' \
    < <(python3 scripts/ci/postgres-mirror-feature-tests.py --root "$postgres_surface_fixture")
if python3 scripts/ci/postgres-mirror-feature-tests.py --root "$postgres_surface_fixture/empty" >/dev/null 2>&1; then
    echo 'postgres-mirror feature discovery accepted an empty test surface' >&2
    exit 1
fi
rm -rf "$postgres_surface_fixture"
trap - EXIT

# The connector's receiver independently validates the payload before using
# platform_sha as its composed-CI ref. Keep the sender half pinned here so a
# workflow cleanup cannot silently drop the success dependency, narrow secret,
# or one of the cross-repository contract fields (#551).
python3 - <<'PY'
from pathlib import Path
import re


def job_block(source: str, job: str) -> str:
    pattern = rf'(?ms)^  {re.escape(job)}:\n.*?(?=^  [a-zA-Z0-9_-]+:\n|\Z)'
    matches = re.findall(pattern, source)
    assert len(matches) == 1, f'tests.yml must define exactly one {job} job'

    return matches[0]


workflow = Path('.github/workflows/tests.yml').read_text(encoding='utf-8')
dispatch = job_block(workflow, 'notify-people-connector')

required = (
    "if: github.event_name == 'push' && github.ref == 'refs/heads/main'",
    'needs:\n      - ci\n      - postgres-mirror',
    'permissions:\n      contents: read',
    'PEOPLE_CONNECTOR_DISPATCH_TOKEN: ${{ secrets.PEOPLE_CONNECTOR_DISPATCH_TOKEN }}',
    "--arg event_type 'belimbing-platform-main-ci-succeeded'",
    '--arg platform_repository "$PLATFORM_REPOSITORY"',
    '--arg platform_ref "$PLATFORM_REF"',
    '--arg platform_sha "$PLATFORM_SHA"',
    '--arg platform_run_url "$PLATFORM_RUN_URL"',
    "'{event_type: $event_type, client_payload: {platform_repository: $platform_repository, platform_ref: $platform_ref, platform_sha: $platform_sha, platform_run_url: $platform_run_url}}'",
    'GH_TOKEN="$PEOPLE_CONNECTOR_DISPATCH_TOKEN" gh api',
    'repos/BelimbingApp/blb-people-connector/dispatches',
)
for contract in required:
    assert contract in dispatch, f'missing People Connector dispatch contract: {contract}'

assert 'if [[ -z "$PEOPLE_CONNECTOR_DISPATCH_TOKEN" ]]' in dispatch, 'missing explicit-secret failure'
assert 'continue-on-error:' not in dispatch, 'dispatch failure must fail the platform workflow'

future_job = workflow + '\n  unrelated-future-job:\n    continue-on-error: true\n'
assert 'continue-on-error:' not in job_block(future_job, 'notify-people-connector'), (
    'an unrelated later job must not contaminate the dispatch contract'
)
PY

# Blocked-By sweep must use a fine-grained cross-repo token so qualified
# BelimbingApp/blb-people and blb-people-connector blockers can resolve (#606).
# Default github.token cannot read other repositories; keep the job's
# permissions block for checkout and document intent, but wire GITHUB_TOKEN
# for the sweep step to AI_TEAM_BLOCKED_BY_SWEEP_TOKEN.
python3 - <<'PY'
from pathlib import Path
import re


def job_block(source: str, job: str) -> str:
    pattern = rf'(?ms)^  {re.escape(job)}:\n.*?(?=^  [a-zA-Z0-9_-]+:\n|\Z)'
    matches = re.findall(pattern, source)
    assert len(matches) == 1, f'ai-team-blocked-by-sweep.yml must define exactly one {job} job'

    return matches[0]


workflow = Path('.github/workflows/ai-team-blocked-by-sweep.yml').read_text(encoding='utf-8')
sweep = job_block(workflow, 'sweep')

required = (
    'permissions:\n  contents: read',
    'permissions:\n      contents: read\n      issues: write',
    'AI_TEAM_BLOCKED_BY_SWEEP_TOKEN: ${{ secrets.AI_TEAM_BLOCKED_BY_SWEEP_TOKEN }}',
    'GITHUB_TOKEN: ${{ secrets.AI_TEAM_BLOCKED_BY_SWEEP_TOKEN }}',
    'GITHUB_REPOSITORY: ${{ github.repository }}',
    'run: python3 docs/ai-team/scripts/blocked_by_sweep.py',
    'if [[ -z "$AI_TEAM_BLOCKED_BY_SWEEP_TOKEN" ]]; then',
)
for contract in required:
    assert contract in workflow, f'missing blocked-by sweep contract: {contract!r}'

assert 'GITHUB_TOKEN: ${{ github.token }}' not in sweep, (
    'sweep step must not use default github.token; cross-repo blockers need AI_TEAM_BLOCKED_BY_SWEEP_TOKEN'
)
assert 'pull_request:' not in workflow, 'blocked-by sweep must stay schedule/dispatch only'
PY

# mount-guard.sh: a direct edit under docs/ai-team/ must be refused; the
# subtree-pull commit shape (and its merge) must pass; unrelated changes must
# never even inspect the mount. Built in an isolated fixture repo so this
# never touches the real docs/ai-team/ mount or its history (#475).
mount_guard_fixture=$(mktemp -d)
trap 'rm -rf "$mount_guard_fixture"' EXIT
(
    cd "$mount_guard_fixture"
    git init -q
    git config user.name test
    git config user.email test@example.invalid
    mkdir -p docs/ai-team
    echo 'root file' > README.md
    git add -A && git commit -qm 'initial'
    base=$(git rev-parse HEAD)

    git checkout -q -b pull-branch
    echo 'mounted content' > docs/ai-team/README.md
    git add -A
    git commit -qm "Squashed 'docs/ai-team/' changes from abc123..def456"
    git checkout -q -
    git merge --no-ff -q pull-branch -m 'chore: pull ai-team package-mount into docs/ai-team'
    pull_head=$(git rev-parse HEAD)

    if ! bash "$root/scripts/ci/mount-guard.sh" "$base" "$pull_head" >/dev/null; then
        echo 'mount-guard.sh refused a legitimate subtree pull' >&2; exit 1
    fi

    git checkout -q -b direct-edit-branch "$base"
    mkdir -p docs/ai-team
    echo 'hand-edited by an agent' >> docs/ai-team/README.md
    git add -A
    git commit -qm 'feat: implement something directly in the mount'
    edit_head=$(git rev-parse HEAD)

    if bash "$root/scripts/ci/mount-guard.sh" "$base" "$edit_head" >/dev/null 2>&1; then
        echo 'mount-guard.sh accepted a direct edit under docs/ai-team/' >&2; exit 1
    fi

    # A topic branch refreshed by merging main (which carries a legitimate
    # pull) must pass: the refresh merge's first-parent diff shows the mount,
    # but the merge introduces nothing — it matches its main parent exactly.
    # First contact (#462, a refreshed Dependabot PR) hit exactly this.
    git checkout -q -b refreshed-topic "$base"
    echo 'lockfile change' > composer.fake
    git add -A
    git commit -qm 'chore(deps): bump something'
    git merge --no-ff -q "$pull_head" -m "Merge branch 'main' into refreshed-topic"
    refreshed_head=$(git rev-parse HEAD)

    if ! bash "$root/scripts/ci/mount-guard.sh" "$pull_head" "$refreshed_head" >/dev/null; then
        echo 'mount-guard.sh refused an innocent branch-refresh merge' >&2; exit 1
    fi

    # A refresh-shaped merge that ALSO sneaks its own mount edit must still be
    # refused: the edit differs from every parent, so the merge introduces it.
    git checkout -q -b poisoned-refresh "$base"
    echo 'lockfile change' > composer.fake
    git add -A
    git commit -qm 'chore(deps): bump something'
    git merge --no-ff -q --no-commit "$pull_head" >/dev/null 2>&1 || true
    echo 'smuggled edit' >> docs/ai-team/README.md
    git add -A
    git commit -qm "Merge branch 'main' into poisoned-refresh"
    poisoned_head=$(git rev-parse HEAD)

    if bash "$root/scripts/ci/mount-guard.sh" "$pull_head" "$poisoned_head" >/dev/null 2>&1; then
        echo 'mount-guard.sh accepted a merge that smuggled a mount edit' >&2; exit 1
    fi

    # A diff that ERRORS (missing parent objects, e.g. a shallow or corrupt
    # checkout) must abort the guard with exit >1, never read as "no change"
    # (Copilot's fail-closed finding on #479).
    shallow_clone=$(mktemp -d)
    git clone -q --depth 1 "file://$PWD" "$shallow_clone" 2>/dev/null
    (
        cd "$shallow_clone"
        guard_status=0
        bash "$root/scripts/ci/mount-guard.sh" "$base" "$refreshed_head" >/dev/null 2>&1 || guard_status=$?
        if [ "$guard_status" -le 1 ]; then
            echo "mount-guard.sh judged a range with missing objects (exit $guard_status); it must fail closed" >&2
            exit 1
        fi
    )
    rm -rf "$shallow_clone"

    git checkout -q -b unrelated-branch "$base"
    echo 'app change' > app.php
    git add -A
    git commit -qm 'feat: unrelated change'
    unrelated_head=$(git rev-parse HEAD)

    if ! bash "$root/scripts/ci/mount-guard.sh" "$base" "$unrelated_head" >/dev/null; then
        echo 'mount-guard.sh flagged a range with no docs/ai-team/ changes' >&2; exit 1
    fi
)
rm -rf "$mount_guard_fixture"
trap - EXIT

if command -v php >/dev/null; then
    php scripts/ci/domain-ci.php validate

    resolved=$(php scripts/ci/domain-ci.php resolve \
        --domain-id=people \
        --caller-repository=belimbingapp/BLB-PEOPLE \
        --workflow-ref=0123456789abcdef0123456789abcdef01234567)
    grep -q '^DOMAIN_PATH=app/Domains/People$' <<< "$resolved"
    if php scripts/ci/domain-ci.php resolve \
        --domain-id=people \
        --caller-repository=BelimbingApp/blb-commerce \
        --workflow-ref=0123456789abcdef0123456789abcdef01234567 2>/dev/null; then
        echo 'domain-ci accepted a mismatched caller repository' >&2
        exit 1
    fi

    caller=$(php scripts/ci/domain-ci.php render \
        --domain-id=people \
        --workflow-ref=0123456789abcdef0123456789abcdef01234567)
    grep -q 'SONAR_TOKEN: ${{ secrets.SONAR_TOKEN }}' <<< "$caller"
    if grep -q 'secrets: inherit' <<< "$caller"; then
        echo 'domain-ci rendered broad secret inheritance' >&2
        exit 1
    fi

    invalid_descriptor=$(mktemp)
    trap 'rm -f "$invalid_descriptor"' EXIT
    python3 -c 'import json,sys; data=json.load(open(sys.argv[1])); data["domains"]["people"]["ref"]="main"; json.dump(data,open(sys.argv[2],"w"))' \
        scripts/ci/domain-repos.json "$invalid_descriptor"
    if php scripts/ci/domain-ci.php validate --descriptor="$invalid_descriptor" 2>/dev/null; then
        echo 'domain-ci accepted a mutable Domain ref' >&2
        exit 1
    fi
    python3 -c 'import json,sys; data=json.load(open(sys.argv[1])); data["domains"]["people"]["repo"]="invalid"; json.dump(data,open(sys.argv[2],"w"))' \
        scripts/ci/domain-repos.json "$invalid_descriptor"
    if php scripts/ci/domain-ci.php validate --descriptor="$invalid_descriptor" 2>/dev/null; then
        echo 'domain-ci accepted an invalid repository slug' >&2
        exit 1
    fi
    php scripts/ci/validate-php-syntax.php scripts/ci/domain-ci.php scripts/ci/compose-domain.php scripts/ci/filter-domain-coverage-clover.php scripts/ci/validate-extension-manifest.php
    php scripts/ci/validate-extension-manifest.php tests/Fixtures/ci/extensions/conventional/Example/composer.json
    if php scripts/ci/validate-extension-manifest.php tests/Fixtures/ci/extensions/invalid/Example/composer.json >/dev/null 2>&1; then
        echo 'invalid Extension manifest was accepted' >&2; exit 1
    fi
    rendered=$(php scripts/ci/domain-ci.php render --domain-id=people --workflow-ref=0123456789abcdef0123456789abcdef01234567)
    grep -q 'domain-id: people' <<< "$rendered"
    grep -q 'platform-ref: 0123456789abcdef0123456789abcdef01234567' <<< "$rendered"
    if php scripts/ci/domain-ci.php render --domain-id=people --workflow-ref=main >/dev/null 2>&1; then
        echo 'mutable workflow ref was accepted' >&2; exit 1
    fi
else
    echo 'SKIP: PHP checks (php is unavailable)' >&2
fi

echo 'CI script checks passed'
