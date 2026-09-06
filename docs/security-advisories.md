# Security advisories

Accepted/ignored dependency advisories and the reasoning behind them. The CI
`security` workflow runs the dependency audit policy
([docs/ci/dependency-audit-policy.json](ci/dependency-audit-policy.json)) via
[`scripts/ci/dependency-audit.py`](../scripts/ci/dependency-audit.py), then a
gitleaks secret scan, on every push/PR and weekly. Anything not allowlisted
there at or above `min_severity` must be fixed rather than ignored.

The JS ecosystem audit uses **Bun** (`bun audit`) because this repository ships
a Bun lockfile, not npm's.

## Policy file

```json
{
  "min_severity": "low",
  "allowlist": [
    {
      "id": "CVE-… or PKSA-…",
      "ecosystem": "composer | bun",
      "reason": "Why Belimbing accepts this risk, and the remediation plan.",
      "expires": "YYYY-MM-DD"
    }
  ]
}
```

- `min_severity` — fail on findings at this severity or higher (`low`,
  `moderate`/`medium`, `high`, `critical`). Default `low` keeps today's
  fail-on-any behaviour.
- `allowlist` — reviewed exceptions. An entry whose `expires` date is before
  today's UTC date fails the job until removed or renewed.

## Composer

There are no accepted Composer advisories at this time.

`PKSA-5jz8-6tcw-pbk4` / CVE-2026-41570 was previously ignored, but the ignore
is no longer needed: the affected PHPUnit 12 line is `12.5.21`, the first
patched 12.x release is `12.5.22`, and this repository is locked to
`phpunit/phpunit` 12.5.30.

## Bun

There are no accepted Bun/JS advisories at this time.
