#!/usr/bin/env bash
set -euo pipefail

root=$(git rev-parse --show-toplevel)
cd "$root"
python3 tests/ci/test-domain-pins.py
bash -n scripts/ci/changed-authorable-php.sh scripts/ci/extension-conformance.sh scripts/ci/mount-guard.sh scripts/ci/phpstan-baseline-gate.sh scripts/ci/record-pest-timing.sh scripts/ci/token-audit.sh
python3 -m py_compile scripts/ci/aggregate-pest-timing.py scripts/ci/pest-timing-ratchet.py scripts/ci/refresh-livewire-action-baselines.py

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

python3 -m py_compile scripts/ci/upsert-pr-ci-summary-comment.py

# #670: one PR comment for timing + coverage delta; two upserts replace, not stack.
upsert_fixture=$(mktemp -d)
trap 'rm -rf "$upsert_fixture"' EXIT
mkdir -p "$upsert_fixture/timing"
printf '%s\n' '{"job":"Unit","suite":"Unit","wall_seconds":1.25,"tests":2,"assertions":4}' > "$upsert_fixture/timing/a.json"
cp tests/ci/fixtures/coverage-ratchet/high-a.xml "$upsert_fixture/a.xml"
cp tests/ci/fixtures/coverage-ratchet/high-b.xml "$upsert_fixture/b.xml"
printf '%s\n' '{"line_rate":80.0,"coveredstatements":80,"statements":100,"tolerance_pp":0.05}' > "$upsert_fixture/baseline.json"
dry=$(python3 scripts/ci/upsert-pr-ci-summary-comment.py \
  --timing-dir "$upsert_fixture/timing" \
  --baseline "$upsert_fixture/baseline.json" \
  --dry-run \
  "$upsert_fixture/a.xml" "$upsert_fixture/b.xml")
grep -q '<!-- belimbing-ci-run-summary -->' <<< "$dry"
grep -q '| Unit | Unit | 1.250 | 2 | 4 |' <<< "$dry"
grep -q '## Coverage delta' <<< "$dry"
grep -q 'Measured' <<< "$dry"
python3 scripts/ci/upsert-pr-ci-summary-comment.py \
  --timing-dir "$upsert_fixture/timing" \
  --baseline "$upsert_fixture/baseline.json" \
  --memory-fixture \
  "$upsert_fixture/a.xml" "$upsert_fixture/b.xml"
rm -rf "$upsert_fixture"
trap - EXIT

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
# directory / test file so CI cannot silently drop coverage (#576 / #626).
python3 -m json.tool scripts/ci/platform-feature-shards.json >/dev/null
python3 -m json.tool scripts/ci/platform-feature-shard-timings.json >/dev/null
python3 scripts/ci/platform-feature-shards.py --validate-only >/dev/null
grep -q 'platform-feature-shards.py' .github/workflows/tests.yml
grep -q 'platform-feature-shard-timings.json' scripts/ci/platform-feature-shards.json

# Feature shard membership must fail closed, not merely parse (#576 / #626).
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
rm -f "$shard_root/tests/Feature/LooseTest.php"

# Nested files under a claimed directory are covered; omitting the directory
# must fail closed so every Feature *Test.php stays in exactly one shard (#626).
mkdir -p "$shard_root/tests/Feature/Alpha/Nested"
touch "$shard_root/tests/Feature/Alpha/Nested/HiddenTest.php"
printf '{"shards":{"a":["Alpha","Beta"],"b":["Gamma"]}}' > "$shard_file"
if ! python3 scripts/ci/platform-feature-shards.py --root "$shard_root" --shards-file "$shard_file" --validate-only >/dev/null; then
    echo 'Feature shard validator rejected a nested file under a claimed directory' >&2; exit 1
fi
printf '{"shards":{"a":["Beta"],"b":["Gamma"]}}' > "$shard_file"
if python3 scripts/ci/platform-feature-shards.py --root "$shard_root" --shards-file "$shard_file" --validate-only >/dev/null 2>&1; then
    echo 'Feature shard validator accepted omitted Alpha files' >&2; exit 1
fi

# --write-balanced must place directories by longest-processing-time so the
# heaviest directory sits alone against the two lighter ones (#626).
printf '{"directories":{"Alpha":{"wall_seconds":10},"Beta":{"wall_seconds":6},"Gamma":{"wall_seconds":5}}}' > "$shard_root/timings.json"
printf '{"shards":{"a":["Alpha","Beta"],"b":["Gamma"]}}' > "$shard_file"
python3 scripts/ci/platform-feature-shards.py --root "$shard_root" --shards-file "$shard_file" --timings-file "$shard_root/timings.json" --write-balanced >/dev/null
balanced=$(python3 -c 'import json,sys; print(json.dumps(json.load(open(sys.argv[1]))["shards"], sort_keys=True))' "$shard_file")
if [[ "$balanced" != '{"a": ["Alpha"], "b": ["Beta", "Gamma"]}' ]]; then
    echo "Feature shard balancer did not apply longest-processing-time: $balanced" >&2; exit 1
fi
rm -rf "$shard_root"

# Feature shard timing refresh from measured Feature-* suite walls (#695).
timings_refresh_root=$(mktemp -d)
mkdir -p "$timings_refresh_root/tests/Feature/Alpha" \
    "$timings_refresh_root/tests/Feature/Beta" \
    "$timings_refresh_root/timing"
touch "$timings_refresh_root/tests/Feature/Alpha/ExampleTest.php" \
    "$timings_refresh_root/tests/Feature/Beta/ExampleTest.php"
printf '%s\n' '{"shards":{"a":["Alpha"],"b":["Beta"]}}' \
    > "$timings_refresh_root/shards.json"
printf '%s\n' '{"source":"fixture","measured_at":"2020-01-01T00:00:00Z","note":"","directories":{"Alpha":{"wall_seconds":1,"test_files":1},"Beta":{"wall_seconds":1,"test_files":1}}}' \
    > "$timings_refresh_root/summary.json"
printf '%s\n' '{"job":"Feature-a","suite":"Feature-a","wall_seconds":30,"tests":1,"assertions":1}' \
    > "$timings_refresh_root/timing/Feature-a__Feature-a.json"
printf '%s\n' '{"job":"Feature-b","suite":"Feature-b","wall_seconds":20,"tests":1,"assertions":1}' \
    > "$timings_refresh_root/timing/Feature-b__Feature-b.json"
python3 scripts/ci/platform-feature-shard-timings.py \
    --timing-dir "$timings_refresh_root/timing" \
    --shards-file "$timings_refresh_root/shards.json" \
    --summary-file "$timings_refresh_root/summary.json" \
    --root "$timings_refresh_root" \
    --write >/dev/null
refreshed=$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1]))["directories"]; print(d["Alpha"]["wall_seconds"], d["Beta"]["wall_seconds"])' "$timings_refresh_root/summary.json")
if [[ "$refreshed" != "30.0 20.0" ]]; then
    echo "Feature shard timing refresh did not rewrite directory walls: $refreshed" >&2
    exit 1
fi
mkdir -p "$timings_refresh_root/tests/Feature/Gamma"
touch "$timings_refresh_root/tests/Feature/Gamma/ExampleTest.php"
missing_out=$(python3 scripts/ci/platform-feature-shard-timings.py \
    --timing-dir "$timings_refresh_root/timing" \
    --shards-file "$timings_refresh_root/shards.json" \
    --summary-file "$timings_refresh_root/summary.json" \
    --root "$timings_refresh_root" 2>&1) && {
    echo 'Feature shard timing refresh accepted a Feature directory with no measurement' >&2
    exit 1
}
printf '%s\n' "$missing_out" | grep -q 'Feature directory has no measurement: Gamma' || {
    echo "missing-directory refusal did not name Gamma: $missing_out" >&2
    exit 1
}
rm -rf "$timings_refresh_root"

# Unit shard timing refresh from measured Unit-* suite walls (#767).
timings_refresh_root=$(mktemp -d)
mkdir -p "$timings_refresh_root/tests/Unit/Alpha" \
    "$timings_refresh_root/tests/Unit/Beta" \
    "$timings_refresh_root/timing"
touch "$timings_refresh_root/tests/Unit/Alpha/ExampleTest.php" \
    "$timings_refresh_root/tests/Unit/Beta/ExampleTest.php"
printf '%s\n' '{"shards":{"a":["Alpha"],"b":["Beta"]}}' \
    > "$timings_refresh_root/shards.json"
printf '%s\n' '{"source":"fixture","measured_at":"2020-01-01T00:00:00Z","note":"","directories":{"Alpha":{"wall_seconds":1,"test_files":1},"Beta":{"wall_seconds":1,"test_files":1}}}' \
    > "$timings_refresh_root/summary.json"
printf '%s\n' '{"job":"Unit-a","suite":"Unit-a","wall_seconds":30,"tests":1,"assertions":1}' \
    > "$timings_refresh_root/timing/Unit-a__Unit-a.json"
printf '%s\n' '{"job":"Unit-b","suite":"Unit-b","wall_seconds":20,"tests":1,"assertions":1}' \
    > "$timings_refresh_root/timing/Unit-b__Unit-b.json"
python3 scripts/ci/platform-feature-shard-timings.py --suite=Unit \
    --timing-dir "$timings_refresh_root/timing" \
    --shards-file "$timings_refresh_root/shards.json" \
    --summary-file "$timings_refresh_root/summary.json" \
    --root "$timings_refresh_root" \
    --write >/dev/null
refreshed=$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1]))["directories"]; print(d["Alpha"]["wall_seconds"], d["Beta"]["wall_seconds"])' "$timings_refresh_root/summary.json")
if [[ "$refreshed" != "30.0 20.0" ]]; then
    echo "Unit shard timing refresh did not rewrite directory walls: $refreshed" >&2
    exit 1
fi
mkdir -p "$timings_refresh_root/tests/Unit/Gamma"
touch "$timings_refresh_root/tests/Unit/Gamma/ExampleTest.php"
missing_out=$(python3 scripts/ci/platform-feature-shard-timings.py --suite=Unit \
    --timing-dir "$timings_refresh_root/timing" \
    --shards-file "$timings_refresh_root/shards.json" \
    --summary-file "$timings_refresh_root/summary.json" \
    --root "$timings_refresh_root" 2>&1) && {
    echo 'Unit shard timing refresh accepted a Unit directory with no measurement' >&2
    exit 1
}
printf '%s\n' "$missing_out" | grep -q 'Unit directory has no measurement: Gamma' || {
    echo "missing-directory refusal did not name Gamma: $missing_out" >&2
    exit 1
}
rm -rf "$timings_refresh_root"

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

# Blocked-By sweep mints a GitHub App installation token so qualified
# BelimbingApp/blb-people and blb-people-connector blockers can resolve (#621).
# Default github.token cannot read other repositories; keep the job's
# permissions block for checkout; the shared user PAT is only the fallback
# until the App secrets exist, and never the primary credential.
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
    'AI_TEAM_GITHUB_APP_ID: ${{ secrets.AI_TEAM_GITHUB_APP_ID }}',
    'AI_TEAM_GITHUB_APP_PRIVATE_KEY: ${{ secrets.AI_TEAM_GITHUB_APP_PRIVATE_KEY }}',
    'uses: actions/create-github-app-token@v2',
    "if: steps.credentials.outputs.source == 'app'",
    'GITHUB_TOKEN: ${{ steps.app-token.outputs.token || secrets.AI_TEAM_BLOCKED_BY_SWEEP_TOKEN }}',
    'GITHUB_REPOSITORY: ${{ github.repository }}',
    'run: python3 docs/ai-team/scripts/blocked_by_sweep.py',
    'if [[ -n "$AI_TEAM_GITHUB_APP_ID" && -n "$AI_TEAM_GITHUB_APP_PRIVATE_KEY" ]]; then',
    'elif [[ -n "$AI_TEAM_BLOCKED_BY_SWEEP_TOKEN" ]]; then',
)
for contract in required:
    assert contract in workflow, f'missing blocked-by sweep contract: {contract!r}'

assert 'GITHUB_TOKEN: ${{ github.token }}' not in sweep, (
    'sweep step must not use default github.token; mint an App installation token (#621)'
)
assert 'GITHUB_TOKEN: ${{ secrets.AI_TEAM_BLOCKED_BY_SWEEP_TOKEN }}' not in workflow, (
    'the shared-user PAT may only be the fallback behind the App token, never the primary credential (#621)'
)
assert workflow.count('secrets.AI_TEAM_BLOCKED_BY_SWEEP_TOKEN') == 2, (
    'the PAT secret is read exactly twice: the credential-choice env and the fallback expression'
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
        if [[ "$guard_status" -le 1 ]]; then
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
    php scripts/ci/validate-php-syntax.php scripts/ci/domain-ci.php scripts/ci/compose-domain.php scripts/ci/filter-domain-coverage-clover.php scripts/ci/validate-extension-manifest.php scripts/ci/composed-smoke.php scripts/ci/pinned-mount-check.php

    # validate-php-syntax.php (#856): the Extension syntax gate
    # (extension-conformance.sh) fails an Extension whose PHP does not parse.
    # The happy path above only feeds known-good files; without an invalid-file
    # case, exit(1) and the path-in-stderr message can be deleted unnoticed.
    syntax_fixture=$(mktemp -d)
    printf '<?php\n' > "$syntax_fixture/good.php"
    printf '<?php\n function (\n' > "$syntax_fixture/bad.php"
    printf '<?php\n function (\n' > "$syntax_fixture/also-bad.php"
    if ! php scripts/ci/validate-php-syntax.php "$syntax_fixture/good.php" 2>"$syntax_fixture/good.err"; then
        echo 'validate-php-syntax.php refused a valid PHP file' >&2
        cat "$syntax_fixture/good.err" >&2
        rm -rf "$syntax_fixture"
        exit 1
    fi
    if [[ -s "$syntax_fixture/good.err" ]]; then
        echo 'validate-php-syntax.php wrote stderr for a valid PHP file' >&2
        cat "$syntax_fixture/good.err" >&2
        rm -rf "$syntax_fixture"
        exit 1
    fi
    if php scripts/ci/validate-php-syntax.php "$syntax_fixture/good.php" "$syntax_fixture/bad.php" "$syntax_fixture/also-bad.php" 2>"$syntax_fixture/bad.err"; then
        echo 'validate-php-syntax.php accepted invalid PHP syntax' >&2
        rm -rf "$syntax_fixture"
        exit 1
    fi
    if ! grep -qF "extension-conformance: invalid PHP syntax in $syntax_fixture/bad.php" "$syntax_fixture/bad.err"; then
        echo 'validate-php-syntax.php did not name the offending path on stderr' >&2
        cat "$syntax_fixture/bad.err" >&2
        rm -rf "$syntax_fixture"
        exit 1
    fi
    if grep -qF "$syntax_fixture/also-bad.php" "$syntax_fixture/bad.err"; then
        echo 'validate-php-syntax.php continued past the first invalid file' >&2
        cat "$syntax_fixture/bad.err" >&2
        rm -rf "$syntax_fixture"
        exit 1
    fi
    rm -rf "$syntax_fixture"

    # setup-sonar.php (#856): hermetic refusals before any SonarCloud/network
    # call. SONAR_TOKEN=placeholder keeps resolveSonarToken off the checkout
    # .env; --registry= points at a temp file so loadRegistry never reads the
    # live domain registry. These three exits are the testable contract.
    sonar_fixture=$(mktemp -d)
    unknown_rc=0
    SONAR_TOKEN=placeholder php scripts/ci/setup-sonar.php --not-a-real-flag 2>"$sonar_fixture/unknown.err" || unknown_rc=$?
    if [[ "$unknown_rc" -ne 1 ]]; then
        echo "setup-sonar.php exited $unknown_rc (expected 1) for an unknown argument; it must stop before any SonarCloud call" >&2
        cat "$sonar_fixture/unknown.err" >&2
        rm -rf "$sonar_fixture"
        exit 1
    fi
    if ! grep -qF 'Unknown argument:' "$sonar_fixture/unknown.err"; then
        echo 'setup-sonar.php did not report Unknown argument:' >&2
        cat "$sonar_fixture/unknown.err" >&2
        rm -rf "$sonar_fixture"
        exit 1
    fi
    if SONAR_TOKEN=placeholder php scripts/ci/setup-sonar.php --registry="$sonar_fixture/missing.json" 2>"$sonar_fixture/missing.err"; then
        echo 'setup-sonar.php accepted a missing registry' >&2
        rm -rf "$sonar_fixture"
        exit 1
    fi
    if ! grep -qF "Registry not found: $sonar_fixture/missing.json" "$sonar_fixture/missing.err"; then
        echo 'setup-sonar.php did not report Registry not found:' >&2
        cat "$sonar_fixture/missing.err" >&2
        rm -rf "$sonar_fixture"
        exit 1
    fi
    if grep -qF 'Invalid registry:' "$sonar_fixture/missing.err"; then
        echo 'setup-sonar.php fell past the unreadable-registry exit into the invalid-registry guard' >&2
        cat "$sonar_fixture/missing.err" >&2
        rm -rf "$sonar_fixture"
        exit 1
    fi
    printf '{"foo":1}\n' > "$sonar_fixture/invalid.json"
    if SONAR_TOKEN=placeholder php scripts/ci/setup-sonar.php --registry="$sonar_fixture/invalid.json" 2>"$sonar_fixture/invalid.err"; then
        echo 'setup-sonar.php accepted a registry without domains' >&2
        rm -rf "$sonar_fixture"
        exit 1
    fi
    if ! grep -qF "Invalid registry: $sonar_fixture/invalid.json" "$sonar_fixture/invalid.err"; then
        echo 'setup-sonar.php did not report Invalid registry:' >&2
        cat "$sonar_fixture/invalid.err" >&2
        rm -rf "$sonar_fixture"
        exit 1
    fi
    printf '{"domains":1}\n' > "$sonar_fixture/scalar.json"
    if SONAR_TOKEN=placeholder php scripts/ci/setup-sonar.php --registry="$sonar_fixture/scalar.json" 2>"$sonar_fixture/scalar.err"; then
        echo 'setup-sonar.php accepted a registry with a scalar domains value' >&2
        rm -rf "$sonar_fixture"
        exit 1
    fi
    if ! grep -qF "Invalid registry: $sonar_fixture/scalar.json" "$sonar_fixture/scalar.err"; then
        echo 'setup-sonar.php did not report Invalid registry: for a scalar domains' >&2
        cat "$sonar_fixture/scalar.err" >&2
        rm -rf "$sonar_fixture"
        exit 1
    fi
    rm -rf "$sonar_fixture"

    # The composed-application smoke test (#600) judges a boot against a
    # checked-in surface; without a network only its migration scan and the
    # surface/descriptor pin agreement can be proven here. The workflow
    # composed-smoke.yml runs the full boot on every PR.
    php scripts/ci/composed-smoke.php --scan-only --root=tests/Fixtures/ci/composed/clean 2>/dev/null
    if php scripts/ci/composed-smoke.php --scan-only --root=tests/Fixtures/ci/composed/duplicate >/dev/null 2>&1; then
        echo 'composed-smoke accepted a migration name shipped by two modules' >&2; exit 1
    fi
    python3 - <<'PY'
import json
surface = json.load(open('scripts/ci/composed-surface.json'))
descriptor = json.load(open('scripts/ci/domain-repos.json'))
for domain, pin in surface['pins'].items():
    assert descriptor['domains'][domain]['ref'] == pin, f'composed-surface.json pins {domain} at {pin}, descriptor at {descriptor["domains"][domain]["ref"]}'
assert surface['domain_route_count'] == len(surface['route_names']) > 0 and surface['route_names'] == sorted(set(surface['route_names'])), 'composed-surface.json route names must be unique and sorted'
PY

    # Every surface guard, driven against a throwaway platform root whose two
    # Domain mounts are one-commit git repositories, with --routes-json standing in
    # for the boot (documented injectable harness for #600 review). Each mutation must turn the smoke red on its own.
    smoke_root=$(mktemp -d)
    trap 'rm -rf "$smoke_root"' EXIT
    mkdir -p "$smoke_root/app/Domains" "$smoke_root/scripts/ci"
    declare -A smoke_sha
    for mount in People PeopleConnector; do
        git -C "$smoke_root" init -q "app/Domains/$mount"
        git -C "$smoke_root/app/Domains/$mount" -c user.name=smoke -c user.email=smoke@example.test commit -q --allow-empty -m "$mount"
        smoke_sha[$mount]=$(git -C "$smoke_root/app/Domains/$mount" rev-parse HEAD)
    done
    smoke_descriptor() {
        local people_sha="$1" connector_sha="$2"
        python3 - "$smoke_root/scripts/ci/domain-repos.json" "$people_sha" "$connector_sha" <<'PY'
import json, sys
json.dump({"domains": {
    "people": {"repo": "BelimbingApp/blb-people", "path": "app/Domains/People", "ref": sys.argv[2]},
    "people-connector": {"repo": "BelimbingApp/blb-people-connector", "path": "app/Domains/PeopleConnector", "ref": sys.argv[3]},
}}, open(sys.argv[1], "w"))
PY
    }
    smoke_surface() {
        local people_sha="$1" connector_sha="$2" route_count="$3" route_names="$4"
        python3 - "$smoke_root/scripts/ci/composed-surface.json" "$people_sha" "$connector_sha" "$route_count" "$route_names" <<'PY'
import json, sys
json.dump({"pins": {"people": sys.argv[2], "people-connector": sys.argv[3]}, "domain_route_count": int(sys.argv[4]), "route_names": sys.argv[5].split(",")}, open(sys.argv[1], "w"))
PY
    }
    # Domain surface is live names matching DOMAIN_ROUTE_NAME (#916/#920). Base
    # admin.integration.* may appear in the table without counting as Domain.
    mkdir -p "$smoke_root/app/Domains/People/Workforce/Routes" \
        "$smoke_root/app/Domains/PeopleConnector/Connector/Routes"
    printf "%s\n" "<?php" "Route::get('people', fn () => null)->name('people.index');" \
        > "$smoke_root/app/Domains/People/Workforce/Routes/web.php"
    printf "%s\n" "<?php" "Route::get('admin/people-connector', fn () => null)->name('admin.people-connector.index');" \
        > "$smoke_root/app/Domains/PeopleConnector/Connector/Routes/web.php"
    printf '[{"name":"people.index","uri":"people"},{"name":"admin.people-connector.index","uri":"admin/people-connector"},{"name":"admin.integration.outbound-exchanges.index","uri":"admin/integration/outbound-exchanges"},{"name":"admin.system.info.index","uri":"admin/system/info"},{"name":null,"uri":"livewire/update"}]' > "$smoke_root/routes.json"
    smoke() {
        php scripts/ci/composed-smoke.php --root="$smoke_root" --routes-json="$smoke_root/routes.json" \
            --registry="$smoke_root/scripts/ci/domain-repos.json" --surface="$smoke_root/scripts/ci/composed-surface.json" "$@"
    }
    smoke_descriptor "${smoke_sha[People]}" "${smoke_sha[PeopleConnector]}"
    smoke_surface "${smoke_sha[People]}" "${smoke_sha[PeopleConnector]}" 2 'admin.people-connector.index,people.index'
    smoke 2>/dev/null
    smoke_surface "${smoke_sha[People]}" "${smoke_sha[PeopleConnector]}" 2 'admin.people-connector.index,people.index,people.missing'
    if smoke >/dev/null 2>&1; then
        echo 'composed-smoke accepted a table missing an expected route name' >&2; exit 1
    fi
    smoke_surface "${smoke_sha[People]}" "${smoke_sha[PeopleConnector]}" 3 'admin.people-connector.index,people.index'
    if smoke >/dev/null 2>&1; then
        echo 'composed-smoke accepted a domain route count that does not match the surface' >&2; exit 1
    fi
    smoke_surface "${smoke_sha[People]}" 0123456789abcdef0123456789abcdef01234567 2 'admin.people-connector.index,people.index'
    if smoke >/dev/null 2>&1; then
        echo 'composed-smoke accepted a surface whose pins disagree with the descriptor' >&2; exit 1
    fi
    smoke_descriptor 0123456789abcdef0123456789abcdef01234567 "${smoke_sha[PeopleConnector]}"
    smoke_surface 0123456789abcdef0123456789abcdef01234567 "${smoke_sha[PeopleConnector]}" 2 'admin.people-connector.index,people.index'
    if smoke >/dev/null 2>&1; then
        echo 'composed-smoke accepted a mount whose HEAD is not the pinned ref' >&2; exit 1
    fi

    # #916: adding a Base-owned admin.integration.* name must stay green and
    # leave domain_route_count unchanged.
    smoke_descriptor "${smoke_sha[People]}" "${smoke_sha[PeopleConnector]}"
    smoke_surface "${smoke_sha[People]}" "${smoke_sha[PeopleConnector]}" 2 'admin.people-connector.index,people.index'
    printf '[{"name":"people.index","uri":"people"},{"name":"admin.people-connector.index","uri":"admin/people-connector"},{"name":"admin.integration.outbound-exchanges.index","uri":"admin/integration/outbound-exchanges"},{"name":"admin.integration.outbound-exchanges.show","uri":"admin/integration/outbound-exchanges/1"}]' \
        > "$smoke_root/routes.json"
    smoke 2>/dev/null || {
        echo 'composed-smoke turned red when only a Base admin.integration.* route was added' >&2
        smoke 2>&1 || true
        exit 1
    }

    # A genuinely Domain-declared name appearing in the table without a surface
    # bump still turns red — the count guard remains load-bearing.
    printf "%s\n" "<?php" "Route::get('people', fn () => null)->name('people.index');" \
        "Route::get('people/extra', fn () => null)->name('people.extra');" \
        > "$smoke_root/app/Domains/People/Workforce/Routes/web.php"
    printf '[{"name":"people.index","uri":"people"},{"name":"people.extra","uri":"people/extra"},{"name":"admin.people-connector.index","uri":"admin/people-connector"},{"name":"admin.integration.outbound-exchanges.index","uri":"admin/integration/outbound-exchanges"}]' \
        > "$smoke_root/routes.json"
    if smoke >/dev/null 2>&1; then
        echo 'composed-smoke accepted an extra Domain-declared route without a surface bump' >&2; exit 1
    fi
    domain_err=$(smoke 2>&1 || true)
    grep -q 'domain route count is 3, expected 2' <<< "$domain_err"
    # Restore the two-route declaration for later cases.
    printf "%s\n" "<?php" "Route::get('people', fn () => null)->name('people.index');" \
        > "$smoke_root/app/Domains/People/Workforce/Routes/web.php"

    # #920: Route::name()->group + Route::resource assembled names never appear as
    # one literal ->name() string. They must still move the Domain surface count.
    printf "%s\n" "<?php" "Route::get('people', fn () => null)->name('people.index');" \
        "Route::name('people.')->group(function () {" \
        "    Route::resource('things', ThingController::class)->only(['index', 'store']);" \
        "});" \
        > "$smoke_root/app/Domains/People/Workforce/Routes/web.php"
    printf '[{"name":"people.index","uri":"people"},{"name":"people.things.index","uri":"people/things"},{"name":"people.things.store","uri":"people/things"},{"name":"admin.people-connector.index","uri":"admin/people-connector"},{"name":"admin.integration.outbound-exchanges.index","uri":"admin/integration/outbound-exchanges"}]' \
        > "$smoke_root/routes.json"
    if smoke >/dev/null 2>&1; then
        echo 'composed-smoke accepted Route::resource group names without a surface bump' >&2; exit 1
    fi
    resource_err=$(smoke 2>&1 || true)
    grep -q 'domain route count is 4, expected 2' <<< "$resource_err"
    printf "%s\n" "<?php" "Route::get('people', fn () => null)->name('people.index');" \
        > "$smoke_root/app/Domains/People/Workforce/Routes/web.php"

    # #870: DOMAIN_ROUTE_NAME must own people-connector.* (signed webhook) and
    # refuse a Domain Routes declaration outside the prefix list.
    smoke_descriptor "${smoke_sha[People]}" "${smoke_sha[PeopleConnector]}"
    printf "%s\n" "<?php" "Route::post('webhooks/people-connector/{id}', fn () => null)->name('people-connector.webhook');" \
        > "$smoke_root/app/Domains/PeopleConnector/Connector/Routes/web.php"
    printf '[{"name":"people.index","uri":"people"},{"name":"people-connector.webhook","uri":"webhooks/people-connector/1"},{"name":"admin.integration.outbound-exchanges.index","uri":"admin/integration/outbound-exchanges"}]' \
        > "$smoke_root/routes.json"
    smoke_surface "${smoke_sha[People]}" "${smoke_sha[PeopleConnector]}" 2 'people-connector.webhook,people.index'
    smoke 2>/dev/null

    # Narrowing the filter by dropping people-connector. must turn red: the
    # Routes declaration is then unmatched (#870).
    narrowed="$smoke_root/composed-smoke-narrowed.php"
    cp scripts/ci/composed-smoke.php "$narrowed"
    python3 - "$narrowed" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
text = path.read_text()
old = "const DOMAIN_ROUTE_NAME = '/^(people\\.|people-connector\\.|admin\\.people-connector\\.|commerce\\.|it\\.|quality\\.)/';"
new = "const DOMAIN_ROUTE_NAME = '/^(people\\.|admin\\.people-connector\\.|commerce\\.|it\\.|quality\\.)/';"
assert old in text, 'DOMAIN_ROUTE_NAME const missing for the #870 mutant'
path.write_text(text.replace(old, new, 1))
PY
    narrowed_err=$(php "$narrowed" --root="$smoke_root" --routes-json="$smoke_root/routes.json" \
        --registry="$smoke_root/scripts/ci/domain-repos.json" --surface="$smoke_root/scripts/ci/composed-surface.json" \
        2>&1 || true)
    if ! grep -q 'people-connector.webhook' <<< "$narrowed_err"; then
        echo 'composed-smoke accepted people-connector.webhook after DOMAIN_ROUTE_NAME dropped people-connector.' >&2
        echo "$narrowed_err" >&2
        exit 1
    fi

    # An undeclared prefix in Domain Routes is refused even when the route table is empty of it.
    printf "%s\n" "<?php" "Route::get('odd', fn () => null)->name('odd.domain.route');" \
        > "$smoke_root/app/Domains/PeopleConnector/Connector/Routes/web.php"
    printf '[{"name":"people.index","uri":"people"},{"name":"admin.integration.outbound-exchanges.index","uri":"admin/integration/outbound-exchanges"}]' \
        > "$smoke_root/routes.json"
    smoke_surface "${smoke_sha[People]}" "${smoke_sha[PeopleConnector]}" 1 'people.index'
    if smoke >/dev/null 2>&1; then
        echo 'composed-smoke accepted a Domain Routes name outside DOMAIN_ROUTE_NAME' >&2
        exit 1
    fi
    smoke_err=$(smoke 2>&1 || true)
    grep -q 'odd.domain.route' <<< "$smoke_err"

    # --print-surface must still regenerate when pins disagree (advance-domain-pin
    # intermediate state) but still refuse unmatched Domain Routes names (#871).
    printf "%s\n" "<?php" "Route::post('webhooks/people-connector/{id}', fn () => null)->name('people-connector.webhook');" \
        > "$smoke_root/app/Domains/PeopleConnector/Connector/Routes/web.php"
    printf '[{"name":"people.index","uri":"people"},{"name":"people-connector.webhook","uri":"webhooks/people-connector/1"},{"name":"admin.integration.outbound-exchanges.index","uri":"admin/integration/outbound-exchanges"}]' \
        > "$smoke_root/routes.json"
    smoke_descriptor "${smoke_sha[People]}" "${smoke_sha[PeopleConnector]}"
    smoke_surface "${smoke_sha[People]}" 1111111111111111111111111111111111111111 2 'people-connector.webhook,people.index'
    printed=$(smoke --print-surface 2>/dev/null) || {
        echo 'composed-smoke --print-surface failed while only the surface pin was stale' >&2
        exit 1
    }
    python3 -c 'import json,sys; d=json.load(sys.stdin); assert "pins" in d and "route_names" in d' <<< "$printed"

    printf "%s\n" "<?php" "Route::get('odd', fn () => null)->name('odd.domain.route');" \
        > "$smoke_root/app/Domains/PeopleConnector/Connector/Routes/web.php"
    if smoke --print-surface >/dev/null 2>&1; then
        echo 'composed-smoke --print-surface accepted a Domain Routes name outside DOMAIN_ROUTE_NAME' >&2
        exit 1
    fi
    print_err=$(smoke --print-surface 2>&1 || true)
    grep -q 'odd.domain.route' <<< "$print_err"

    rm -rf "$smoke_root"
    trap - EXIT
    php scripts/ci/validate-extension-manifest.php tests/Fixtures/ci/extensions/conventional/Example/composer.json
    if php scripts/ci/validate-extension-manifest.php tests/Fixtures/ci/extensions/invalid/Example/composer.json >/dev/null 2>&1; then
        echo 'invalid Extension manifest was accepted' >&2; exit 1
    fi

    # extension-conformance.sh (#822): hermetic refusals + happy path.
    ext_root=$(mktemp -d)
    trap 'rm -rf "$ext_root"' EXIT
    cp -a tests/Fixtures/ci/extensions/conventional/. "$ext_root/"
    conf_out=$(scripts/ci/extension-conformance.sh "$ext_root")
    grep -q 'extension-conformance: passed 1 Module manifest(s)' <<< "$conf_out"

    empty_ext=$(mktemp -d)
    if scripts/ci/extension-conformance.sh "$empty_ext" >/dev/null 2>&1; then
        echo 'extension-conformance accepted an empty Extension root' >&2; exit 1
    fi
    empty_err=$(scripts/ci/extension-conformance.sh "$empty_ext" 2>&1 || true)
    grep -q 'no Module composer.json found' <<< "$empty_err"
    rm -rf "$empty_ext"

    bad_ext=$(mktemp -d)
    cp -a tests/Fixtures/ci/extensions/invalid/. "$bad_ext/"
    if scripts/ci/extension-conformance.sh "$bad_ext" >/dev/null 2>&1; then
        echo 'extension-conformance accepted an invalid Extension manifest' >&2; exit 1
    fi
    rm -rf "$bad_ext"

    assets_ext=$(mktemp -d)
    cp -a tests/Fixtures/ci/extensions/conventional/. "$assets_ext/"
    mkdir -p "$assets_ext/Example/Assets"
    printf 'console.log(1)\n' > "$assets_ext/Example/Assets/app.js"
    if scripts/ci/extension-conformance.sh "$assets_ext" >/dev/null 2>&1; then
        echo 'extension-conformance accepted owned assets without package.json and bun.lock' >&2; exit 1
    fi
    assets_err=$(scripts/ci/extension-conformance.sh "$assets_ext" 2>&1 || true)
    grep -q 'owned assets require package.json and bun.lock' <<< "$assets_err"
    rm -rf "$assets_ext"

    # Tracked migrations are excluded from Pint; the same untracked file fails.
    mig_ext=$(mktemp -d)
    cp -a tests/Fixtures/ci/extensions/conventional/. "$mig_ext/"
    mkdir -p "$mig_ext/Example/Database/Migrations"
    # Deliberately unformatted so Pint --test fails when the file is authorable.
    cat > "$mig_ext/Example/Database/Migrations/0330_01_01_000000_probe.php" <<'PHP'
<?php
return new class {
public function up(): void
{
$x=1;
}
};
PHP
    git -C "$mig_ext" init -q
    git -C "$mig_ext" config user.name 'ci'
    git -C "$mig_ext" config user.email 'ci@example.invalid'
    git -C "$mig_ext" add Example/composer.json Example/Example.php Example/Database/Migrations/0330_01_01_000000_probe.php
    git -C "$mig_ext" commit -qm 'tracked migration'
    scripts/ci/extension-conformance.sh "$mig_ext" >/dev/null
    # A never-tracked sibling must still face Pint (tracked exclusion is path+git).
    cat > "$mig_ext/Example/Database/Migrations/0330_01_01_000001_untracked_probe.php" <<'PHP'
<?php
return new class {
public function up(): void
{
$x=1;
}
};
PHP
    if scripts/ci/extension-conformance.sh "$mig_ext" >/dev/null 2>&1; then
        echo 'extension-conformance accepted an untracked migration that fails Pint' >&2; exit 1
    fi
    rm -rf "$mig_ext"

    rm -rf "$ext_root"
    trap - EXIT
    rendered=$(php scripts/ci/domain-ci.php render --domain-id=people --workflow-ref=0123456789abcdef0123456789abcdef01234567)
    grep -q 'domain-id: people' <<< "$rendered"
    grep -q 'platform-ref: 0123456789abcdef0123456789abcdef01234567' <<< "$rendered"
    if php scripts/ci/domain-ci.php render --domain-id=people --workflow-ref=main >/dev/null 2>&1; then
        echo 'mutable workflow ref was accepted' >&2; exit 1
    fi

    # filter-domain-coverage-clover.php (#842): Domain CI attributes Sonar
    # coverage only to the mount under test. Sibling domains, platform files,
    # and a path that merely contains the domain name as a substring must be
    # stripped; relative and absolute Clover paths under the mount must stay.
    clover_fixture=$(mktemp -d)
    trap 'rm -rf "$clover_fixture"' EXIT
    cp tests/ci/fixtures/domain-coverage-clover/mixed.xml "$clover_fixture/clover.xml"
    clover_err=$(
        php scripts/ci/filter-domain-coverage-clover.php \
            --domain-path=app/Domains/People \
            --coverage="$clover_fixture/clover.xml" 2>&1 >/dev/null
    )
    if ! grep -qF 'kept 2 file(s), removed 3' <<< "$clover_err"; then
        echo "filter-domain-coverage-clover.php summary mismatch: $clover_err" >&2
        exit 1
    fi
    python3 - "$clover_fixture/clover.xml" <<'PY'
import sys
import xml.etree.ElementTree as ET

tree = ET.parse(sys.argv[1])
root = tree.getroot()
assert root.tag == 'coverage', root.tag
project = root.find('project')
assert project is not None, 'missing <project> root'
names = sorted(f.get('name') for f in project.findall('file'))
expected = sorted([
    'app/Domains/People/Skills/Foo.php',
    '/home/runner/work/blb-people/blb-people/app/Domains/People/Skills/Bar.php',
])
assert names == expected, names
forbidden = (
    'app/Domains/PeopleConnector/Services/Sync.php',
    'app/Base/Tenancy/Tenant.php',
    'app/Domains/PeopleX/Ghost.php',
)
for name in forbidden:
    assert name not in names, name
PY
    clover_usage_status=0
    clover_usage_err=$(php scripts/ci/filter-domain-coverage-clover.php --domain-path=app/Domains/People 2>&1 >/dev/null) || clover_usage_status=$?
    if [[ "$clover_usage_status" -ne 2 ]]; then
        echo "filter-domain-coverage-clover.php missing --coverage exited $clover_usage_status, expected 2" >&2
        exit 1
    fi
    if ! grep -qF 'usage: filter-domain-coverage-clover.php --domain-path=<path> --coverage=<clover.xml>' <<< "$clover_usage_err"; then
        echo "filter-domain-coverage-clover.php did not print its usage line: $clover_usage_err" >&2
        exit 1
    fi
    clover_missing="$clover_fixture/missing.xml"
    clover_missing_status=0
    clover_missing_err=$(
        php scripts/ci/filter-domain-coverage-clover.php \
            --domain-path=app/Domains/People \
            --coverage="$clover_missing" 2>&1 >/dev/null
    ) || clover_missing_status=$?
    if [[ "$clover_missing_status" -ne 1 ]]; then
        echo "filter-domain-coverage-clover.php unreadable path exited $clover_missing_status, expected 1" >&2
        exit 1
    fi
    if ! grep -qF "coverage file not readable: $clover_missing" <<< "$clover_missing_err"; then
        echo "filter-domain-coverage-clover.php did not name the unreadable path: $clover_missing_err" >&2
        exit 1
    fi
    printf 'not xml\n' > "$clover_fixture/invalid.xml"
    clover_invalid_status=0
    clover_invalid_err=$(
        php scripts/ci/filter-domain-coverage-clover.php \
            --domain-path=app/Domains/People \
            --coverage="$clover_fixture/invalid.xml" 2>&1 >/dev/null
    ) || clover_invalid_status=$?
    if [[ "$clover_invalid_status" -ne 1 ]]; then
        echo "filter-domain-coverage-clover.php invalid XML exited $clover_invalid_status, expected 1" >&2
        exit 1
    fi
    if ! grep -qF 'invalid clover XML' <<< "$clover_invalid_err"; then
        echo "filter-domain-coverage-clover.php did not report invalid clover XML: $clover_invalid_err" >&2
        exit 1
    fi
    rm -rf "$clover_fixture"
    trap - EXIT
else
    echo 'SKIP: PHP checks (php is unavailable)' >&2
fi


# Platform coverage ratchet (#629): fail-first when coverage drops below the
# checked-in baseline, then pass at/above baseline; main update raises only.
python3 - <<'PY'
from pathlib import Path
import json
import subprocess
import tempfile

root = Path('.').resolve()
script = root / 'scripts/ci/platform-coverage-ratchet.py'
fixtures = root / 'tests/ci/fixtures/coverage-ratchet'
baseline_path = root / 'tests/ci/platform-coverage-baseline.json'
workflow = (root / '.github/workflows/tests.yml').read_text(encoding='utf-8')

assert script.is_file(), 'missing platform-coverage-ratchet.py'
assert baseline_path.is_file(), 'missing platform-coverage-baseline.json'
assert 'platform-coverage-ratchet.py check' in workflow
assert 'platform-coverage-ratchet.py update' in workflow
assert 'Raise platform coverage baseline on main' in workflow
assert 'Upsert PR timing and coverage comment' in workflow
assert 'upsert-pr-ci-summary-comment.py' in workflow
assert 'ci/raise-coverage-baseline' in workflow
assert 'gh pr create' in workflow
assert 'gh pr merge' in workflow
assert 'COVERAGE_BASELINE_RAISE_TOKEN: ${{ secrets.COVERAGE_BASELINE_RAISE_TOKEN }}' in workflow, 'raise token must be wired from secrets, not only mentioned'
assert 'AI-Team-Lane-Issue: none' in workflow
assert 'task:ready' in workflow
assert 'bot-maintenance' in workflow
assert 'git push origin HEAD:main' not in workflow
assert 'coverage-feature-a.xml' in workflow and 'coverage-feature-b.xml' in workflow
assert 'paths-ignore' in workflow and 'platform-coverage-baseline.json' in workflow

baseline = json.loads(baseline_path.read_text(encoding='utf-8'))
assert 'line_rate' in baseline and 'tolerance_pp' in baseline

high = [
    str(fixtures / 'high-a.xml'),
    str(fixtures / 'high-b.xml'),
]
low = [
    str(fixtures / 'low-a.xml'),
    str(fixtures / 'low-b.xml'),
]

with tempfile.TemporaryDirectory() as tmp:
    tmp_baseline = Path(tmp) / 'baseline.json'
    tmp_baseline.write_text(
        json.dumps({'line_rate': 90.0, 'tolerance_pp': 0.05, 'coveredstatements': 90, 'statements': 100})
        + '\n',
        encoding='utf-8',
    )

    # Fail-first: deleting covered statements drops below the floor.
    failed = subprocess.run(
        ['python3', str(script), 'check', *low, '--baseline', str(tmp_baseline)],
        capture_output=True,
        text=True,
    )
    assert failed.returncode != 0, failed.stdout + failed.stderr
    assert 'below the baseline floor' in failed.stderr

    passed = subprocess.run(
        ['python3', str(script), 'check', *high, '--baseline', str(tmp_baseline)],
        capture_output=True,
        text=True,
    )
    assert passed.returncode == 0, passed.stdout + passed.stderr

    # Update raises when higher, refuses to lower.
    subprocess.check_call(
        ['python3', str(script), 'update', *high, '--baseline', str(tmp_baseline)],
    )
    raised = json.loads(tmp_baseline.read_text(encoding='utf-8'))
    assert raised['line_rate'] == 90.0

    lower_probe = Path(tmp) / 'lower.json'
    lower_probe.write_text(
        json.dumps({'line_rate': 95.0, 'tolerance_pp': 0.05, 'coveredstatements': 95, 'statements': 100})
        + '\n',
        encoding='utf-8',
    )
    subprocess.check_call(
        ['python3', str(script), 'update', *high, '--baseline', str(lower_probe)],
    )
    unchanged = json.loads(lower_probe.read_text(encoding='utf-8'))
    assert unchanged['line_rate'] == 95.0, 'update must never lower the baseline'

    nested = subprocess.check_output(
        ['python3', str(script), 'measure', str(fixtures / 'nested-metrics.xml')],
        text=True,
    )
    assert 'covered=8 statements=10' in nested, nested

    # Missing baseline must fail closed — a silent default would pass every PR.
    missing = subprocess.run(
        [
            'python3',
            str(script),
            'check',
            *high,
            '--baseline',
            str(Path(tmp) / 'does-not-exist.json'),
        ],
        capture_output=True,
        text=True,
    )
    assert missing.returncode != 0, missing.stdout + missing.stderr
    assert 'missing coverage baseline' in missing.stderr
PY

# Pest timing ratchet (#711): regressions must exceed both the percentage and
# absolute tolerances. Fixtures are local JSON only; no hosted timings leak in.
python3 - <<'PY'
from pathlib import Path
import json
import subprocess
import tempfile

root = Path('.').resolve()
script = root / 'scripts/ci/pest-timing-ratchet.py'
workflow = (root / '.github/workflows/tests.yml').read_text(encoding='utf-8')

assert script.is_file(), 'missing pest-timing-ratchet.py'
assert 'pest-timing-ratchet.py timing' in workflow

with tempfile.TemporaryDirectory() as tmp:
    fixture = Path(tmp)
    baseline = fixture / 'baseline.json'
    timing = fixture / 'timing'
    timing.mkdir()
    baseline.write_text(
        json.dumps({'suites': {'Feature-a': {'wall_seconds': 100.0}}}) + '\n',
        encoding='utf-8',
    )

    def write_wall(seconds: float) -> None:
        (timing / 'Feature-a__Feature-a.json').write_text(
            json.dumps({'job': 'Feature-a', 'suite': 'Feature-a', 'wall_seconds': seconds}) + '\n',
            encoding='utf-8',
        )

    # Failing-first: 30% and 30 seconds slower must name both measurements.
    write_wall(130.0)
    failed = subprocess.run(
        ['python3', str(script), str(timing), '--baseline', str(baseline)],
        capture_output=True,
        text=True,
    )
    assert failed.returncode == 1, failed.stdout + failed.stderr
    assert 'Feature-a' in failed.stderr
    assert '100.000' in failed.stderr and '130.000' in failed.stderr

    # Both boundaries are strict: exactly 25% slower remains within tolerance.
    write_wall(125.0)
    passed = subprocess.run(
        ['python3', str(script), str(timing), '--baseline', str(baseline)],
        capture_output=True,
        text=True,
    )
    assert passed.returncode == 0, passed.stdout + passed.stderr

    # A large percentage on a short suite is still below the 20-second floor.
    baseline.write_text(
        json.dumps({'suites': {'Feature-a': {'wall_seconds': 10.0}}}) + '\n',
        encoding='utf-8',
    )
    write_wall(14.0)
    passed = subprocess.run(
        ['python3', str(script), str(timing), '--baseline', str(baseline)],
        capture_output=True,
        text=True,
    )
    assert passed.returncode == 0, passed.stdout + passed.stderr

    # A new or missing lane cannot silently escape comparison.
    (timing / 'Unit__Unit.json').write_text(
        json.dumps({'job': 'Unit', 'suite': 'Unit', 'wall_seconds': 1.0}) + '\n',
        encoding='utf-8',
    )
    mismatch = subprocess.run(
        ['python3', str(script), str(timing), '--baseline', str(baseline)],
        capture_output=True,
        text=True,
    )
    assert mismatch.returncode != 0, mismatch.stdout + mismatch.stderr
    assert 'suites absent from baseline: Unit' in mismatch.stderr

    refreshed = fixture / 'refreshed.json'
    subprocess.check_call(
        [
            'python3', str(script), str(timing), '--baseline', str(refreshed),
            '--write-baseline', '--source', 'fixture-run',
        ],
    )
    payload = json.loads(refreshed.read_text(encoding='utf-8'))
    assert payload['source'] == 'fixture-run'
    assert payload['suites']['Feature-a']['wall_seconds'] == 14.0
    assert payload['suites']['Unit']['wall_seconds'] == 1.0
PY


# refresh-livewire-action-baselines.py (#775): never raise a committed count.
python3 - <<'PY'
from pathlib import Path
import importlib.util
import json
import subprocess
import tempfile

root = Path('.').resolve()
script = root / 'scripts/ci/refresh-livewire-action-baselines.py'
workflow = (root / '.github/workflows/refresh-livewire-action-baselines.yml').read_text(
    encoding='utf-8'
)
assert script.is_file(), 'missing refresh-livewire-action-baselines.py'
assert 'refresh-livewire-action-baselines.py' in workflow
assert 'gh pr create' in workflow
assert '--label bot-maintenance' in workflow
assert 'Protect Main refuses direct pushes' in workflow
assert 'HEAD:main' not in workflow.replace('HEAD:refs/heads/$branch', '')

spec = importlib.util.spec_from_file_location('refresh_livewire', script)
mod = importlib.util.module_from_spec(spec)
assert spec.loader is not None
spec.loader.exec_module(mod)
source = script.read_text(encoding='utf-8')
assert 'never-raise guard' in source

with tempfile.TemporaryDirectory() as tmp:
    fixture = Path(tmp)
    baseline = fixture / 'baseline.json'
    measured = fixture / 'measured.json'
    baseline.write_text(
        json.dumps({'domain': None, 'module_owned_unreferenced': 10, 'actions': ['A::a']})
        + '\n',
        encoding='utf-8',
    )

    # Lower count rewrites the baseline.
    measured.write_text(
        json.dumps({'domain': None, 'module_owned_unreferenced': 7, 'actions': ['A::a']})
        + '\n',
        encoding='utf-8',
    )
    lower = subprocess.run(
        ['python3', str(script), '--baseline', str(baseline), '--measured', str(measured)],
        capture_output=True,
        text=True,
    )
    assert lower.returncode == 0, lower.stdout + lower.stderr
    assert json.loads(baseline.read_text(encoding='utf-8'))['module_owned_unreferenced'] == 7

    # Higher count leaves the file untouched and exits 0 with a message.
    before = baseline.read_text(encoding='utf-8')
    measured.write_text(
        json.dumps({'domain': None, 'module_owned_unreferenced': 99, 'actions': ['Z::z']})
        + '\n',
        encoding='utf-8',
    )
    higher = subprocess.run(
        ['python3', str(script), '--baseline', str(baseline), '--measured', str(measured)],
        capture_output=True,
        text=True,
    )
    assert higher.returncode == 0, higher.stdout + higher.stderr
    assert 'refusing to raise' in higher.stdout
    assert baseline.read_text(encoding='utf-8') == before
    assert json.loads(baseline.read_text(encoding='utf-8'))['module_owned_unreferenced'] == 7

    # Delete the never-raise guard and the higher count is written (check goes red).
    patched = source.replace(
        '        # never-raise guard: a higher measured count must not rewrite the file.\n'
        '        if measured_count > old:\n'
        '            print(\n'
        '                f"refusing to raise Livewire action-debt baseline {baseline}: "\n'
        '                f"{old} -> {measured_count}; leaving file untouched"\n'
        '            )\n'
        '            return "unchanged-raise"\n',
        '',
        1,
    )
    assert patched != source, 'never-raise guard block missing for red check'
    patched_path = fixture / 'patched.py'
    patched_path.write_text(patched, encoding='utf-8')
    raised = subprocess.run(
        ['python3', str(patched_path), '--baseline', str(baseline), '--measured', str(measured)],
        capture_output=True,
        text=True,
    )
    assert raised.returncode == 0, raised.stdout + raised.stderr
    assert json.loads(baseline.read_text(encoding='utf-8'))['module_owned_unreferenced'] == 99
PY


# bot-pr-policy.sh (#728): hermetic profile check + workflow contracts.
python3 - <<'PY'
from pathlib import Path
import subprocess
import tempfile

root = Path('.').resolve()
policy = root / 'scripts/ci/bot-pr-policy.sh'
assert policy.is_file(), 'missing scripts/ci/bot-pr-policy.sh'
assert policy.stat().st_mode & 0o111, 'bot-pr-policy.sh must be executable'

tests_yml = (root / '.github/workflows/tests.yml').read_text(encoding='utf-8')
timings_yml = (root / '.github/workflows/refresh-feature-shard-timings.yml').read_text(encoding='utf-8')
livewire_yml = (root / '.github/workflows/refresh-livewire-action-baselines.yml').read_text(
    encoding='utf-8'
)
review_yml = (root / '.github/workflows/ai-team-independent-review.yml').read_text(encoding='utf-8')
assert '--label bot-maintenance' in tests_yml, 'coverage raise must apply bot-maintenance'
assert '--add-label bot-maintenance' in tests_yml
assert '--label bot-maintenance' in timings_yml, 'timings refresh must apply bot-maintenance'
assert 'AI-Team-Lane-Issue: none' in timings_yml
assert '--label bot-maintenance' in livewire_yml, 'livewire refresh must apply bot-maintenance'
assert 'AI-Team-Lane-Issue: none' in livewire_yml
assert 'Recognize a bot-maintenance PR' in review_yml
assert 'Materialize bot-maintenance policy' in review_yml
assert 'scripts/ci/bot-pr-policy.sh' in review_yml
assert "steps.bot_policy.outputs.accepted != 'true'" in review_yml
assert "contains(github.event.pull_request.labels.*.name, 'bot-maintenance')" in review_yml
assert 'livewire_actions_profile' in policy.read_text(encoding='utf-8')

with tempfile.TemporaryDirectory() as tmp:
    repo = Path(tmp)
    subprocess.check_call(['git', 'init', '-q'], cwd=repo)
    subprocess.check_call(['git', 'config', 'user.name', 'test'], cwd=repo)
    subprocess.check_call(['git', 'config', 'user.email', 'test@example.invalid'], cwd=repo)
    (repo / 'tests/ci').mkdir(parents=True)
    (repo / 'tests/ci/livewire-actions-baselines').mkdir(parents=True)
    (repo / 'scripts/ci').mkdir(parents=True)
    (repo / 'tests/ci/platform-coverage-baseline.json').write_text('{}\n', encoding='utf-8')
    (repo / 'scripts/ci/platform-feature-shard-timings.json').write_text('{}\n', encoding='utf-8')
    (repo / 'scripts/ci/platform-feature-shards.json').write_text('{}\n', encoding='utf-8')
    (repo / 'tests/ci/livewire-actions-baselines/platform.json').write_text('{}\n', encoding='utf-8')
    (repo / 'README.md').write_text('other\n', encoding='utf-8')
    subprocess.check_call(['git', 'add', '-A'], cwd=repo)
    subprocess.check_call(['git', 'commit', '-qm', 'base'], cwd=repo)
    base = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=repo, text=True).strip()

    def commit_on(branch: str, mutator) -> str:
        subprocess.check_call(['git', 'checkout', '-q', '-B', branch, base], cwd=repo)
        mutator()
        subprocess.check_call(['git', 'add', '-A'], cwd=repo)
        subprocess.check_call(['git', 'commit', '-qm', branch], cwd=repo)
        return subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=repo, text=True).strip()

    def run_policy(head: str):
        return subprocess.run(
            ['bash', str(policy), base, head],
            cwd=repo,
            capture_output=True,
            text=True,
        )

    head = commit_on(
        'baseline-only',
        lambda: (repo / 'tests/ci/platform-coverage-baseline.json').write_text(
            '{"line_rate":1}\n', encoding='utf-8'
        ),
    )
    passed = run_policy(head)
    assert passed.returncode == 0, passed.stdout + passed.stderr

    def baseline_plus_extra():
        (repo / 'tests/ci/platform-coverage-baseline.json').write_text(
            '{"line_rate":1}\n', encoding='utf-8'
        )
        (repo / 'README.md').write_text('smuggle\n', encoding='utf-8')

    head = commit_on('baseline-plus-extra', baseline_plus_extra)
    failed = run_policy(head)
    assert failed.returncode == 1, failed.stdout + failed.stderr
    assert 'README.md' in failed.stderr, failed.stderr

    def timings_ok():
        (repo / 'scripts/ci/platform-unit-shard-timings.json').write_text(
            '{"directories":{}}\n', encoding='utf-8'
        )
        (repo / 'scripts/ci/platform-unit-shards.json').write_text(
            '{"shards":{}}\n', encoding='utf-8'
        )
        (repo / 'scripts/ci/platform-feature-shard-timings.json').write_text(
            '{"suites":{}}\n', encoding='utf-8'
        )
        (repo / 'scripts/ci/platform-feature-shards.json').write_text(
            '{"shards":[]}\n', encoding='utf-8'
        )

    head = commit_on('timings-ok', timings_ok)
    passed = run_policy(head)
    assert passed.returncode == 0, passed.stdout + passed.stderr

    def livewire_ok():
        (repo / 'tests/ci/livewire-actions-baselines/platform.json').write_text(
            '{"module_owned_unreferenced":1}\n', encoding='utf-8'
        )
        (repo / 'tests/ci/livewire-actions-baselines/people.json').write_text(
            '{"module_owned_unreferenced":2}\n', encoding='utf-8'
        )

    head = commit_on('livewire-ok', livewire_ok)
    passed = run_policy(head)
    assert passed.returncode == 0, passed.stdout + passed.stderr

    def mixed():
        (repo / 'tests/ci/platform-coverage-baseline.json').write_text(
            '{"line_rate":1}\n', encoding='utf-8'
        )
        (repo / 'scripts/ci/platform-feature-shard-timings.json').write_text(
            '{"suites":{}}\n', encoding='utf-8'
        )

    head = commit_on('mixed', mixed)
    failed = run_policy(head)
    assert failed.returncode == 1, failed.stdout + failed.stderr
PY

# changed-authorable-php.sh (#823): the subset rule that decides what Pint sees
# in lint.yml. An existing migration is hash-immutable, so re-linting it can
# only ever fail CI for a file nobody may edit; a NEWLY added migration is
# still authorable and must be linted. Both halves lived only in the script.
# Hermetic: a throwaway repository with two commits, so nothing here reads the
# platform checkout's own history or working tree.
authorable_fixture=$(mktemp -d)
trap 'rm -rf "$authorable_fixture"' EXIT
authorable_script="$root/scripts/ci/changed-authorable-php.sh"
(
    cd "$authorable_fixture"
    git init -q
    git config user.name test
    git config user.email test@example.invalid

    mkdir -p app/Base/X/Database/Migrations database/migrations \
        app/Base/X/Services app/Base/X/Livewire
    printf '<?php // a\n' > app/Base/X/Database/Migrations/2026_01_01_000000_a.php
    printf '<?php // b\n' > database/migrations/2026_01_01_000000_b.php
    printf '<?php // s\n' > app/Base/X/Services/S.php
    printf '<?php // old\n' > app/Base/X/Old.php
    git add -A
    git commit -qm base
    authorable_base=$(git rev-parse HEAD)

    # Touch both existing migrations, add one, add an ordinary class, delete a
    # class, and add a non-PHP file. Each is a different arm of the rule.
    #
    # Deletion exclusion is two guards: --diff-filter=ACMR (pinned by the
    # head-tree case below — ACMRD alone stays green because -f still drops
    # Old.php) and [[ -f "$path" ]] (pinned by the base-tree case that follows —
    # deleting -f alone turns that case red while the head-tree case stays
    # green). Do not drop either on the strength of one green run.
    printf '<?php // a changed\n' > app/Base/X/Database/Migrations/2026_01_01_000000_a.php
    printf '<?php // b changed\n' > database/migrations/2026_01_01_000000_b.php
    printf '<?php // s changed\n' > app/Base/X/Services/S.php
    printf '<?php // c\n' > app/Base/X/Database/Migrations/2026_02_01_000000_c.php
    printf '<?php // l\n' > app/Base/X/Livewire/L.php
    printf 'notes\n' > notes.txt
    rm app/Base/X/Old.php
    git add -A
    git commit -qm head
    authorable_head=$(git rev-parse HEAD)

    # Head-tree case: working tree matches head (lint.yml checkout). git orders
    # paths lexically, so the expected sequence is fixed. Compared whole rather
    # than grepped: a missing line and an extra line are both failures.
    authorable_expected=$(printf '%s\n' \
        'app/Base/X/Database/Migrations/2026_02_01_000000_c.php' \
        'app/Base/X/Livewire/L.php' \
        'app/Base/X/Services/S.php')
    authorable_actual=$(bash "$authorable_script" "$authorable_base" "$authorable_head" | tr '\0' '\n')

    if [[ "$authorable_actual" != "$authorable_expected" ]]; then
        echo 'changed-authorable-php.sh printed the wrong set of files' >&2
        echo "expected:" >&2
        printf '%s\n' "$authorable_expected" >&2
        echo "actual:" >&2
        printf '%s\n' "$authorable_actual" >&2
        exit 1
    fi

    # The output is NUL-separated, which is what lint.yml feeds to xargs -0:
    # three records means three trailing NULs and no newline of its own.
    authorable_nuls=$(bash "$authorable_script" "$authorable_base" "$authorable_head" | tr -dc '\0' | wc -c)
    if [[ "$authorable_nuls" -ne 3 ]]; then
        echo "changed-authorable-php.sh emitted $authorable_nuls NUL separators, expected 3" >&2
        exit 1
    fi

    # Base-tree case (#857): checkout at base so paths the diff lists but the
    # tree lacks (the added migration and Livewire class) are absent on disk.
    # [[ -f "$path" ]] must drop them; without it the script prints the full
    # head set against a base tree and Pint would lint ghosts. lint.yml relies
    # on checkout-at-head; this pins the -f guard alone.
    git checkout -q "$authorable_base"
    authorable_base_expected=$(printf '%s\n' 'app/Base/X/Services/S.php')
    authorable_base_actual=$(bash "$authorable_script" "$authorable_base" "$authorable_head" 2>"$authorable_fixture/base.err" | tr '\0' '\n')
    if [[ -s "$authorable_fixture/base.err" ]]; then
        echo 'changed-authorable-php.sh wrote stderr on a base-tree checkout' >&2
        cat "$authorable_fixture/base.err" >&2
        exit 1
    fi
    if [[ "$authorable_base_actual" != "$authorable_base_expected" ]]; then
        echo 'changed-authorable-php.sh did not drop missing paths on a base-tree checkout' >&2
        echo "expected:" >&2
        printf '%s\n' "$authorable_base_expected" >&2
        echo "actual:" >&2
        printf '%s\n' "$authorable_base_actual" >&2
        exit 1
    fi
    git checkout -q "$authorable_head"

    # No base ref: the ${1:?} guard refuses rather than diffing against nothing.
    if bash "$authorable_script" >/dev/null 2>"$authorable_fixture/usage.txt"; then
        echo 'changed-authorable-php.sh accepted a missing base ref' >&2
        exit 1
    fi
    if ! grep -qF 'usage: changed-authorable-php.sh <base> [head]' "$authorable_fixture/usage.txt"; then
        echo 'changed-authorable-php.sh did not print its usage line' >&2
        cat "$authorable_fixture/usage.txt" >&2
        exit 1
    fi

    # An unresolvable base ref must fail, not silently lint nothing: an empty
    # file list is exactly what a green "Pint on changed files" step looks like.
    if bash "$authorable_script" nosuchref >/dev/null 2>&1; then
        echo 'changed-authorable-php.sh accepted an unknown base ref' >&2
        exit 1
    fi
)
rm -rf "$authorable_fixture"
trap - EXIT

# token-audit.sh (#780 / #825): every statically resolvable secrets.* reference
# must be allowlisted in docs/ci/secrets.json; stale rotation warns; malformed
# entries fail; computed keys and secrets: inherit warn (or --strict refuse).
python3 - <<'PY'
from pathlib import Path
import json
import subprocess
import tempfile

root = Path('.').resolve()
audit = root / 'scripts/ci/token-audit.sh'
assert audit.is_file(), 'missing scripts/ci/token-audit.sh'
assert audit.stat().st_mode & 0o111, 'token-audit.sh must be executable'
lint_yml = (root / '.github/workflows/lint.yml').read_text(encoding='utf-8')
assert 'run: scripts/ci/token-audit.sh' in lint_yml, 'quality job must run token-audit.sh'

live = subprocess.run(['bash', str(audit)], cwd=root, capture_output=True, text=True)
assert live.returncode == 0, live.stdout + live.stderr
assert 'every referenced secret is allowlisted' in live.stdout, live.stdout
assert 'not statically verifiable' not in live.stdout, live.stdout
assert '::warning file=' not in live.stdout, live.stdout
assert '::warning::' not in live.stdout, live.stdout


def run_audit(tmp, workflow, allowlist, today='2026-09-07', strict=False):
    (tmp / 'wf').mkdir(exist_ok=True)
    (tmp / 'wf/ci.yml').write_text(workflow, encoding='utf-8')
    (tmp / 'secrets.json').write_text(json.dumps(allowlist), encoding='utf-8')
    cmd = ['bash', str(audit), '--workflows', str(tmp / 'wf'),
           '--allowlist', str(tmp / 'secrets.json'), '--today', today]
    if strict:
        cmd.append('--strict')
    return subprocess.run(cmd, capture_output=True, text=True)


def entry(name, **overrides):
    payload = {'name': name, 'purpose': 'fixture', 'owner': 'kiatng', 'rotated': '2026-09-01'}
    payload.update(overrides)
    return payload


workflow = (
    'jobs:\n  a:\n    steps:\n      - env:\n'
    '          A: ${{ secrets.LISTED_TOKEN }}\n'
    '          B: ${{ secrets.GITHUB_TOKEN }}\n'
    '          C: ${{ steps.x.outputs.token || secrets.FIXTURE_UNLISTED_TOKEN }}\n'
)
with tempfile.TemporaryDirectory() as tmpdir:
    tmp = Path(tmpdir)
    unlisted = run_audit(tmp, workflow, {'secrets': [entry('LISTED_TOKEN')]})
    assert unlisted.returncode == 1, unlisted.stdout + unlisted.stderr
    assert 'FIXTURE_UNLISTED_TOKEN' in unlisted.stderr, unlisted.stderr
    assert 'secret LISTED_TOKEN is referenced' not in unlisted.stderr, unlisted.stderr
    assert 'GITHUB_TOKEN' not in unlisted.stderr, unlisted.stderr

    listed = run_audit(tmp, workflow, {'secrets': [entry('LISTED_TOKEN'), entry('FIXTURE_UNLISTED_TOKEN')]})
    assert listed.returncode == 0, listed.stdout + listed.stderr
    assert '::warning::' not in listed.stdout, listed.stdout

    # A bracketed reference is the same reference: GitHub accepts
    # ${{ secrets['NAME'] }} exactly as it accepts ${{ secrets.NAME }}.
    bracketed = run_audit(tmp, (
        'jobs:\n  a:\n    steps:\n      - env:\n'
        "          A: ${{ secrets['FIXTURE_UNLISTED_TOKEN'] }}\n"
    ), {'secrets': [entry('LISTED_TOKEN')]})
    assert bracketed.returncode == 1, bracketed.stdout + bracketed.stderr
    assert 'FIXTURE_UNLISTED_TOKEN' in bracketed.stderr, bracketed.stderr

    stale = run_audit(tmp, workflow, {'secrets': [
        entry('LISTED_TOKEN', rotated='2026-06-01'), entry('FIXTURE_UNLISTED_TOKEN')]})
    assert stale.returncode == 0, stale.stdout + stale.stderr
    assert '::warning::secret LISTED_TOKEN was last rotated 2026-06-01 (98 days ago' in stale.stdout, stale.stdout

    fresh = run_audit(tmp, workflow, {'secrets': [
        entry('LISTED_TOKEN', rotated='2026-06-09'), entry('FIXTURE_UNLISTED_TOKEN')]})
    assert fresh.returncode == 0 and '::warning::' not in fresh.stdout, fresh.stdout

    no_owner = run_audit(tmp, workflow, {'secrets': [
        entry('LISTED_TOKEN', owner=''), entry('FIXTURE_UNLISTED_TOKEN')]})
    assert no_owner.returncode == 1, no_owner.stdout + no_owner.stderr
    assert 'entry LISTED_TOKEN: missing owner' in no_owner.stderr, no_owner.stderr

    no_date = run_audit(tmp, workflow, {'secrets': [
        entry('LISTED_TOKEN'), {'name': 'FIXTURE_UNLISTED_TOKEN', 'purpose': 'p', 'owner': 'kiatng'}]})
    assert no_date.returncode == 1, no_date.stdout + no_date.stderr
    assert 'entry FIXTURE_UNLISTED_TOKEN: missing rotated' in no_date.stderr, no_date.stderr

    bad_date = run_audit(tmp, workflow, {'secrets': [
        entry('LISTED_TOKEN', rotated='soon'), entry('FIXTURE_UNLISTED_TOKEN')]})
    assert bad_date.returncode == 1 and 'not an ISO date' in bad_date.stderr, bad_date.stderr

    (tmp / 'secrets.json').unlink()
    missing = subprocess.run(
        ['bash', str(audit), '--workflows', str(tmp / 'wf'), '--allowlist', str(tmp / 'secrets.json')],
        capture_output=True, text=True)
    assert missing.returncode == 1 and 'is missing' in missing.stderr, missing.stderr

    # #825: computed keys and secrets: inherit are warned, not silently green.
    computed_wf = (
        'jobs:\n  a:\n    steps:\n      - env:\n'
        "          A: ${{ secrets[format('TOKEN_{0}', github.ref_name)] }}\n"
        '          B: ${{ secrets.LISTED_TOKEN }}\n'
    )
    computed = run_audit(tmp, computed_wf, {'secrets': [entry('LISTED_TOKEN')]})
    assert computed.returncode == 0, computed.stdout + computed.stderr
    assert '::warning file=' in computed.stdout and 'computed-key' in computed.stdout, computed.stdout
    assert '1 reference(s) not statically verifiable' in computed.stdout, computed.stdout
    assert 'every statically resolvable referenced secret is allowlisted' in computed.stdout, computed.stdout
    assert 'every referenced secret is allowlisted\n' not in computed.stdout + '\n', computed.stdout
    computed_strict = run_audit(tmp, computed_wf, {'secrets': [entry('LISTED_TOKEN')]}, strict=True)
    assert computed_strict.returncode == 1, computed_strict.stdout + computed_strict.stderr
    assert '--strict refuses' in computed_strict.stderr, computed_strict.stderr

    inherit_wf = (
        'jobs:\n  a:\n    secrets: inherit\n'
        '    uses: ./.github/workflows/extension-conformance.yml\n'
        '  b:\n    steps:\n      - env:\n'
        '          A: ${{ secrets.LISTED_TOKEN }}\n'
    )
    inherit = run_audit(tmp, inherit_wf, {'secrets': [entry('LISTED_TOKEN')]})
    assert inherit.returncode == 0, inherit.stdout + inherit.stderr
    assert 'secrets: inherit' in inherit.stdout, inherit.stdout
    assert '1 reference(s) not statically verifiable' in inherit.stdout, inherit.stdout
    assert 'every statically resolvable referenced secret is allowlisted' in inherit.stdout, inherit.stdout
    inherit_strict = run_audit(tmp, inherit_wf, {'secrets': [entry('LISTED_TOKEN')]}, strict=True)
    assert inherit_strict.returncode == 1, inherit_strict.stdout + inherit_strict.stderr

    plain = run_audit(tmp, (
        'jobs:\n  a:\n    steps:\n      - env:\n'
        '          A: ${{ secrets.SONAR_TOKEN }}\n'
    ), {'secrets': [entry('SONAR_TOKEN')]})
    assert plain.returncode == 0, plain.stdout + plain.stderr
    assert '::warning file=' not in plain.stdout, plain.stdout
    assert 'not statically verifiable' not in plain.stdout, plain.stdout
    assert 'every referenced secret is allowlisted' in plain.stdout, plain.stdout
PY


# compose-domain.php (#843): cross-domain clone TSV, registry refusals, unknown args.
compose_fixture=$(mktemp -d)
trap 'rm -rf "$compose_fixture"' EXIT
compose_src="$root/tests/ci/fixtures/compose-domain"
cp -a "$compose_src/registry-root/." "$compose_fixture/tree/"
mkdir -p "$compose_fixture/present/app/Domains"
# Pre-existing beta mount so the already-present path is omitted from stdout.
cp -a "$compose_fixture/tree/app/Domains/Beta" "$compose_fixture/present/app/Domains/Beta"
python3 - "$compose_fixture" <<'COMPOSE_REG'
import json, pathlib, sys
root = pathlib.Path(sys.argv[1])
tree = root / "tree"
absent_beta = root / "absent" / "app" / "Domains" / "Beta"
reg = {
    "domains": {
        "solo": {"repo": "Example/solo", "path": str(tree / "app/Domains/Solo")},
        "needs-beta": {"repo": "Example/needs-beta", "path": str(tree / "app/Domains/NeedsBeta")},
        "needs-gamma": {"repo": "Example/needs-gamma", "path": str(tree / "app/Domains/NeedsGamma")},
        # Absent on disk so NeedsBeta must emit a clone line.
        "beta": {"repo": "Example/beta", "path": str(absent_beta)},
    }
}
(root / "registry.json").write_text(json.dumps(reg))
# Registry whose beta path already exists on disk (present mount).
reg_present = dict(reg)
reg_present["domains"] = dict(reg["domains"])
reg_present["domains"]["beta"] = {
    "repo": "Example/beta",
    "path": str(root / "present/app/Domains/Beta"),
}
(root / "registry-present.json").write_text(json.dumps(reg_present))
COMPOSE_REG

compose_run() {
    local domain="$1" registry="$2"
    shift 2 || true
    php "$root/scripts/ci/compose-domain.php" \
        --domain-path="$compose_fixture/tree/app/Domains/$domain" \
        --registry="$registry" \
        "$@"
}

# Sibling + core only: nothing to clone.
set +e
solo_out=$(compose_run Solo "$compose_fixture/registry.json" 2>"$compose_fixture/solo.err")
solo_ec=$?
set -e
if [[ "$solo_ec" -ne 0 || -n "$solo_out" ]]; then
    echo "compose-domain Solo expected empty stdout exit 0, got ec=$solo_ec out=$(printf %q "$solo_out")" >&2
    exit 1
fi
if ! grep -qF 'no cross-domain dependencies to clone' "$compose_fixture/solo.err"; then
    echo 'compose-domain Solo missing no-cross-domain stderr' >&2
    cat "$compose_fixture/solo.err" >&2
    exit 1
fi

# Cross-domain requirement prints one TSV line when the registry path is absent.
set +e
need_out=$(compose_run NeedsBeta "$compose_fixture/registry.json" 2>"$compose_fixture/need.err")
need_ec=$?
set -e
if [[ "$need_ec" -ne 0 ]]; then
    echo "compose-domain NeedsBeta expected exit 0, got $need_ec" >&2
    cat "$compose_fixture/need.err" >&2
    exit 1
fi
expected_tsv=$'Example/beta\t'"$compose_fixture/absent/app/Domains/Beta"
if [[ "$need_out" != "$expected_tsv" ]]; then
    echo "compose-domain NeedsBeta TSV mismatch: $(printf %q "$need_out") expected $(printf %q "$expected_tsv")" >&2
    exit 1
fi

# Already-present registry path is omitted.
set +e
present_out=$(compose_run NeedsBeta "$compose_fixture/registry-present.json" 2>"$compose_fixture/present.err")
present_ec=$?
set -e
if [[ "$present_ec" -ne 0 || -n "$present_out" ]]; then
    echo "compose-domain present beta expected empty stdout exit 0, got ec=$present_ec out=$(printf %q "$present_out")" >&2
    exit 1
fi

# Required module with no registry entry exits 1 naming it.
set +e
miss_out=$(compose_run NeedsGamma "$compose_fixture/registry.json" 2>"$compose_fixture/miss.err")
miss_ec=$?
set -e
if [[ "$miss_ec" -ne 1 ]]; then
    echo "compose-domain NeedsGamma expected exit 1, got $miss_ec" >&2
    exit 1
fi
if ! grep -qF 'gamma/missing' "$compose_fixture/miss.err"; then
    echo 'compose-domain NeedsGamma stderr did not name gamma/missing' >&2
    cat "$compose_fixture/miss.err" >&2
    exit 1
fi

# Unreadable registry exits 2.
set +e
php "$root/scripts/ci/compose-domain.php" \
    --domain-path="$compose_fixture/tree/app/Domains/Solo" \
    --registry="$compose_fixture/no-such-registry.json" \
    >/dev/null 2>"$compose_fixture/noread.err"
noread_ec=$?
set -e
if [[ "$noread_ec" -ne 2 ]] || ! grep -qF 'cannot read registry' "$compose_fixture/noread.err"; then
    echo 'compose-domain unreadable registry refusal failed' >&2
    cat "$compose_fixture/noread.err" >&2
    exit 1
fi

# Non-JSON registry exits 2.
printf 'not-json\n' > "$compose_fixture/bad.json"
set +e
php "$root/scripts/ci/compose-domain.php" \
    --domain-path="$compose_fixture/tree/app/Domains/Solo" \
    --registry="$compose_fixture/bad.json" \
    >/dev/null 2>"$compose_fixture/bad.err"
bad_ec=$?
set -e
if [[ "$bad_ec" -ne 2 ]] || ! grep -qF 'not valid JSON' "$compose_fixture/bad.err"; then
    echo 'compose-domain invalid JSON refusal failed' >&2
    cat "$compose_fixture/bad.err" >&2
    exit 1
fi

# Unknown argument exits 2 naming it.
set +e
php "$root/scripts/ci/compose-domain.php" \
    --domain-path="$compose_fixture/tree/app/Domains/Solo" \
    --registry="$compose_fixture/registry.json" \
    --bogus \
    >/dev/null 2>"$compose_fixture/bogus.err"
bogus_ec=$?
set -e
if [[ "$bogus_ec" -ne 2 ]] || ! grep -qF 'unknown argument --bogus' "$compose_fixture/bogus.err"; then
    echo 'compose-domain unknown argument refusal failed' >&2
    cat "$compose_fixture/bogus.err" >&2
    exit 1
fi

# Workflow argument list (domain-path only) exits 0 against the fixture.
set +e
php "$root/scripts/ci/compose-domain.php" \
    --domain-path="$compose_fixture/tree/app/Domains/Solo" \
    --registry="$compose_fixture/registry.json" \
    >/dev/null 2>"$compose_fixture/wf.err"
wf_ec=$?
set -e
if [[ "$wf_ec" -ne 0 ]]; then
    echo 'compose-domain workflow args failed against Solo fixture' >&2
    cat "$compose_fixture/wf.err" >&2
    exit 1
fi

rm -rf "$compose_fixture"
trap - EXIT

# pinned-mount-check.php (#927): a lane that calls sibling Domain API absent at
# the pinned revision must be refused locally, naming both revisions. The proof
# is the same case run against two pins — red at the old one, green at the new
# one — not an assertion that the script exists.
pin_fixture=$(mktemp -d)
trap 'rm -rf "$pin_fixture"' EXIT
mkdir -p "$pin_fixture/app/Domains/People/Training/Services" \
    "$pin_fixture/app/Domains/PeopleConnector/Connector"

pin_store() {
    cat > "$pin_fixture/app/Domains/People/Training/Services/TrainingParticipationStore.php"
}
pin_git() { git -C "$pin_fixture/app/Domains/People" "$@"; }

pin_git init -q .
pin_git config user.email ci@example.com
pin_git config user.name ci
pin_store <<'STORE'
<?php
namespace App\Domains\People\Training\Services;
final class TrainingParticipationStore
{
    public function enrol(int $id): void {}
}
STORE
pin_git add -A
pin_git commit -qm 'pinned revision'
pinned_sha=$(pin_git rev-parse HEAD)
pin_store <<'STORE'
<?php
namespace App\Domains\People\Training\Services;
final class TrainingParticipationStore
{
    public function enrol(int $id): void {}

    public function enrolFromRequest(int $id): void {}
}
STORE
pin_git add -A
pin_git commit -qm 'method added after the pin'
mounted_sha=$(pin_git rev-parse HEAD)

# The connector lane that #927 describes: green locally, red on all three jobs.
cat > "$pin_fixture/app/Domains/PeopleConnector/Connector/EnrolmentTest.php" <<'LANE'
<?php
use App\Domains\People\Training\Services\TrainingParticipationStore;

it('enrols through the People API', function (): void {
    app(TrainingParticipationStore::class)->enrolFromRequest(1);
});
LANE

# A second lane file that calls only what the pin already has, plus an unrelated
# method name: neither may fire, or the check is noise an author learns to skip.
cat > "$pin_fixture/app/Domains/PeopleConnector/Connector/SettledTest.php" <<'LANE'
<?php
use App\Domains\People\Training\Services\TrainingParticipationStore;

it('enrols through API the pin already has', function (): void {
    app(TrainingParticipationStore::class)->enrol(1);
    $unrelated->enrolFromRequestSomewhereElse(2);
});
LANE

pin_registry() {
    local people_sha="$1"
    python3 - "$pin_fixture/registry.json" "$people_sha" <<'PIN_REG'
import json, sys
json.dump({
    "schema_version": 1,
    "domains": {
        "people": {
            "repo": "BelimbingApp/blb-people",
            "path": "app/Domains/People",
            "sonar_project_key": "BelimbingApp_blb-people",
            "ref": sys.argv[2],
        },
        "people-connector": {
            "repo": "BelimbingApp/blb-people-connector",
            "path": "app/Domains/PeopleConnector",
            "sonar_project_key": "BelimbingApp_blb-people-connector",
            "ref": "d91e2ccbcc0e40dcbb750c28aeebbe9c05729a33",
        },
    },
}, open(sys.argv[1], "w"))
PIN_REG
}

pin_check() {
    php "$root/scripts/ci/pinned-mount-check.php" \
        --root="$pin_fixture" --registry="$pin_fixture/registry.json" "$@"
}

pin_registry "$pinned_sha"
set +e
pin_err=$(pin_check app/Domains/PeopleConnector/Connector/EnrolmentTest.php 2>&1)
pin_ec=$?
set -e
if [[ "$pin_ec" -ne 1 ]]; then
    echo "pinned-mount-check accepted a lane calling People API absent at the pin (exit $pin_ec)" >&2
    echo "$pin_err" >&2
    exit 1
fi
grep -q 'enrolFromRequest() does not exist' <<< "$pin_err"
# The message has to name both revisions or the author cannot act on it.
grep -q "${pinned_sha:0:12}" <<< "$pin_err"
grep -q "${mounted_sha:0:12}" <<< "$pin_err"

# Same file, pin advanced to the mounted revision: the refusal must lift.
pin_registry "$mounted_sha"
if ! pin_check app/Domains/PeopleConnector/Connector/EnrolmentTest.php >/dev/null 2>&1; then
    echo 'pinned-mount-check refused a lane whose pin matches the mount' >&2
    pin_check app/Domains/PeopleConnector/Connector/EnrolmentTest.php || true
    exit 1
fi

# Back to the old pin: a lane using only pinned API stays green, so the check
# refuses drift rather than every lane that names a sibling class.
pin_registry "$pinned_sha"
if ! pin_check app/Domains/PeopleConnector/Connector/SettledTest.php >/dev/null 2>&1; then
    echo 'pinned-mount-check refused a lane that only uses API the pin has' >&2
    pin_check app/Domains/PeopleConnector/Connector/SettledTest.php || true
    exit 1
fi

# A sibling class that does not exist at the pin at all is the same hazard.
mkdir -p "$pin_fixture/app/Domains/PeopleConnector/Connector"
cat > "$pin_fixture/app/Domains/PeopleConnector/Connector/NewClassTest.php" <<'LANE'
<?php
use App\Domains\People\Training\Services\TrainingLedger;

it('reads a class the pin never had', function (): void {
    app(TrainingLedger::class)->read(1);
});
LANE
set +e
pin_new_err=$(pin_check app/Domains/PeopleConnector/Connector/NewClassTest.php 2>&1)
pin_new_ec=$?
set -e
if [[ "$pin_new_ec" -ne 1 ]]; then
    echo 'pinned-mount-check accepted a lane naming a class absent at the pin' >&2
    echo "$pin_new_err" >&2
    exit 1
fi
grep -q 'TrainingLedger does not exist' <<< "$pin_new_err"

# The domain under review is composed at its PR head, not at its pin, so a
# lane's references to its OWN classes are not drift. Without this the real
# connector tree reports every class it added since its own pin.
cat > "$pin_fixture/app/Domains/People/Training/OwnClassTest.php" <<'LANE'
<?php
use App\Domains\People\Training\Services\TrainingParticipationStore;

it('calls its own new API', function (): void {
    app(TrainingParticipationStore::class)->enrolFromRequest(1);
});
LANE
if ! pin_check app/Domains/People/Training/OwnClassTest.php >/dev/null 2>&1; then
    echo 'pinned-mount-check refused a People lane for using People API added since the People pin' >&2
    pin_check app/Domains/People/Training/OwnClassTest.php || true
    exit 1
fi

# Missing arguments are a usage error (exit 2), distinct from a finding (exit 1).
set +e
pin_check >/dev/null 2>&1
pin_usage_ec=$?
set -e
if [[ "$pin_usage_ec" -ne 2 ]]; then
    echo "pinned-mount-check did not report a usage error for missing arguments (exit $pin_usage_ec)" >&2
    exit 1
fi

rm -rf "$pin_fixture"
trap - EXIT

echo 'CI script checks passed'
