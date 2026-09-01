#!/usr/bin/env bash
set -euo pipefail

root=$(git rev-parse --show-toplevel)
cd "$root"
bash -n scripts/ci/changed-authorable-php.sh scripts/ci/extension-conformance.sh scripts/ci/mount-guard.sh
python3 -m json.tool scripts/ci/domain-repos.json >/dev/null

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
    php scripts/ci/validate-php-syntax.php scripts/ci/domain-ci.php scripts/ci/compose-domain.php scripts/ci/validate-extension-manifest.php
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
