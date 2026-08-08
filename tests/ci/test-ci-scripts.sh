#!/usr/bin/env bash
set -euo pipefail

root=$(git rev-parse --show-toplevel)
cd "$root"
bash -n scripts/ci/changed-authorable-php.sh scripts/ci/extension-conformance.sh
python3 -m json.tool scripts/ci/domain-repos.json >/dev/null

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
